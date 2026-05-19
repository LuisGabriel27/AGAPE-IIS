<?php
/**
 * Push local Docker database changes to Supabase.
 *
 * Reads sync_outbox rows created by local database triggers, applies them to
 * Supabase, and marks each row as synced after the remote write succeeds.
 */

require_once __DIR__ . '/sync_lib.php';

syncRequireCli();

$dryRun = syncHasArg('--dry-run');
$continueOnError = syncHasArg('--continue-on-error');
$limit = max(1, (int)syncArgValue('--limit', '500'));

try {
    syncPushToSupabaseOperation(
        $dryRun,
        $continueOnError,
        $limit,
        static function (string $message): void {
            syncOut($message);
        }
    );

    if ($dryRun) {
        syncOut('Dry run only. No Supabase data changed.');
    }
} catch (Throwable $e) {
    syncFail($e->getMessage());
}
