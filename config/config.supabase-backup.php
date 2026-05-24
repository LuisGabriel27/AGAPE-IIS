<?php
/**
 * Backup of the previous hardcoded Supabase configuration.
 * Created before switching config.php to Docker/XAMPP .env-driven settings.
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

define('DB_HOST', 'aws-1-ap-southeast-1.pooler.supabase.com');
define('DB_PORT', '5432');
define('DB_NAME', 'postgres');
define('DB_USER', 'postgres.uidydlciqrefvcakjfat');
define('DB_PASS', 'agapeadmin@1234');

define('SUPABASE_URL', 'https://uidydlciqrefvcakjfat.supabase.co');
define('SUPABASE_ANON_KEY', 'sb_publishable_m3j6jAtCSVOjD52qfKXi2w_yjz7nevo');

define('APP_NAME', 'Academy Information System');
define('APP_URL', 'http://localhost/softeng-AIIS/AGAPE-IIS');
define('APP_VERSION', '1.0.0');

define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI', APP_URL . '/auth/oauth-callback.php');

define('SESSION_LIFETIME', 3600);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);

date_default_timezone_set('Asia/Manila');
