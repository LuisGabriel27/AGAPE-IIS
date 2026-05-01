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

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Port 6543 = Supabase Transaction Pooler (much faster for web apps).
        // Port 5432 = Session Pooler (used only if you need SET/advisory locks).
        $dsn = 'pgsql:host=' . DB_HOST . ';port=6543;dbname=' . DB_NAME
             . ';sslmode=require;connect_timeout=10';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,  // required for Transaction Pooler
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            die('A database error occurred. Please try again later.');
        }
    }
    return $pdo;
}
