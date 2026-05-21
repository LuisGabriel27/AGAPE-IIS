<?php
/**
 * Shared helpers for AGAPE AIIS offline/online database sync.
 *
 * Used by both CLI scripts and the admin sync page. They move data between
 * the local Docker PostgreSQL database and the remote Supabase PostgreSQL
 * database.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

function syncRequireCli(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
}

function syncOut(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function syncFail(string $message, int $code = 1): void
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

function syncHasArg(string $arg): bool
{
    global $argv;
    return in_array($arg, $argv ?? [], true);
}

function syncArgValue(string $prefix, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, $prefix . '=')) {
            return substr($arg, strlen($prefix) + 1);
        }
    }

    return $default;
}

function syncEnv(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    if (defined($key)) {
        $constant = (string)constant($key);
        return $constant !== '' ? $constant : $default;
    }

    return $default;
}

function syncHostLooksRemote(string $host): bool
{
    $host = strtolower($host);
    return str_contains($host, 'supabase.co')
        || str_contains($host, 'pooler.supabase')
        || str_contains($host, 'pooler');
}

function syncAssertAppDatabaseIsLocal(): void
{
    $host = (string)syncEnv('DB_HOST', '');
    if (syncHostLooksRemote($host)) {
        syncFail(
            'The app database is currently pointing to Supabase. '
            . 'Switch .env to offline/local mode first: DB_HOST=db, DB_PORT=5432, DB_SSLMODE=disable.'
        );
    }
}

function syncOpenPgConnection(
    string $host,
    string $port,
    string $dbname,
    string $user,
    string $password,
    string $sslmode
): PDO {
    $dsn = 'pgsql:host=' . $host
        . ';port=' . $port
        . ';dbname=' . $dbname
        . ';sslmode=' . $sslmode
        . ';connect_timeout=15';

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
}

function syncRemoteDatabase(): PDO
{
    $required = [
        'SUPABASE_DB_HOST',
        'SUPABASE_DB_PORT',
        'SUPABASE_DB_NAME',
        'SUPABASE_DB_USER',
        'SUPABASE_DB_PASS',
        'SUPABASE_DB_SSLMODE',
    ];

    $values = [];
    foreach ($required as $key) {
        $values[$key] = syncEnv($key, '');
        if ($values[$key] === '') {
            syncFail("Missing {$key}. Add your Supabase database credentials to .env.");
        }
    }

    return syncOpenPgConnection(
        $values['SUPABASE_DB_HOST'],
        $values['SUPABASE_DB_PORT'],
        $values['SUPABASE_DB_NAME'],
        $values['SUPABASE_DB_USER'],
        $values['SUPABASE_DB_PASS'],
        $values['SUPABASE_DB_SSLMODE']
    );
}

/**
 * Tables copied/synced in dependency order.
 *
 * @return list<string>
 */
function syncTablePlan(): array
{
    return [
        'users',
        'user_roles',
        'guardians',
        'teachers',
        'user_profiles',
        'subjects',
        'sections',
        'students',
        'enrollments',
        'enrollment_documents',
        'enrollment_assessments',
        'enrollment_assessment_items',
        'grades',
        'schedules',
        'calendar_events',
        'payments',
        'student_face_profiles',
        'attendance_logs',
        'settings',
        'audit_log',
    ];
}

function syncQuoteIdent(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function syncTableSql(string $table): string
{
    return syncQuoteIdent($table);
}

function syncTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = :table_name
    ");
    $stmt->execute([':table_name' => $table]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * @return list<string>
 */
function syncColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table_name
        ORDER BY ordinal_position
    ");
    $stmt->execute([':table_name' => $table]);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return list<string>
 */
function syncCommonColumns(PDO $left, PDO $right, string $table): array
{
    $leftColumns = syncColumns($left, $table);
    $rightColumns = array_flip(syncColumns($right, $table));

    return array_values(array_filter(
        $leftColumns,
        static fn(string $column): bool => isset($rightColumns[$column])
    ));
}

/**
 * @return list<string>
 */
function syncPrimaryKeyColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT kcu.column_name
        FROM information_schema.table_constraints tc
        INNER JOIN information_schema.key_column_usage kcu
            ON kcu.constraint_schema = tc.constraint_schema
           AND kcu.constraint_name = tc.constraint_name
           AND kcu.table_schema = tc.table_schema
           AND kcu.table_name = tc.table_name
        WHERE tc.table_schema = 'public'
          AND tc.table_name = :table_name
          AND tc.constraint_type = 'PRIMARY KEY'
        ORDER BY kcu.ordinal_position
    ");
    $stmt->execute([':table_name' => $table]);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function syncColumnListSql(array $columns): string
{
    return implode(', ', array_map('syncQuoteIdent', $columns));
}

function syncScalar(mixed $value): mixed
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_array($value) || is_object($value)) {
        return json_encode($value);
    }

    return $value;
}

