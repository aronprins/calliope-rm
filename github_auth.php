<?php
/**
 * GitHub API authentication helper.
 *
 * Preferred auth is a GitHub App installation:
 *   GITHUB_APP_ID
 *   GITHUB_APP_INSTALLATION_ID
 *   GITHUB_APP_PRIVATE_KEY or GITHUB_APP_PRIVATE_KEY_B64
 *
 * Legacy fallback:
 *   GITHUB_TOKEN
 */

declare(strict_types=1);

function github_api_token(): ?string {
    $appId          = getenv('GITHUB_APP_ID');
    $installationId = getenv('GITHUB_APP_INSTALLATION_ID');
    $privateKeyEnv  = getenv('GITHUB_APP_PRIVATE_KEY') ?: getenv('GITHUB_APP_PRIVATE_KEY_B64');
    $privateKey     = github_app_private_key();
    $hasAppConfig    = $appId || $installationId || $privateKeyEnv;

    if ($hasAppConfig) {
        if (!$appId || !$installationId || !$privateKey) {
            error_log('github_auth.php: incomplete GitHub App config');
            return null;
        }

        return github_app_installation_token((string)$appId, (string)$installationId, $privateKey);
    }

    $token = getenv('GITHUB_TOKEN');
    return $token ? (string)$token : null;
}

function github_app_private_key(): ?string {
    $raw = getenv('GITHUB_APP_PRIVATE_KEY_B64');
    if ($raw) {
        $decoded = base64_decode(preg_replace('/\s+/', '', (string)$raw) ?? (string)$raw, true);
        if ($decoded !== false && $decoded !== '') return $decoded;
    }

    $raw = getenv('GITHUB_APP_PRIVATE_KEY');
    if (!$raw) return null;

    return str_replace('\n', "\n", (string)$raw);
}

function github_app_installation_token(string $appId, string $installationId, string $privateKey): ?string {
    $cacheFile = github_app_token_cache_file($appId, $installationId);
    $cached = github_app_read_cached_token($cacheFile);
    if ($cached !== null) return $cached;

    $jwt = github_app_jwt($appId, $privateKey);
    if ($jwt === null) return null;

    $ch = curl_init("https://api.github.com/app/installations/$installationId/access_tokens");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $jwt",
            'Accept: application/vnd.github+json',
            'Content-Type: application/json',
            'User-Agent: customer-portal',
        ],
    ]);
    $res    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log("github_auth.php: GitHub App token request returned $status: $res");
        return null;
    }

    $data = json_decode((string)$res, true);
    if (!is_array($data) || empty($data['token']) || empty($data['expires_at'])) {
        error_log('github_auth.php: invalid GitHub App token response');
        return null;
    }

    github_app_write_cached_token($cacheFile, [
        'token'      => (string)$data['token'],
        'expires_at' => (string)$data['expires_at'],
    ]);

    return (string)$data['token'];
}

function github_app_jwt(string $appId, string $privateKey): ?string {
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iat' => $now - 60,
        'exp' => $now + 540,
        'iss' => $appId,
    ];

    $signingInput = github_base64url(json_encode($header))
        . '.'
        . github_base64url(json_encode($payload));

    $key = openssl_pkey_get_private($privateKey);
    if ($key === false) {
        error_log('github_auth.php: invalid GitHub App private key');
        return null;
    }

    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        error_log('github_auth.php: failed to sign GitHub App JWT');
        return null;
    }

    return $signingInput . '.' . github_base64url($signature);
}

function github_base64url(string|false $data): string {
    return rtrim(strtr(base64_encode((string)$data), '+/', '-_'), '=');
}

function github_app_token_cache_file(string $appId, string $installationId): string {
    $cacheDir = getenv('GITHUB_AUTH_CACHE_DIR')
        ?: getenv('BOARD_CACHE_DIR')
        ?: sys_get_temp_dir();

    return $cacheDir . '/calliope-gh-app-token-' . sha1("$appId:$installationId") . '.json';
}

function github_app_read_cached_token(string $cacheFile): ?string {
    if (!is_file($cacheFile)) return null;

    $data = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($data) || empty($data['token']) || empty($data['expires_at'])) {
        return null;
    }

    $expires = strtotime((string)$data['expires_at']);
    if ($expires === false || $expires <= time() + 300) {
        return null;
    }

    return (string)$data['token'];
}

function github_app_write_cached_token(string $cacheFile, array $data): void {
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $tmp = @tempnam($dir, 'calliope-gh-app-token-');
    if ($tmp === false) return;

    @chmod($tmp, 0600);
    if (@file_put_contents($tmp, json_encode($data), LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }
    if (!@rename($tmp, $cacheFile)) {
        @unlink($tmp);
        return;
    }
    @chmod($cacheFile, 0600);
}
