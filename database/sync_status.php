<?php
/**
 * Show whether the Docker app is currently using local/offline DB or Supabase,
 * plus pending local sync work if the app DB is local.
 */

require_once __DIR__ . '/sync_lib.php';

syncRequireCli();

$status = syncStatusSnapshot();

syncOut('AGAPE AIIS sync status');
syncOut('Mode: ' . $status['mode']);
syncOut('DB_HOST: ' . $status['db_host']);
syncOut('DB_NAME: ' . $status['db_name']);
syncOut('SUPABASE_DB_HOST configured: ' . ($status['supabase_db_host_configured'] ? 'yes' : 'no'));

if ($status['mode'] === 'online_supabase') {
    syncOut('The app is reading/writing Supabase directly. This is online mode, not offline mode.');
    exit(0);
}

syncOut('Pending local changes: ' . $status['pending_changes']);

if (!empty($status['outbox_summary'])) {
    foreach ($status['outbox_summary'] as $row) {
        syncOut(str_pad($row['table_name'] . ' ' . $row['operation'], 42) . $row['count']);
    }
}

foreach ($status['counts'] as $table => $count) {
    syncOut(str_pad($table, 24) . $count . ' row(s)');
}
