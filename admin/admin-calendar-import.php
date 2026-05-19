<?php
/**
 * Admin DepEd Calendar Import
 * Two-step import of official DepEd Philippines school calendar events.
 * Idempotent: importing same SY twice is blocked.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$errors = [];
$imported = false;
$preview = false;
$alreadyImported = false;

// Generate SY options: current year ±2
$currentYear = (int)date('Y');
$syOptions = [];
for ($y = $currentYear - 2; $y <= $currentYear + 2; $y++) {
    $syOptions[] = $y . '-' . ($y + 1);
}

$selectedSY = $_POST['school_year'] ?? $_GET['school_year'] ?? '';
$step       = $_POST['step'] ?? '';

// ── Step 1: Preview ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'preview') {
    validateCsrf();

    if (empty($selectedSY) || !in_array($selectedSY, $syOptions)) {
        $errors[] = 'Please select a valid school year.';
    }

    if (empty($errors)) {
        // Check if already imported
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM calendar_events WHERE source = 'deped' AND school_year = :sy");
        $stmt->execute([':sy' => $selectedSY]);
        $existingCount = (int)$stmt->fetchColumn();

        if ($existingCount > 0) {
            $alreadyImported = true;
        } else {
            $preview = true;
        }
    }
}

// ── Step 2: Confirm Import ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'confirm') {
    validateCsrf();

    if (empty($selectedSY) || !in_array($selectedSY, $syOptions)) {
        $errors[] = 'Invalid school year.';
    }

    if (empty($errors)) {
        // Double-check idempotency
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM calendar_events WHERE source = 'deped' AND school_year = :sy");
        $stmt->execute([':sy' => $selectedSY]);
        $existingCount = (int)$stmt->fetchColumn();

        if ($existingCount > 0) {
            $alreadyImported = true;
        } else {
            $events = generateSchoolYearCalendar($selectedSY);

            $insertStmt = $pdo->prepare("
                INSERT INTO calendar_events (title, date_start, date_end, type, description, created_by, source, school_year)
                VALUES (:title, :ds, :de, :type, :desc, NULL, 'deped', :sy)
            ");

            $pdo->beginTransaction();
            try {
                foreach ($events as $ev) {
                    $insertStmt->execute([
                        ':title' => $ev['title'],
                        ':ds'    => $ev['date_start'],
                        ':de'    => $ev['date_end'],
                        ':type'  => $ev['type'],
                        ':desc'  => $ev['description'],
                        ':sy'    => $selectedSY,
                    ]);
                }
                $pdo->commit();
                auditLog('deped_calendar_import', 'calendar_events', null, null, ['school_year' => $selectedSY, 'count' => count($events)]);
                $imported = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('DepEd calendar import error: ' . $e->getMessage());
                $errors[] = 'An error occurred during import. Please try again.';
            }
        }
    }
}

// Get preview data if needed
$previewEvents = [];
if ($preview && !empty($selectedSY)) {
    $previewEvents = generateSchoolYearCalendar($selectedSY);
}

$pageTitle = 'Import DepEd Calendar';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-cloud-download me-2"></i>Import DepEd Calendar</h4>
                <p class="text-muted mb-0">Pre-load official DepEd Philippines school calendar events for an academic year.</p>
            </div>
            <a href="<?= APP_URL ?>/admin/admin-calendar.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Calendar
            </a>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($imported): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill me-2"></i>
        DepEd calendar events for SY <strong><?= e($selectedSY) ?></strong> have been successfully imported!
        <a href="<?= APP_URL ?>/admin/admin-calendar.php" class="alert-link ms-2">View Calendar →</a>
    </div>
<?php endif; ?>

<?php if ($alreadyImported): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        DepEd calendar for SY <strong><?= e($selectedSY) ?></strong> is already imported. No duplicate entries were created.
    </div>
<?php endif; ?>

<?php if (!$imported): ?>
<!-- Step 1: School Year Selection -->
<div class="card mb-4">
    <div class="card-header">
        <span><i class="bi bi-calendar-plus-fill"></i>Select School Year</span>
    </div>
    <div class="card-body">
        <form method="POST" id="import-preview-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="step" value="preview">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">School Year</label>
                    <select class="form-select" name="school_year" required>
                        <option value="">-- Select SY --</option>
                        <?php foreach ($syOptions as $sy): ?>
                            <option value="<?= e($sy) ?>" <?= e($selectedSY === $sy ? 'selected' : '') ?>><?= e($sy) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-eye me-1"></i>Preview Events
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($preview && !empty($previewEvents)): ?>
<!-- Step 2: Preview & Confirm -->
<div class="card mb-4">
    <div class="card-header">
        <span><i class="bi bi-list-check"></i>Preview — SY <?= e($selectedSY) ?> (<?= e((string)count($previewEvents)) ?> events)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="preview-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Event Title</th>
                        <th>Date Range</th>
                        <th>Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewEvents as $idx => $ev):
                        $typeColorMap = [
                            'event'   => 'badge-status-enrolled',
                            'holiday' => 'badge-status-rejected',
                            'exam'    => 'badge-status-pending',
                            'other'   => 'badge-status-active',
                        ];
                        $badge = $typeColorMap[$ev['type']] ?? 'badge-status-inactive';
                    ?>
                    <tr>
                        <td><?= e((string)($idx + 1)) ?></td>
                        <td class="fw-semibold"><?= e($ev['title']) ?></td>
                        <td>
                            <small>
                                <i class="bi bi-calendar3 me-1 text-muted"></i>
                                <?= e(date('M d, Y', strtotime($ev['date_start']))) ?>
                                <?php if ($ev['date_start'] !== $ev['date_end']): ?>
                                    — <?= e(date('M d, Y', strtotime($ev['date_end']))) ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td><span class="badge <?= e($badge) ?>"><?= e(ucfirst($ev['type'])) ?></span></td>
                        <td><small class="text-muted"><?= e(mb_substr($ev['description'], 0, 60)) ?><?= strlen($ev['description']) > 60 ? '…' : '' ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        <form method="POST" id="import-confirm-form" class="d-flex justify-content-end gap-2">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="step" value="confirm">
            <input type="hidden" name="school_year" value="<?= e($selectedSY) ?>">
            <a href="<?= APP_URL ?>/admin/admin-calendar-import.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-success" data-confirm="Import <?= e((string)count($previewEvents)) ?> DepEd events for SY <?= e($selectedSY) ?>?" data-confirm-variant="primary">
                <i class="bi bi-cloud-download me-1"></i>Confirm Import
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
