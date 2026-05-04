<?php
/**
 * Customer feedback submission endpoint.
 *
 * Accepts multipart/form-data, validates input, converts uploaded images
 * to WebP, saves them under UPLOADS_DIR with a public URL prefix, then
 * creates a GitHub issue with embedded image references.
 *
 * Required env vars (set via php-fpm pool config or shell env):
 *   GITHUB_TOKEN     fine-grained PAT, scope: Issues read/write on the repo
 *   GITHUB_OWNER     "your-org"
 *   GITHUB_REPO      "your-repo"
 *   ALLOWED_ORIGIN   "https://yoursite.com"
 *   UPLOADS_DIR      absolute path on disk, e.g. "/var/lib/calliope/uploads"
 *   UPLOADS_URL      public base URL, e.g. "https://yoursite.com/uploads"
 *
 * nginx (sketch):
 *   location = /api/submit { fastcgi_pass unix:/run/php/php-fpm.sock; ... include fastcgi_params; }
 *   client_max_body_size 50m;
 *   location /uploads/ {
 *     alias /var/lib/calliope/uploads/;
 *     expires 1y;
 *     add_header Cache-Control "public, immutable";
 *     add_header X-Content-Type-Options nosniff;
 *   }
 */

declare(strict_types=1);

// ─── Config ──────────────────────────────────────────────────────────
const MAX_FILES        = 4;
const MAX_FILE_SIZE    = 10 * 1024 * 1024;   // 10 MB
const MAX_DIMENSION    = 2000;                // px, longest edge
const WEBP_QUALITY     = 82;
const ALLOWED_TYPES    = ['bug', 'feature', 'improvement'];
const ALLOWED_AREAS    = ['auth','billing','dashboard','integrations','notifications','api','export','mobile','performance','other'];
const ALLOWED_ENVS     = ['production','staging','sandbox','local','unknown'];
const ALLOWED_FREQ     = ['always','often','sometimes','once'];
const ALLOWED_BLOCKING = ['yes','workaround','no'];
const ALLOWED_MIMES    = ['image/png','image/jpeg','image/gif','image/webp'];

// ─── CORS + method gate ──────────────────────────────────────────────
$origin = getenv('ALLOWED_ORIGIN') ?: '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { json_out(['error' => 'Method not allowed'], 405); }

// ─── Honeypot ────────────────────────────────────────────────────────
if (!empty($_POST['website'])) { json_out(['ok' => true]); }

// ─── Required env: STATUS_SIGN_KEY ──────────────────────────────────
// Without this the submission would land in GitHub but the customer's
// localStorage row would never get a token, leaving them unable to
// see live status (declined / closed / triage) for the rest of that
// device's life. Fail fast instead of silently mis-configuring data.
if (!getenv('STATUS_SIGN_KEY')) {
    error_log('submit.php: STATUS_SIGN_KEY not configured');
    json_out(['error' => 'Server misconfigured'], 500);
}

// ─── Parse + validate ────────────────────────────────────────────────
$ticket = parse_submission($_POST);
$err = validate_submission($ticket);
if ($err) json_out(['error' => $err], 400);

// ─── Process attachments ─────────────────────────────────────────────
$saved = [];
try {
    $saved = process_attachments($_FILES['attachments'] ?? null);
} catch (RuntimeException $e) {
    json_out(['error' => $e->getMessage()], 400);
}

// ─── Build issue + post to GitHub ────────────────────────────────────
$labels = [
    'from-customer',
    'type:' . $ticket['type'],
];
if ($ticket['area'])        $labels[] = 'area:' . $ticket['area'];
if ($ticket['environment']) $labels[] = 'env:'  . $ticket['environment'];

$body = build_issue_body($ticket, $saved);

$gh = github_create_issue($ticket['title'], $body, $labels);
if ($gh === null) {
    cleanup_files($saved);
    json_out(['error' => 'Could not submit. Please try again later.'], 502);
}

// Issue a short HMAC token tied to this issue number AND this
// owner/repo, so a key shared across deployments (or carried over a
// repo migration) can't validate tokens against a different repo's
// issue space. The client stores the token next to the number in
// localStorage and passes it to /api/status and /api/comments, both
// of which recompute the HMAC over the same `<owner>/<repo>#<n>`
// string before allowing access.
$signKey   = (string)getenv('STATUS_SIGN_KEY');
$ghOwnerEv = (string)getenv('GITHUB_OWNER');
$ghRepoEv  = (string)getenv('GITHUB_REPO');
$token     = substr(hash_hmac('sha256', "$ghOwnerEv/$ghRepoEv#" . $gh['number'], $signKey), 0, 16);

