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
 *   GET /api/status?numbers=18,42,77   (max 20 numbers per call)
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

// ─── Parse + validate numbers ────────────────────────────────────────
$raw   = (string)($_GET['numbers'] ?? '');
$parts = array_filter(array_map('trim', explode(',', $raw)));
$numbers = [];
foreach ($parts as $p) {
    $n = filter_var($p, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($n) $numbers[] = $n;
}
$numbers = array_values(array_unique($numbers));

if (count($numbers) === 0) {
    header('Content-Type: application/json');
    echo json_encode(['items' => [], 'fetchedAt' => gmdate('c')]);
    exit;
}
if (count($numbers) > STATUS_MAX_NUMBERS) {
    json_out(['error' => 'Too many issue numbers in one request.'], 400);
}

$owner = getenv('GITHUB_OWNER');
$repo  = getenv('GITHUB_REPO');
$token = getenv('GITHUB_TOKEN');
if (!$owner || !$repo || !$token) {
    error_log('status.php: GitHub config missing');
    json_out(['error' => 'Server misconfigured'], 500);
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

// ─── Parallel fetch via curl_multi ───────────────────────────────────
$mh = curl_multi_init();
$handles = [];
foreach ($numbers as $n) {
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
    curl_multi_add_handle($mh, $ch);
    $handles[$n] = $ch;
}

do {
    $execStatus = curl_multi_exec($mh, $running);
    if ($running) curl_multi_select($mh, 1.0);
} while ($running > 0 && $execStatus === CURLM_OK);

$items = [];
foreach ($handles as $n => $ch) {
    $body = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    if ($code === 404) {
        $items[] = ['number' => $n, 'state' => 'unknown', 'reason' => null];
        continue;
    }
    if ($code < 200 || $code >= 300) {
        error_log("status.php: GitHub returned $code for issue $n");
        $items[] = ['number' => $n, 'state' => 'unknown', 'reason' => null];
        continue;
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        $items[] = ['number' => $n, 'state' => 'unknown', 'reason' => null];
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
curl_multi_close($mh);

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
