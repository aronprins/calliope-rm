<?php
/**
 * Lightweight status lookup for issue numbers a customer already has
 * (e.g. from their localStorage submissions list).
 *
 * Returns nothing more than `{ number, state, reason }` per issue —
 * never the body, never the labels, never PII. This lets the
 * "Your submissions" tracker distinguish between:
 *   - open and not yet on the public roadmap → "Awaiting triage"
 *   - closed as completed                    → "Closed"
 *   - closed as not_planned / duplicate      → "Declined"
 *
 * Required env vars: same as board.php (GITHUB_*, ALLOWED_ORIGIN).
 *
 * Optional:
 *   STATUS_CACHE_TTL  cache lifetime in seconds (default 60)
 *   STATUS_CACHE_DIR  cache directory (default sys_get_temp_dir())
 *
 * Request:
 *   GET /api/status?numbers=N.TOKEN,N.TOKEN,...   (max 20 per call)
 *
 * Each `numbers` entry is `<issue-number>.<HMAC token>`. The token is
 * issued by submit.php at submission time and stored client-side in
 * localStorage. Without a valid token an entry is silently dropped,
 * so anonymous callers can't enumerate issue state by trying numbers.
 */

declare(strict_types=1);

const STATUS_MAX_NUMBERS = 20;

// ─── CORS + method gate ──────────────────────────────────────────────
$origin = getenv('ALLOWED_ORIGIN') ?: '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     { json_out(['error' => 'Method not allowed'], 405); }

// ─── Parse + validate numbers (with HMAC tokens) ─────────────────────
$signKey = getenv('STATUS_SIGN_KEY');
if (!$signKey) {
    error_log('status.php: STATUS_SIGN_KEY not configured');
    json_out(['error' => 'Server misconfigured'], 500);
}

$owner = getenv('GITHUB_OWNER');
$repo  = getenv('GITHUB_REPO');
$token = getenv('GITHUB_TOKEN');
if (!$owner || !$repo || !$token) {
    error_log('status.php: GitHub config missing');
    json_out(['error' => 'Server misconfigured'], 500);
}

$raw   = (string)($_GET['numbers'] ?? '');
$parts = array_filter(array_map('trim', explode(',', $raw)));
if (count($parts) > STATUS_MAX_NUMBERS) {
    json_out(['error' => 'Too many issue numbers in one request.'], 400);
}

$numbers = [];
foreach ($parts as $p) {
    if (!preg_match('/^(\d+)\.([a-f0-9]{16})$/', $p, $m)) continue;
    $n        = (int)$m[1];
    $given    = $m[2];
    // HMAC message must be `<owner>/<repo>#<n>` so a key shared across
    // deployments can't validate cross-repo tokens.
    $expected = substr(hash_hmac('sha256', "$owner/$repo#$n", $signKey), 0, 16);
    if (hash_equals($expected, $given)) $numbers[] = $n;
}
$numbers = array_values(array_unique($numbers));

if (count($numbers) === 0) {
    header('Content-Type: application/json');
    echo json_encode(['items' => [], 'fetchedAt' => gmdate('c')]);
    exit;
}

// ─── Cache fast-path ─────────────────────────────────────────────────
sort($numbers);
$ttl       = (int)(getenv('STATUS_CACHE_TTL') ?: 60);
$cacheDir  = getenv('STATUS_CACHE_DIR') ?: sys_get_temp_dir();
$cacheFile = $cacheDir . '/calliope-status-'
           . sha1("$owner/$repo:" . implode(',', $numbers)) . '.json';

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    header('Content-Type: application/json');
    readfile($cacheFile);
    exit;
}

// ─── Fetch each issue's state ────────────────────────────────────────
// Prefer curl_multi for parallelism, but fall back to sequential
// curl_exec when the host disables curl_multi_exec (common in shared
// PHP-FPM hardening profiles). Sequential is bounded — at most
// STATUS_MAX_NUMBERS requests per call, and the response is cached.
$useMulti = function_exists('curl_multi_exec') && function_exists('curl_multi_init');
$results  = []; // number => ['code' => int, 'body' => string|false]

if ($useMulti) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($numbers as $n) {
        $ch = github_issue_handle($owner, $repo, $n, $token);
        curl_multi_add_handle($mh, $ch);
        $handles[$n] = $ch;
    }
    do {
        $execStatus = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running > 0 && $execStatus === CURLM_OK);
    if ($execStatus !== CURLM_OK) {
        foreach ($handles as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
        curl_multi_close($mh);
        error_log("status.php: curl_multi_exec returned $execStatus");
        json_out(['error' => 'Could not load status.'], 502);
    }
    foreach ($handles as $n => $ch) {
        $results[$n] = [
            'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => curl_multi_getcontent($ch),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
} else {
    foreach ($numbers as $n) {
        $ch = github_issue_handle($owner, $repo, $n, $token);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $results[$n] = ['code' => $code, 'body' => $body];
    }
}

$items = [];
$hadTransientError = false;
foreach ($results as $n => $r) {
    $code = $r['code'];
    $body = $r['body'];

    if ($code === 404) {
        // Genuine "doesn't exist" — record as unknown and cache. Retries
        // won't help because the issue is really gone.
        $items[] = ['number' => $n, 'state' => 'unknown', 'reason' => null];
        continue;
    }
    if ($code < 200 || $code >= 300) {
        // Transient: rate limit, expired token, GitHub 5xx. Don't fold
        // this into the response as "unknown" — that would freeze the
        // misleading state into the cache for STATUS_CACHE_TTL. Mark
        // the whole response as a failure and bail after the loop.
        error_log("status.php: GitHub returned $code for issue $n");
        $hadTransientError = true;
        continue;
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        $hadTransientError = true;
        continue;
    }
    // Skip pull requests — they share the issue number space but aren't
    // customer submissions and we don't want to leak PR existence.
    if (!empty($data['pull_request'])) {
        $items[] = ['number' => $n, 'state' => 'unknown', 'reason' => null];
        continue;
    }
    $items[] = [
        'number' => $n,
        'state'  => (string)($data['state'] ?? 'unknown'),
        'reason' => $data['state_reason'] ?? null,
    ];
}

if ($hadTransientError) {
    json_out(['error' => 'Could not load status.'], 502);
}

$result  = ['items' => $items, 'fetchedAt' => gmdate('c')];
$payload = json_encode($result);

@file_put_contents($cacheFile, $payload, LOCK_EX);

header('Content-Type: application/json');
echo $payload;

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function github_issue_handle(string $owner, string $repo, int $n, string $token) {
    $ch = curl_init("https://api.github.com/repos/$owner/$repo/issues/$n");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Accept: application/vnd.github+json',
            'User-Agent: customer-portal',
        ],
    ]);
    return $ch;
}