json_out(['ok' => true, 'number' => $gh['number'], 'token' => $token]);

// ─── Functions ───────────────────────────────────────────────────────

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function txt($v, int $max): string {
    return mb_substr(trim((string)($v ?? '')), 0, $max);
}

function pick($v, array $allowed): string {
    $v = (string)($v ?? '');
    return in_array($v, $allowed, true) ? $v : '';
}

function parse_submission(array $p): array {
    return [
        'type'              => pick($p['type'] ?? '', ALLOWED_TYPES),
        'title'             => txt($p['title'] ?? '', 200),
        'description'       => txt($p['description'] ?? '', 3000),
        'reproductionSteps' => txt($p['reproductionSteps'] ?? '', 2500),
        'expectedResult'    => txt($p['expectedResult'] ?? '', 1500),
        'frequency'         => pick($p['frequency'] ?? '', ALLOWED_FREQ),
        'blocking'          => pick($p['blocking'] ?? '', ALLOWED_BLOCKING),
        'featureUseCase'    => txt($p['featureUseCase'] ?? '', 2000),
        'successCriteria'   => txt($p['successCriteria'] ?? '', 2000),
        'alternatives'      => txt($p['alternatives'] ?? '', 1500),
        'area'              => pick($p['area'] ?? '', ALLOWED_AREAS),
        'environment'       => pick($p['environment'] ?? '', ALLOWED_ENVS),
        'references'        => txt($p['references'] ?? '', 1500),
        'name'              => txt($p['name'] ?? '', 100),
        'email'             => txt($p['email'] ?? '', 200),
        'company'           => txt($p['company'] ?? '', 120),
        'role'              => txt($p['role'] ?? '', 120),
        'pageUrl'           => txt($p['pageUrl'] ?? '', 500),
        'userAgent'         => txt($p['userAgent'] ?? '', 500),
        'language'          => txt($p['language'] ?? '', 80),
        'screenSize'        => txt($p['screenSize'] ?? '', 80),
        'timeZone'          => txt($p['timeZone'] ?? '', 80),
        'submittedAt'       => txt($p['submittedAt'] ?? '', 80) ?: gmdate('c'),
    ];
}

function validate_submission(array $t): string {
    if (!$t['type'])        return 'Please choose what kind of feedback this is.';
    if (!$t['title'])       return 'A short summary is required.';
    if (!$t['description']) return 'Please describe what is going on.';
    if (!$t['name'])        return 'Your name is required.';
    if (!$t['email'])       return 'Your email is required.';
    if (!filter_var($t['email'], FILTER_VALIDATE_EMAIL)) {
        return 'Please provide a valid email address.';
    }
    return '';
}

function process_attachments($files): array {
    if (!$files || !is_array($files['name'] ?? null)) return [];

    $count = count($files['name']);
    if ($count > MAX_FILES) {
        throw new RuntimeException('Too many images attached.');
    }

    $base = rtrim(getenv('UPLOADS_DIR') ?: '', '/');
    $url  = rtrim(getenv('UPLOADS_URL') ?: '', '/');
    if (!$base || !$url) throw new RuntimeException('Server upload path not configured.');

    $datePath = gmdate('Y/m/d');
    $dir = "$base/$datePath";
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = [];

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed for one of the files.');
        }
        if ($files['size'][$i] > MAX_FILE_SIZE) {
            throw new RuntimeException('One of the images is over 10MB.');
        }
        $tmp  = $files['tmp_name'][$i];
        $mime = $finfo->file($tmp);
        if (!in_array($mime, ALLOWED_MIMES, true)) {
            throw new RuntimeException('Only PNG, JPEG, GIF, and WebP images are allowed.');
        }

        $img = @imagecreatefromstring(file_get_contents($tmp));
        if ($img === false) throw new RuntimeException('Could not read image data.');

        $w = imagesx($img); $h = imagesy($img);
        $longest = max($w, $h);
        if ($longest > MAX_DIMENSION) {
            $scale = MAX_DIMENSION / $longest;
            $resized = imagescale($img, (int)round($w * $scale), (int)round($h * $scale));
            if ($resized !== false) { imagedestroy($img); $img = $resized; }
        }
        // Preserve transparency for PNG/GIF/WebP sources
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $token = bin2hex(random_bytes(12));
        $relPath = "$datePath/$token.webp";
        $absPath = "$base/$relPath";

        if (!imagewebp($img, $absPath, WEBP_QUALITY)) {
            imagedestroy($img);
            throw new RuntimeException('Could not encode image.');
        }
        imagedestroy($img);
        @chmod($absPath, 0644);

        $saved[] = [
            'absPath'  => $absPath,
            'url'      => "$url/$relPath",
            'origName' => basename($files['name'][$i]),
        ];
    }

    return $saved;
}

