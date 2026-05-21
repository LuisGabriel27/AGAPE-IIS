<?php
/**
 * Environment-driven application configuration.
 *
 * Docker copies this file to config/config.php inside the container.
 * For non-Docker installs, copy it manually and adjust .env / server env vars.
 */

$env = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
};

ini_set('display_errors', $env('APP_DEBUG', '0') === '1' ? '1' : '0');
ini_set('display_startup_errors', $env('APP_DEBUG', '0') === '1' ? '1' : '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');

define('DB_HOST', $env('DB_HOST', 'db'));
define('DB_PORT', $env('DB_PORT', '5432'));
define('DB_NAME', $env('DB_NAME', 'agape_iis'));
define('DB_USER', $env('DB_USER', 'agape'));
define('DB_PASS', $env('DB_PASS', 'agape_local_password'));
define('DB_SSLMODE', $env('DB_SSLMODE', 'disable'));

define('SUPABASE_URL', $env('SUPABASE_URL', ''));
define('SUPABASE_ANON_KEY', $env('SUPABASE_ANON_KEY', ''));
define('SUPABASE_SERVICE_ROLE_KEY', $env('SUPABASE_SERVICE_ROLE_KEY', ''));
define('SUPABASE_STORAGE_BUCKET', $env('SUPABASE_STORAGE_BUCKET', 'enrollment-documents'));
define('ENROLLMENT_DOCUMENT_STORAGE_DRIVER', $env('ENROLLMENT_DOCUMENT_STORAGE_DRIVER', 'local'));
define('SUPABASE_DB_HOST', $env('SUPABASE_DB_HOST', ''));
define('SUPABASE_DB_PORT', $env('SUPABASE_DB_PORT', '6543'));
define('SUPABASE_DB_NAME', $env('SUPABASE_DB_NAME', 'postgres'));
define('SUPABASE_DB_USER', $env('SUPABASE_DB_USER', ''));
define('SUPABASE_DB_PASS', $env('SUPABASE_DB_PASS', ''));
define('SUPABASE_DB_SSLMODE', $env('SUPABASE_DB_SSLMODE', 'require'));

define('APP_NAME', $env('APP_NAME', 'Academy Information System'));
define('APP_URL', rtrim((string)$env('APP_URL', 'http://localhost:8080'), '/'));
define('APP_VERSION', $env('APP_VERSION', '1.0.0'));

define('GOOGLE_CLIENT_ID', $env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', $env('GOOGLE_CLIENT_SECRET', ''));
define('GOOGLE_REDIRECT_URI', APP_URL . '/auth/oauth-callback.php');

define('SESSION_LIFETIME', (int)$env('SESSION_LIFETIME', '3600'));
define('MAX_LOGIN_ATTEMPTS', (int)$env('MAX_LOGIN_ATTEMPTS', '5'));
define('LOCKOUT_DURATION', (int)$env('LOCKOUT_DURATION', '900'));

date_default_timezone_set($env('APP_TIMEZONE', 'Asia/Manila'));
