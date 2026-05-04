<?php
/**
 * Customer-visible comments for a single issue.
 *
 * Returns the subset of comments on a GitHub issue that should be shown
 * to customers. A comment is shown when:
 *   - the author is OWNER, MEMBER, or COLLABORATOR (team members), OR
 *   - the comment body contains the marker <!-- public --> (any author)
 *
 * The marker is stripped from the rendered body. Comments are cached
 * per-issue for 60 seconds.
 *
 * Required env vars: same as board.php (GITHUB_*, ALLOWED_ORIGIN).
 *
 * Optional:
 *   COMMENTS_CACHE_TTL  cache lifetime in seconds (default 60)
 *   COMMENTS_CACHE_DIR  cache directory (default sys_get_temp_dir())
 *
 * Request:
 *   GET /api/comments?issue=42
 */

declare(strict_types=1);

const PUBLIC_MARKER       = '<!-- public -->';
const TRUSTED_ASSOCIATIONS = ['OWNER', 'MEMBER', 'COLLABORATOR'];

// ─── CORS + method gate ──────────────────────────────────────────────
$origin = getenv('ALLOWED_ORIGIN') ?: '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     { json_out(['error' => 'Method not allowed'], 405); }

$issue = filter_input(INPUT_GET, 'issue', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$issue) json_out(['error' => 'Invalid issue number'], 400);

$owner = getenv('GITHUB_OWNER');
$repo  = getenv('GITHUB_REPO');
$token = getenv('GITHUB_TOKEN');
if (!$owner || !$repo || !$token) {
    error_log('comments.php: GitHub config missing');
    json_out(['error' => 'Server misconfigured'], 500);
}

// ─── Access control ──────────────────────────────────────────────────
// Public-board issues are visible to anyone. Non-public issues (still
// in triage, declined, internally-closed) require a valid HMAC token
// — the same one /api/status uses — so a stranger faking a
// `calliope-submissions` localStorage row can't pull team comments
// they were never meant to see.
$callerToken = (string)($_GET['token'] ?? '');
$signKey     = (string)(getenv('STATUS_SIGN_KEY') ?: '');

$validToken = false;
if ($signKey !== '' && $callerToken !== '') {
    // Same construction as submit.php — `<owner>/<repo>#<n>` so a
    // shared key can't validate tokens across deployments.
    $expected   = substr(hash_hmac('sha256', "$owner/$repo#$issue", $signKey), 0, 16);
    $validToken = hash_equals($expected, $callerToken);
}

if (!$validToken && !issue_is_public($owner, $repo, $issue, $token)) {
    // Don't disclose existence: anonymous access to a non-public
    // issue's comments looks the same as the issue not existing at all.
    json_out(['error' => 'Not found'], 404);
}

// ─── Cache fast-path ─────────────────────────────────────────────────
$ttl       = (int)(getenv('COMMENTS_CACHE_TTL') ?: 60);
$cacheDir  = getenv('COMMENTS_CACHE_DIR') ?: sys_get_temp_dir();
$cacheFile = $cacheDir . '/calliope-comments-' . sha1("$owner/$repo") . "-$issue.json";

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    header('Content-Type: application/json');
    readfile($cacheFile);
    exit;
}

// ─── Fetch from GitHub ───────────────────────────────────────────────
$apiUrl = "https://api.github.com/repos/$owner/$repo/issues/$issue/comments?per_page=100";

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer $token",
        'Accept: application/vnd.github+json',
        'User-Agent: customer-portal',
    ],
]);
$res    = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status === 404) json_out(['error' => 'Issue not found'], 404);
if ($status < 200 || $status >= 300) {
    error_log("comments.php: GitHub returned $status: $res");
    json_out(['error' => 'Could not load comments.'], 502);
}

$raw = json_decode($res, true);
if (!is_array($raw)) json_out(['error' => 'Invalid GitHub response.'], 502);

$comments = [];
foreach ($raw as $c) {
    $body  = (string)($c['body'] ?? '');
    $assoc = (string)($c['author_association'] ?? '');
    $hasMarker = str_contains($body, PUBLIC_MARKER);

    $trusted = in_array($assoc, TRUSTED_ASSOCIATIONS, true);
    if (!$trusted && !$hasMarker) continue;

    if ($hasMarker) {
        $body = str_replace(PUBLIC_MARKER, '', $body);
    }
    // Strip team-only spans and any other HTML comments before exposing
    // the body to the client — never trust the renderer to do this.
    $body = preg_replace(
        '/<!--\s*CUSTOMER_HIDE_START\s*-->[\s\S]*?<!--\s*CUSTOMER_HIDE_END\s*-->/',
        '',
        $body
    ) ?? $body;
    $body = preg_replace('/<!--[\s\S]*?-->/', '', $body) ?? $body;
    $body = trim($body);

    $comments[] = [
        'id'        => $c['id'] ?? null,
        'author'    => $c['user']['login']      ?? 'unknown',
        'avatarUrl' => $c['user']['avatar_url'] ?? null,
        'body'      => $body,
        'createdAt' => $c['created_at']         ?? null,
        'updatedAt' => $c['updated_at']         ?? null,
        'isTeam'    => $trusted,
    ];
}

$result  = ['issue' => $issue, 'comments' => $comments, 'fetchedAt' => gmdate('c')];
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

/**
 * Returns true if the issue carries the `public` label.
 *
 * Cached per-issue so the labels lookup isn't repeated on every modal
 * open (cache hits skip the GitHub round-trip entirely) and so a
 * GitHub outage doesn't make previously-public issues' cached
 * comments suddenly inaccessible.
 */
function issue_is_public(string $owner, string $repo, int $issue, string $ghToken): bool {
    $cacheDir  = (string)(getenv('COMMENTS_CACHE_DIR') ?: sys_get_temp_dir());
    $ttl       = (int)(getenv('PUBLIC_CACHE_TTL') ?: 300);
    $cacheFile = $cacheDir . '/calliope-pubcheck-' . sha1("$owner/$repo:$issue") . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && array_key_exists('public', $cached)) {
            return (bool)$cached['public'];
        }
    }

    $ch = curl_init("https://api.github.com/repos/$owner/$repo/issues/$issue");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $ghToken",
            'Accept: application/vnd.github+json',
            'User-Agent: customer-portal',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // On transient GitHub failure (rate limit, 5xx) fall back to the
    // most recent cached decision if any — even if expired — rather
    // than locking out cached public comments. Only persist a fresh
    // result back to the cache when the lookup actually succeeded.
    if ($code < 200 || $code >= 300) {
        if (is_file($cacheFile)) {
            $stale = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($stale) && array_key_exists('public', $stale)) {
                return (bool)$stale['public'];
            }
        }
        return false;
    }

    $data = json_decode((string)$body, true);
    $isPublic = false;
    if (is_array($data)) {
        foreach (($data['labels'] ?? []) as $l) {
            if (($l['name'] ?? '') === 'public') { $isPublic = true; break; }
        }
    }
    @file_put_contents($cacheFile, json_encode(['public' => $isPublic]), LOCK_EX);
    return $isPublic;
}
