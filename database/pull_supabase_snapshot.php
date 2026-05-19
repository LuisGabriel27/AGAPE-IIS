<?php
/**
 * Copy the current Supabase database data into the local Docker database.
 *
 * Use this while internet is available, before switching the school into an
 * offline work period. Existing local data is replaced by the Supabase copy.
 */

require_once __DIR__ . '/sync_lib.php';

syncRequireCli();

$force = syncHasArg('--force');
$dryRun = syncHasArg('--dry-run');

try {
    $logs = [];
    syncPullSupabaseSnapshotOperation($force, $dryRun, static function (string $message) use (&$logs): void {
        $logs[] = $message;
        syncOut($message);
    });

    if ($dryRun) {
        syncOut('Dry run only. No local data changed.');
    }
} catch (Throwable $e) {
    syncFail($e->getMessage());
}
