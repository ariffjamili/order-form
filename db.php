<?php
declare(strict_types=1);

/**
 * db.php — MySQL connection for GLORIOUS90 Order Form
 *
 * Credentials are loaded from .env (gitignored).
 * Copy .env.example → .env and fill in your cPanel MySQL details.
 *
 * SECURITY (cPanel shared hosting):
 *   Option A (best): Move .env one level ABOVE public_html, e.g.
 *     /home/<cpanel_user>/.env
 *   Then update the loadEnv() path below to:
 *     loadEnv(dirname(__DIR__) . '/.env');
 *
 *   Option B: Keep .env inside public_html and add to .htaccess:
 *     <FilesMatch "^\.env">
 *         Order allow,deny
 *         Deny from all
 *     </FilesMatch>
 *   (Already included in the project .htaccess.)
 */

// ── .env loader (guard prevents duplicate definition when included twice) ────
if (!function_exists('loadEnv')) {
    function loadEnv(string $path): void {
        if (!is_readable($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\"'");
            if ($key !== '') $_ENV[$key] = $val;
        }
    }
}

loadEnv(__DIR__ . '/.env');

// ── Connect ──────────────────────────────────────────────────────────────────
$mysqli = new mysqli(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? ''
);

if ($mysqli->connect_errno) {
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => 'Sambungan pangkalan data gagal.']);
    exit;
}

$mysqli->set_charset('utf8mb4');

return $mysqli;
