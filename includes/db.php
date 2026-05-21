<?php
/**
 * PDO Database Connection — Supabase Transaction Pooler (port 6543)
 *
 * Transaction Pooler keeps a persistent pool of PG connections so each PHP
 * request does NOT pay a full SSL+TCP handshake cost. This is the primary
 * fix for Supabase latency in PHP apps.
 *
 * Rules for Transaction Pooler:
 *  - ATTR_EMULATE_PREPARES must be TRUE (PgBouncer does not support the
 *    PostgreSQL extended query protocol used by native prepared statements).
 *  - Do NOT run session-level SET commands (they are lost between transactions).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/logger.php';

function databaseDeploymentMode(): string
{
    $mode = defined('APP_DEPLOYMENT_MODE')
        ? (string)APP_DEPLOYMENT_MODE
        : (string)(getenv('APP_DEPLOYMENT_MODE') ?: 'hybrid_role_routed');
    $mode = strtolower(trim($mode));

    return in_array($mode, ['development', 'local_school', 'online_portal', 'hybrid_role_routed'], true)
        ? $mode
        : 'hybrid_role_routed';
}

function normalizeDatabaseScope(?string $scope): string
{
    $scope = strtolower(trim((string)$scope));
    return $scope === 'supabase' ? 'supabase' : 'local';
}

/**
 * Decide which database should serve a role.
 *
 * hybrid_role_routed:
 * - admin/clerk use the Docker local database for school-office offline work.
 * - teacher/guardian use Supabase directly because they access the portal remotely.
 */
function databaseScopeForRole(?string $role): string
{
    $mode = databaseDeploymentMode();
    $role = strtolower(trim((string)$role));

    if ($mode === 'online_portal') {
        return 'supabase';
    }

    if ($mode === 'hybrid_role_routed' && in_array($role, ['teacher', 'guardian'], true)) {
        return 'supabase';
    }

    return 'local';
}

function activeDatabaseScope(?string $scope = null): string
{
    if ($scope !== null) {
        return normalizeDatabaseScope($scope);
    }

    return databaseScopeForRole($_SESSION['role'] ?? null);
}

/**
 * @return array{host:string,port:string,name:string,user:string,password:string,sslmode:string}
 */
function databaseConfigForScope(string $scope): array
{
    $scope = normalizeDatabaseScope($scope);

    if ($scope === 'supabase') {
        $hasSupabaseDbConfig = defined('SUPABASE_DB_HOST')
            && trim((string)SUPABASE_DB_HOST) !== ''
            && defined('SUPABASE_DB_USER')
            && trim((string)SUPABASE_DB_USER) !== ''
            && defined('SUPABASE_DB_PASS')
            && trim((string)SUPABASE_DB_PASS) !== '';

        if ($hasSupabaseDbConfig) {
            return [
                'host' => (string)SUPABASE_DB_HOST,
                'port' => defined('SUPABASE_DB_PORT') ? (string)SUPABASE_DB_PORT : '6543',
                'name' => defined('SUPABASE_DB_NAME') ? (string)SUPABASE_DB_NAME : 'postgres',
                'user' => (string)SUPABASE_DB_USER,
                'password' => (string)SUPABASE_DB_PASS,
                'sslmode' => defined('SUPABASE_DB_SSLMODE') ? (string)SUPABASE_DB_SSLMODE : 'require',
            ];
        }

        $dbHost = defined('DB_HOST') ? strtolower(trim((string)DB_HOST)) : '';
        $dbLooksLocal = in_array($dbHost, ['db', 'localhost', '127.0.0.1', '::1'], true);
        if (databaseDeploymentMode() === 'hybrid_role_routed' && $dbLooksLocal) {
            appLog('error', 'Supabase database settings are missing for hybrid role-routed mode.');
            die('Supabase database settings are missing for teacher and guardian access.');
        }
    }

    return [
        'host' => defined('DB_HOST') ? (string)DB_HOST : 'db',
        'port' => defined('DB_PORT') ? (string)DB_PORT : '5432',
        'name' => defined('DB_NAME') ? (string)DB_NAME : 'agape_iis',
        'user' => defined('DB_USER') ? (string)DB_USER : 'agape',
        'password' => defined('DB_PASS') ? (string)DB_PASS : '',
        'sslmode' => defined('DB_SSLMODE') ? (string)DB_SSLMODE : 'disable',
    ];
}

function getDB(?string $scope = null): PDO
{
    static $connections = [];

    $scope = activeDatabaseScope($scope);
    if (!isset($connections[$scope])) {
        $config = databaseConfigForScope($scope);
        $dsn = 'pgsql:host=' . $config['host']
             . ';port=' . $config['port']
             . ';dbname=' . $config['name']
             . ';sslmode=' . $config['sslmode']
             . ';connect_timeout=10';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,  // required for Supabase Transaction Pooler
        ];
        try {
            $connections[$scope] = new PDO($dsn, $config['user'], $config['password'], $options);
        } catch (PDOException $e) {
            logException($e, 'Database connection failed.', ['database_scope' => $scope]);
            die('A database error occurred. Please try again later.');
        }
    }

    return $connections[$scope];
}