function cleanup_files(array $saved): void {
    foreach ($saved as $f) { @unlink($f['absPath']); }
}

function build_issue_body(array $t, array $attachments): string {
    $lines = [
        '## Description',
        $t['description'],
    ];

    if (!empty($attachments)) {
        $lines[] = '';
        $lines[] = '## Screenshots';
        foreach ($attachments as $a) {
            $alt = preg_replace('/[\[\]]/', '', $a['origName']);
            $lines[] = "![$alt]({$a['url']})";
        }
    }

    if ($t['type'] === 'bug') {
        if ($t['reproductionSteps']) {
            $lines[] = '';
            $lines[] = '## Steps to reproduce';
            $lines[] = $t['reproductionSteps'];
        }
        if ($t['expectedResult']) {
            $lines[] = '';
            $lines[] = '## Expected result';
            $lines[] = $t['expectedResult'];
        }
        if ($t['frequency'] || $t['blocking']) {
            $lines[] = '';
            $lines[] = '## Impact';
            if ($t['frequency']) $lines[] = '- Frequency: ' . $t['frequency'];
            if ($t['blocking'])  $lines[] = '- Blocking: '  . $t['blocking'];
        }
    } else {
        if ($t['featureUseCase']) {
            $lines[] = '';
            $lines[] = '## Use case';
            $lines[] = $t['featureUseCase'];
        }
        if ($t['successCriteria']) {
            $lines[] = '';
            $lines[] = '## What good looks like';
            $lines[] = $t['successCriteria'];
        }
        if ($t['alternatives']) {
            $lines[] = '';
            $lines[] = '## Current workaround';
            $lines[] = $t['alternatives'];
        }
    }

    if ($t['references']) {
        $lines[] = '';
        $lines[] = '## References';
        $lines[] = $t['references'];
    }

    // Everything between these markers is hidden from the customer-facing
    // modal in index.html but still rendered in GitHub's UI for the team.
    $lines[] = '';
    $lines[] = '<!-- CUSTOMER_HIDE_START -->';
    $lines[] = '## Submitter';
    $lines[] = '- Name: '  . $t['name'];
    $lines[] = '- Email: ' . $t['email'];
    if ($t['company']) $lines[] = '- Company: ' . $t['company'];
    if ($t['role'])    $lines[] = '- Role: '    . $t['role'];

    $lines[] = '';
    $lines[] = '## Submission metadata';
    $lines[] = '- Submitted at: ' . $t['submittedAt'];
    if ($t['area'])        $lines[] = '- Product area: ' . $t['area'];
    if ($t['environment']) $lines[] = '- Environment: '  . $t['environment'];
    if ($t['pageUrl'])     $lines[] = '- Page URL: '     . $t['pageUrl'];
    if ($t['userAgent'])   $lines[] = '- User agent: '   . $t['userAgent'];
    if ($t['language'])    $lines[] = '- Language: '     . $t['language'];
    if ($t['screenSize'])  $lines[] = '- Screen size: '  . $t['screenSize'];
    if ($t['timeZone'])    $lines[] = '- Time zone: '    . $t['timeZone'];
    $lines[] = '<!-- CUSTOMER_HIDE_END -->';

    return implode("\n", $lines);
}

function github_create_issue(string $title, string $body, array $labels): ?array {
    $token = getenv('GITHUB_TOKEN');
    $owner = getenv('GITHUB_OWNER');
    $repo  = getenv('GITHUB_REPO');
    if (!$token || !$owner || !$repo) {
        error_log('submit.php: GitHub config missing');
        return null;
    }

    $url = "https://api.github.com/repos/$owner/$repo/issues";
    $payload = json_encode(['title' => $title, 'body' => $body, 'labels' => $labels]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Accept: application/vnd.github+json',
            'Content-Type: application/json',
            'User-Agent: customer-portal',
        ],
    ]);
    $res    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log("submit.php: GitHub returned $status: $res");
        return null;
    }
    return json_decode($res, true);
}
