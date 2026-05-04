<?php
/**
 * Customer feedback board endpoint.
 *
 * Returns issues with the `public` label, grouped into kanban columns.
 * Caches the GitHub response for 60 seconds via a single file in the
 * system temp directory.
 *
 * Required env vars (set via php-fpm pool config or shell env):
 *   GITHUB_TOKEN     fine-grained PAT, scope: Issues read on the repo
 *   GITHUB_OWNER     "your-org"
 *   GITHUB_REPO      "your-repo"
 *   ALLOWED_ORIGIN   "https://yoursite.com"
 *
 * Optional:
 *   BOARD_CACHE_TTL  cache lifetime in seconds (default 60)
 *   BOARD_CACHE_DIR  cache directory (default sys_get_temp_dir())
 *
 * nginx (sketch):
 *   location = /api/board {
 *     fastcgi_pass unix:/run/php/php-fpm.sock;
 *     fastcgi_param SCRIPT_FILENAME /var/www/calliope/board.php;
 *     include fastcgi_params;
 *   }
 */

declare(strict_types=1);

const STATUS_COLUMNS = [
    ['key' => 'planned',     'name' => 'Planned'],
    ['key' => 'in-progress', 'name' => 'In Progress'],
    ['key' => 'shipped',     'name' => 'Shipped'],
];

// ─── CORS + method gate ──────────────────────────────────────────────
$origin = getenv('ALLOWED_ORIGIN') ?: '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     { json_out(['error' => 'Method not allowed'], 405); }

$owner = getenv('GITHUB_OWNER');
$repo  = getenv('GITHUB_REPO');
$token = getenv('GITHUB_TOKEN');
if (!$owner || !$repo || !$token) {
    error_log('board.php: GitHub config missing');
    json_out(['error' => 'Server misconfigured'], 500);
}

// ─── Cache hit fast-path ─────────────────────────────────────────────
$ttl       = (int)(getenv('BOARD_CACHE_TTL') ?: 60);
$cacheDir  = getenv('BOARD_CACHE_DIR') ?: sys_get_temp_dir();
$cacheFile = $cacheDir . '/calliope-board-' . sha1("$owner/$repo") . '.json';

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    header('Content-Type: application/json');
    readfile($cacheFile);
    exit;
}

// ─── Fetch from GitHub ───────────────────────────────────────────────
$apiUrl = "https://api.github.com/repos/$owner/$repo/issues"
        . '?labels=public&state=all&per_page=100&sort=updated&direction=desc';

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

if ($status < 200 || $status >= 300) {
    error_log("board.php: GitHub returned $status: $res");
    json_out(['error' => 'Could not load board.'], 502);
}

$raw = json_decode($res, true);
if (!is_array($raw)) json_out(['error' => 'Invalid GitHub response.'], 502);

$issues = [];
foreach ($raw as $issue) {
    if (!empty($issue['pull_request'])) continue; // /issues includes PRs
    $issues[] = transform_issue($issue);
}

$columns = [];
foreach (STATUS_COLUMNS as $col) {
    $columns[] = [
        'key'    => $col['key'],
        'name'   => $col['name'],
        'issues' => array_values(array_filter($issues, fn($i) => $i['status'] === $col['key'])),
    ];
}

$result = [
    'columns'    => $columns,
    'totalCount' => count($issues),
    'fetchedAt'  => gmdate('c'),
];

$payload = json_encode($result);

// Best-effort cache write — don't fail the request if it doesn't land
@file_put_contents($cacheFile, $payload, LOCK_EX);

header('Content-Type: application/json');
echo $payload;

// ─── Helpers ─────────────────────────────────────────────────────────

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function transform_issue(array $issue): array {
    $labels = $issue['labels'] ?? [];

    $statusLabel = null;
    $typeLabel   = null;
    $visible     = [];
    foreach ($labels as $l) {
        $name = $l['name'] ?? '';
        if (str_starts_with($name, 'status:')) { $statusLabel = $name; continue; }
        if (str_starts_with($name, 'type:'))   { $typeLabel   = $name; continue; }
        if ($name === 'public' || $name === 'from-customer') continue;
        $visible[] = ['name' => $name, 'color' => $l['color'] ?? '888888'];
    }

    return [
        'number'    => $issue['number'],
        'title'     => $issue['title'] ?? '',
        'body'      => strip_team_only((string)($issue['body'] ?? '')),
        'status'    => $statusLabel ? substr($statusLabel, strlen('status:')) : 'planned',
        'type'      => $typeLabel   ? substr($typeLabel,   strlen('type:'))   : null,
        'labels'    => $visible,
        'upvotes'   => $issue['reactions']['+1'] ?? 0,
        'comments'  => $issue['comments'] ?? 0,
        'state'     => $issue['state'] ?? 'open',
        'createdAt' => $issue['created_at'] ?? null,
        'updatedAt' => $issue['updated_at'] ?? null,
    ];
}

/**
 * Remove team-only sections wrapped in <!-- CUSTOMER_HIDE_START --> ...
 * <!-- CUSTOMER_HIDE_END --> markers, plus any other HTML comments. Keeps
 * customer-facing payload free of PII before it ever leaves the server.
 */
function strip_team_only(string $body): string {
    $body = preg_replace(
        '/<!--\s*CUSTOMER_HIDE_START\s*-->[\s\S]*?<!--\s*CUSTOMER_HIDE_END\s*-->/',
        '',
        $body
    ) ?? $body;
    $body = preg_replace('/<!--[\s\S]*?-->/', '', $body) ?? $body;
    return trim($body);
}