/**
 * @return array<string, mixed>
 */
function syncDecodeRowData(mixed $rowData): array
{
    if (is_array($rowData)) {
        return $rowData;
    }

    $decoded = json_decode((string)$rowData, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON row_data in sync_outbox.');
    }

    return $decoded;
}

/**
 * @return list<string>
 */
function syncPrimaryKeyForRow(PDO $pdo, string $table, array $rowData): array
{
    $pk = syncPrimaryKeyColumns($pdo, $table);
    if (!empty($pk)) {
        return $pk;
    }

    if (array_key_exists('id', $rowData)) {
        return ['id'];
    }

    if ($table === 'settings' && array_key_exists('key', $rowData)) {
        return ['key'];
    }

    throw new RuntimeException("Unable to determine primary key for {$table}.");
}

function syncWhereClauseForPk(array $pkColumns, array $rowData, array &$values): string
{
    $parts = [];
    foreach ($pkColumns as $column) {
        if (!array_key_exists($column, $rowData)) {
            throw new RuntimeException("Missing primary key column {$column} in row_data.");
        }

        $parts[] = syncQuoteIdent($column) . ' = ?';
        $values[] = syncScalar($rowData[$column]);
    }

    return implode(' AND ', $parts);
}

function syncUpsertRow(PDO $pdo, string $table, array $rowData): void
{
    $columns = syncColumns($pdo, $table);
    $columns = array_values(array_filter(
        $columns,
        static fn(string $column): bool => array_key_exists($column, $rowData)
    ));

    if (empty($columns)) {
        throw new RuntimeException("No writable columns found for {$table}.");
    }

    $pkColumns = syncPrimaryKeyForRow($pdo, $table, $rowData);
    $nonPkColumns = array_values(array_diff($columns, $pkColumns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $values = array_map(static fn(string $column): mixed => syncScalar($rowData[$column]), $columns);

    $sql = 'INSERT INTO ' . syncTableSql($table)
        . ' (' . syncColumnListSql($columns) . ')'
        . ' VALUES (' . $placeholders . ')'
        . ' ON CONFLICT (' . syncColumnListSql($pkColumns) . ') ';

    if (empty($nonPkColumns)) {
        $sql .= 'DO NOTHING';
    } else {
        $assignments = array_map(
            static fn(string $column): string => syncQuoteIdent($column) . ' = EXCLUDED.' . syncQuoteIdent($column),
            $nonPkColumns
        );
        $sql .= 'DO UPDATE SET ' . implode(', ', $assignments);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function syncDeleteRow(PDO $pdo, string $table, array $rowData): void
{
    $pkColumns = syncPrimaryKeyForRow($pdo, $table, $rowData);
    $values = [];
    $where = syncWhereClauseForPk($pkColumns, $rowData, $values);
    $stmt = $pdo->prepare('DELETE FROM ' . syncTableSql($table) . ' WHERE ' . $where);
    $stmt->execute($values);
}

function syncPrepareEnrollmentDocumentFileForSupabase(PDO $local, array $rowData, ?callable $logger = null): array
{
    $filePath = (string)($rowData['file_path'] ?? '');
    if ($filePath === '' || parseEnrollmentDocumentSupabaseReference($filePath) !== null) {
        return $rowData;
    }

    if (!str_starts_with(ltrim(str_replace('\\', '/', $filePath), '/'), 'uploads/enrollment-documents/')) {
        return $rowData;
    }

    if (!supabaseStorageConfigured()) {
        throw new RuntimeException(
            'Enrollment document file sync requires SUPABASE_SERVICE_ROLE_KEY and SUPABASE_STORAGE_BUCKET. '
            . 'Set them in .env before syncing local uploaded files.'
        );
    }

    $localPath = enrollmentDocumentLocalFilePath(['file_path' => $filePath]);
    if ($localPath === null) {
        throw new RuntimeException('Local enrollment document file is missing: ' . $filePath);
    }

    $enrollmentId = (int)($rowData['enrollment_id'] ?? 0);
    if ($enrollmentId < 1) {
        throw new RuntimeException('Enrollment document row is missing enrollment_id.');
    }

    $storedName = basename(str_replace('\\', '/', $filePath));
    $mimeType = (string)($rowData['mime_type'] ?? 'application/octet-stream');
    $remotePath = uploadEnrollmentDocumentToSupabase($localPath, $enrollmentId, $storedName, $mimeType);
    $rowData['file_path'] = $remotePath;

    if (isset($rowData['id'])) {
        try {
            $local->beginTransaction();
            $local->exec("SELECT set_config('agape.sync_disabled', 'on', true)");
            $stmt = $local->prepare("
                UPDATE enrollment_documents
                SET file_path = :file_path
                WHERE id = :id
            ");
            $stmt->execute([
                ':file_path' => $remotePath,
                ':id' => $rowData['id'],
            ]);
            $local->commit();
        } catch (Throwable $e) {
            if ($local->inTransaction()) {
                $local->rollBack();
            }
            throw $e;
        }
    }

    syncLog($logger, 'uploaded enrollment document file to Supabase Storage: ' . $remotePath);

    return $rowData;
}

function syncPendingOutboxCount(PDO $pdo): int
{
    if (!syncTableExists($pdo, 'sync_outbox')) {
        return 0;
    }

    return (int)$pdo->query('SELECT COUNT(*) FROM sync_outbox WHERE synced_at IS NULL')->fetchColumn();
}

function syncResetSerialSequences(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        $columns = syncColumns($pdo, $table);
        if (!in_array('id', $columns, true)) {
            continue;
        }

        $sequence = $pdo->query(
            'SELECT pg_get_serial_sequence(' . $pdo->quote($table) . ', ' . $pdo->quote('id') . ')'
        )->fetchColumn();

        if (!$sequence) {
            continue;
        }

        $maxId = (int)$pdo->query(
            'SELECT COALESCE(MAX(' . syncQuoteIdent('id') . '), 0) FROM ' . syncTableSql($table)
        )->fetchColumn();

        if ($maxId > 0) {
            $pdo->query('SELECT setval(' . $pdo->quote((string)$sequence) . ', ' . $maxId . ', true)');
        } else {
            $pdo->query('SELECT setval(' . $pdo->quote((string)$sequence) . ', 1, false)');
        }
    }
}

function syncOutboxSummary(PDO $pdo): array
{
    if (!syncTableExists($pdo, 'sync_outbox')) {
        return [];
    }

    return $pdo->query("
        SELECT table_name, operation, COUNT(*) AS count
        FROM sync_outbox
        WHERE synced_at IS NULL
        GROUP BY table_name, operation
        ORDER BY table_name, operation
    ")->fetchAll();
}

function syncAppMode(): string
{
    return syncHostLooksRemote((string)syncEnv('DB_HOST', '')) ? 'online_supabase' : 'offline_local';
}

function syncAssertAppDatabaseIsLocalOrThrow(): void
{
    if (syncAppMode() === 'online_supabase') {
        throw new RuntimeException(
            'The app is currently in online Supabase mode. Switch to offline/local mode before running local sync.'
        );
    }
}

function syncRemoteDatabaseOrThrow(): PDO
{
    $required = [
        'SUPABASE_DB_HOST',
        'SUPABASE_DB_PORT',
        'SUPABASE_DB_NAME',
        'SUPABASE_DB_USER',
        'SUPABASE_DB_PASS',
        'SUPABASE_DB_SSLMODE',
    ];

    $values = [];
    foreach ($required as $key) {
        $values[$key] = syncEnv($key, '');
        if ($values[$key] === '') {
            throw new RuntimeException("Missing {$key}. Add your Supabase database credentials to .env.");
        }
    }

    return syncOpenPgConnection(
        $values['SUPABASE_DB_HOST'],
        $values['SUPABASE_DB_PORT'],
        $values['SUPABASE_DB_NAME'],
        $values['SUPABASE_DB_USER'],
        $values['SUPABASE_DB_PASS'],
        $values['SUPABASE_DB_SSLMODE']
    );
}

function syncLog(?callable $logger, string $message): void
{
    if ($logger !== null) {
        $logger($message);
    }
}

/**
 * @return array<string, mixed>
 */
function syncStatusSnapshot(): array
{
    $pdo = getDB();
    $mode = syncAppMode();
    $counts = [];

    foreach (['users', 'students', 'guardians', 'teachers', 'enrollments'] as $table) {
        if (syncTableExists($pdo, $table)) {
            $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . syncTableSql($table))->fetchColumn();
        }
    }

    $lastSnapshotAt = null;
    if (syncTableExists($pdo, 'sync_state')) {
        $stmt = $pdo->prepare("SELECT value FROM sync_state WHERE key = 'last_snapshot_at' LIMIT 1");
        $stmt->execute();
        $lastSnapshotAt = $stmt->fetchColumn() ?: null;
    }

    return [
        'mode' => $mode,
        'db_host' => (string)syncEnv('DB_HOST', ''),
        'db_name' => (string)syncEnv('DB_NAME', ''),
        'supabase_db_host_configured' => syncEnv('SUPABASE_DB_HOST', '') !== '',
        'document_storage_driver' => enrollmentDocumentStorageDriver(),
        'supabase_storage_configured' => supabaseStorageConfigured(),
        'pending_changes' => $mode === 'offline_local' ? syncPendingOutboxCount($pdo) : 0,
        'outbox_summary' => $mode === 'offline_local' ? syncOutboxSummary($pdo) : [],
        'counts' => $counts,
        'last_snapshot_at' => $lastSnapshotAt,
    ];
}

/**
 * Copy Supabase rows into the local Docker database.
 *
 * @return array<string, mixed>
 */
function syncPullSupabaseSnapshotOperation(bool $force = false, bool $dryRun = false, ?callable $logger = null): array
{
    syncAssertAppDatabaseIsLocalOrThrow();

    $local = getDB();
    $remote = syncRemoteDatabaseOrThrow();
    $pending = syncPendingOutboxCount($local);

    if ($pending > 0 && !$force) {
        throw new RuntimeException(
            "Local database has {$pending} unsynced change(s). Sync to Supabase first before refreshing the local copy."
        );
    }

    $tables = [];
    foreach (syncTablePlan() as $table) {
        if (syncTableExists($local, $table) && syncTableExists($remote, $table)) {
            $tables[] = $table;
        }
    }

    if (empty($tables)) {
        throw new RuntimeException('No shared application tables were found between local and Supabase databases.');
    }

    syncLog($logger, 'Supabase -> local snapshot plan');
    syncLog($logger, 'Tables: ' . implode(', ', $tables));
    syncLog($logger, 'Pending local outbox rows: ' . $pending);

    if ($dryRun) {
        $remoteCounts = [];
        foreach ($tables as $table) {
            $remoteCounts[$table] = (int)$remote->query('SELECT COUNT(*) FROM ' . syncTableSql($table))->fetchColumn();
            syncLog($logger, str_pad($table, 36) . $remoteCounts[$table] . ' remote row(s)');
        }

        return [
            'dry_run' => true,
            'pending_before' => $pending,
            'tables' => $tables,
            'remote_counts' => $remoteCounts,
        ];
    }

    $copiedCounts = [];

    try {
        $local->beginTransaction();
        $local->exec("SELECT set_config('agape.sync_disabled', 'on', true)");
        $local->exec('TRUNCATE TABLE ' . implode(', ', array_map('syncTableSql', $tables)) . ' RESTART IDENTITY CASCADE');

        foreach ($tables as $table) {
            $columns = syncCommonColumns($local, $remote, $table);
            if (empty($columns)) {
                $copiedCounts[$table] = 0;
                continue;
            }

            $orderColumns = array_values(array_intersect(syncPrimaryKeyColumns($remote, $table), $columns));
            $orderSql = !empty($orderColumns) ? ' ORDER BY ' . syncColumnListSql($orderColumns) : '';
            $selectSql = 'SELECT ' . syncColumnListSql($columns) . ' FROM ' . syncTableSql($table) . $orderSql;
            $remoteRows = $remote->query($selectSql)->fetchAll();

            if (!empty($remoteRows)) {
                $insertSql = 'INSERT INTO ' . syncTableSql($table)
                    . ' (' . syncColumnListSql($columns) . ')'
                    . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $insertStmt = $local->prepare($insertSql);

                foreach ($remoteRows as $row) {
                    $values = array_map(
                        static fn(string $column): mixed => syncScalar($row[$column] ?? null),
                        $columns
                    );
                    $insertStmt->execute($values);
                }
            }

            $copiedCounts[$table] = count($remoteRows);
            syncLog($logger, str_pad($table, 36) . $copiedCounts[$table] . ' row(s) copied');
        }

        if (syncTableExists($local, 'sync_outbox')) {
            $local->exec('DELETE FROM sync_outbox');
        }
        if (syncTableExists($local, 'sync_state')) {
            $stmt = $local->prepare("
                INSERT INTO sync_state (key, value, updated_at)
                VALUES ('last_snapshot_at', NOW()::TEXT, NOW())
                ON CONFLICT (key)
                DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
            ");
            $stmt->execute();
        }

        syncResetSerialSequences($local, $tables);
        $local->commit();
    } catch (Throwable $e) {
        if ($local->inTransaction()) {
            $local->rollBack();
        }
        throw $e;
    }

    syncLog($logger, 'Snapshot complete. Local Docker database now has the Supabase records.');

    return [
        'dry_run' => false,
        'pending_before' => $pending,
        'tables' => $tables,
        'copied_counts' => $copiedCounts,
    ];
}

/**
 * Push pending local changes to Supabase.
 *
 * @return array<string, mixed>
 */
function syncPushToSupabaseOperation(
    bool $dryRun = false,
    bool $continueOnError = false,
    int $limit = 500,
    ?callable $logger = null
): array {
    syncAssertAppDatabaseIsLocalOrThrow();

    $local = getDB();
    if (!syncTableExists($local, 'sync_outbox')) {
        throw new RuntimeException('Local database does not have sync_outbox. Apply offline_sync_foundation.sql first.');
    }

    $pending = syncPendingOutboxCount($local);
    syncLog($logger, "Pending local changes: {$pending}");

    if ($pending === 0) {
        syncLog($logger, 'Nothing to sync.');
        return [
            'dry_run' => $dryRun,
            'pending_before' => 0,
            'synced' => 0,
            'remaining' => 0,
            'errors' => [],
        ];
    }

    if ($dryRun) {
        $summary = syncOutboxSummary($local);
        foreach ($summary as $row) {
            syncLog($logger, str_pad($row['table_name'] . ' ' . $row['operation'], 42) . $row['count']);
        }

        return [
            'dry_run' => true,
            'pending_before' => $pending,
            'summary' => $summary,
        ];
    }

    $remote = syncRemoteDatabaseOrThrow();
    $allowedTables = array_flip(syncTablePlan());
    $limit = max(1, $limit);
    $stmt = $local->query("
        SELECT *
        FROM sync_outbox
        WHERE synced_at IS NULL
        ORDER BY id
        LIMIT {$limit}
    ");
    $outboxRows = $stmt->fetchAll();
    $synced = 0;
    $affectedTables = [];
    $errors = [];

    foreach ($outboxRows as $outbox) {
        $outboxId = (int)$outbox['id'];
        $table = (string)$outbox['table_name'];
        $operation = (string)$outbox['operation'];

        try {
            if (!isset($allowedTables[$table])) {
                throw new RuntimeException("Table {$table} is not in the sync allow-list.");
            }
            if (!syncTableExists($remote, $table)) {
                throw new RuntimeException("Remote table {$table} does not exist.");
            }

            $rowData = syncDecodeRowData($outbox['row_data']);
            if ($table === 'enrollment_documents' && ($operation === 'INSERT' || $operation === 'UPDATE')) {
                $rowData = syncPrepareEnrollmentDocumentFileForSupabase($local, $rowData, $logger);
            }

            $remote->beginTransaction();
            $remote->exec("SELECT set_config('agape.sync_disabled', 'on', true)");

            if ($operation === 'DELETE') {
                syncDeleteRow($remote, $table, $rowData);
            } elseif ($operation === 'INSERT' || $operation === 'UPDATE') {
                syncUpsertRow($remote, $table, $rowData);
            } else {
                throw new RuntimeException("Unsupported operation {$operation}.");
            }

            $remote->commit();

            $markStmt = $local->prepare("
                UPDATE sync_outbox
                SET synced_at = NOW(),
                    error_message = NULL
                WHERE id = :id
            ");
            $markStmt->execute([':id' => $outboxId]);

            $synced++;
            $affectedTables[$table] = true;
            syncLog($logger, "synced #{$outboxId}: {$operation} {$table}({$outbox['record_pk']})");
        } catch (Throwable $e) {
            if ($remote->inTransaction()) {
                $remote->rollBack();
            }

            $error = substr($e->getMessage(), 0, 1000);
            $errorStmt = $local->prepare("
                UPDATE sync_outbox
                SET attempt_count = attempt_count + 1,
                    error_message = :error
                WHERE id = :id
            ");
            $errorStmt->execute([
                ':error' => $error,
                ':id' => $outboxId,
            ]);

            $message = "Failed to sync outbox #{$outboxId} ({$operation} {$table}): {$error}";
            $errors[] = $message;
            syncLog($logger, $message);
            if (!$continueOnError) {
                throw new RuntimeException($message);
            }
        }
    }

    if (!empty($affectedTables)) {
        syncResetSerialSequences($remote, array_keys($affectedTables));
    }

    $remaining = syncPendingOutboxCount($local);
    syncLog($logger, "Sync complete. Synced {$synced} row(s). Remaining pending rows: {$remaining}.");

    return [
        'dry_run' => false,
        'pending_before' => $pending,
        'synced' => $synced,
        'remaining' => $remaining,
        'errors' => $errors,
    ];
}
