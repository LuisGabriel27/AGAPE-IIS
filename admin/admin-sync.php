<?php
/**
 * Manual Sync
 * Lets school staff preview and run offline/local DB sync.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin', 'clerk']);
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../database/sync_lib.php';

set_time_limit(300);

$errors = [];
$logs = [];
$resultLabel = '';

$captureLog = static function (string $message) use (&$logs): void {
    $logs[] = $message;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'preview_push') {
            syncPushToSupabaseOperation(true, false, 500, $captureLog);
            $resultLabel = 'Sync preview completed.';
        } elseif ($action === 'push_to_supabase') {
            $result = syncPushToSupabaseOperation(false, false, 500, $captureLog);
            $resultLabel = 'Sync completed. ' . (int)$result['synced'] . ' local change(s) pushed to Supabase.';
            setFlash('success', $resultLabel);
        } elseif ($action === 'pull_snapshot') {
            $result = syncPullSupabaseSnapshotOperation(false, false, $captureLog);
            $copied = array_sum(array_map('intval', $result['copied_counts'] ?? []));
            $resultLabel = 'Local copy refreshed from Supabase. ' . $copied . ' row(s) copied.';
            setFlash('success', $resultLabel);
        } else {
            $errors[] = 'Unknown sync action.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$status = syncStatusSnapshot();
$isOfflineMode = $status['mode'] === 'offline_local';
$pendingChanges = (int)$status['pending_changes'];
$lastSnapshotAt = $status['last_snapshot_at'] ? date('M d, Y g:i A', strtotime((string)$status['last_snapshot_at'])) : 'Not recorded';
$storageLabel = ($status['document_storage_driver'] ?? 'local') === 'supabase'
    ? 'Supabase Storage'
    : 'Local uploads';

$pageTitle = 'Manual Sync';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1"><i class="bi bi-cloud-arrow-up me-2"></i>Offline / Supabase Sync</h2>
        <p class="text-muted mb-0">Push offline work to Supabase and refresh the local Docker copy before offline use.</p>
    </div>
    <span class="badge <?= $isOfflineMode ? 'bg-success' : 'bg-primary' ?> fs-6">
        <?= $isOfflineMode ? 'Offline Local Mode' : 'Online Supabase Mode' ?>
    </span>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($resultLabel !== ''): ?>
    <div class="alert alert-info"><?= e($resultLabel) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon-wrap"><i class="bi bi-database"></i></div>
            <div>
                <div class="kpi-label">Database Mode</div>
                <div class="kpi-value fs-5"><?= e($isOfflineMode ? 'Local' : 'Supabase') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="kpi-label">Pending Local Changes</div>
                <div class="kpi-value"><?= e((string)$pendingChanges) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="kpi-card kpi-success">
            <div class="kpi-icon-wrap"><i class="bi bi-clock-history"></i></div>
            <div>
                <div class="kpi-label">Last Local Refresh</div>
                <div class="kpi-value fs-6"><?= e($lastSnapshotAt) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-light border">
    <strong>Document storage:</strong> <?= e($storageLabel) ?>.
    <?php if (!($status['supabase_storage_configured'] ?? false)): ?>
        Add the Supabase service role key before syncing local enrollment document uploads to Supabase Storage.
    <?php else: ?>
        Supabase Storage credentials are configured for protected document access and file sync.
    <?php endif; ?>
</div>

<?php if (!$isOfflineMode): ?>
    <div class="alert alert-primary">
        The system is currently connected directly to Supabase. Changes are already saved online, so offline sync is not needed in this mode.
    </div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <span><i class="bi bi-cloud-arrow-up-fill"></i>Push Offline Work</span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Use this after the internet comes back. It sends local Docker changes to Supabase.
                    </p>

                    <?php if ($pendingChanges > 0): ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>Table</th>
                                    <th>Action</th>
                                    <th class="text-end">Count</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($status['outbox_summary'] as $row): ?>
                                    <tr>
                                        <td><?= e((string)$row['table_name']) ?></td>
                                        <td><?= e((string)$row['operation']) ?></td>
                                        <td class="text-end"><?= e((string)$row['count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state py-3">
                            <i class="bi bi-check-circle d-block"></i>
                            <p>No local changes waiting to sync.</p>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="preview_push">
                            <button type="submit" class="btn btn-outline-primary" <?= $pendingChanges === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-search me-1"></i>Preview Sync
                            </button>
                        </form>
                        <form method="POST" data-confirm="Push all pending local changes to Supabase now?">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="push_to_supabase">
                            <button type="submit" class="btn btn-primary" <?= $pendingChanges === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-cloud-arrow-up me-1"></i>Sync to Supabase
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <span><i class="bi bi-cloud-arrow-down-fill"></i>Refresh Local Copy</span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Use this before going offline. It copies the latest Supabase records into the local Docker database.
                    </p>
                    <?php if ($pendingChanges > 0): ?>
                        <div class="alert alert-warning py-2">
                            Sync pending local changes first. Refresh is locked to avoid overwriting offline work.
                        </div>
                    <?php endif; ?>
                    <form method="POST" data-confirm="Refresh the local Docker database from Supabase? This replaces the local copy with the online records.">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="pull_snapshot">
                        <button type="submit" class="btn btn-outline-primary" <?= $pendingChanges > 0 ? 'disabled' : '' ?>>
                            <i class="bi bi-cloud-arrow-down me-1"></i>Refresh From Supabase
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card mt-4">
    <div class="card-header">
        <span><i class="bi bi-info-circle-fill"></i>Current Data Check</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($status['counts'] as $table => $count): ?>
                <div class="col-sm-6 col-lg-3">
                    <div class="border rounded-3 p-3 h-100">
                        <div class="text-muted small"><?= e(ucfirst(str_replace('_', ' ', $table))) ?></div>
                        <div class="fw-bold fs-4"><?= e((string)$count) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-muted small mt-3">
            Active database host: <?= e((string)$status['db_host']) ?>
        </div>
    </div>
</div>

<?php if (!empty($logs)): ?>
    <div class="card mt-4">
        <div class="card-header">
            <span><i class="bi bi-terminal-fill"></i>Sync Log</span>
        </div>
        <div class="card-body">
            <pre class="mb-0 sync-log"><?= e(implode(PHP_EOL, $logs)) ?></pre>
        </div>
    </div>
<?php endif; ?>

<style>
.sync-log {
    white-space: pre-wrap;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 8px;
    padding: 1rem;
    max-height: 360px;
    overflow: auto;
    font-size: 0.875rem;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
