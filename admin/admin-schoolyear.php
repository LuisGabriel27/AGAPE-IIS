<?php
/**
 * Admin School Year Management
 * View current SY, summary stats, close/reset school year.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$errors = [];

$currentSY = currentSchoolYear();
$currentTerm = currentAcademicTerm();

// Calculate next SY
$parts = explode('-', $currentSY);
$nextSY = ((int)$parts[0] + 1) . '-' . ((int)$parts[1] + 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_period') {
    validateCsrf();

    $newSchoolYear = trim((string)($_POST['active_school_year'] ?? ''));
    $newTerm = normalizeAcademicTerm($_POST['active_term'] ?? '');

    if (!preg_match('/^\d{4}-\d{4}$/', $newSchoolYear)) {
        $errors[] = 'School year must use the format YYYY-YYYY.';
    } else {
        [$startYear, $endYear] = array_map('intval', explode('-', $newSchoolYear));
        if ($endYear !== $startYear + 1) {
            $errors[] = 'School year end must be exactly one year after the start year.';
        }
    }

    if (empty($errors)) {
        try {
            setSettingValue('active_school_year', $newSchoolYear);
            setSettingValue('active_term', $newTerm);
            clearAcademicPeriodCache();

            auditLog('academic_period_updated', 'settings', null, [
                'school_year' => $currentSY,
                'term' => $currentTerm,
            ], [
                'school_year' => $newSchoolYear,
                'term' => $newTerm,
            ]);

            setFlash('success', 'Active academic period updated to ' . $newSchoolYear . ' / ' . $newTerm . '.');
            redirect(APP_URL . '/admin/admin-schoolyear.php');
        } catch (Throwable $e) {
            logException($e, 'Active academic period update failed.', [
                'school_year' => $newSchoolYear,
                'term' => $newTerm,
            ]);
            $errors[] = safeErrorMessage('Could not update the active academic period.');
        }
    }
}

// ── Handle School Year Reset ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_sy') {
    validateCsrf();

    $confirmation = trim($_POST['confirmation'] ?? '');
    $clearAttendance = !empty($_POST['clear_attendance']);

    if ($confirmation !== 'CONFIRM') {
        $errors[] = 'Please type CONFIRM exactly to proceed.';
    }

    if (empty($errors)) {
        $oldSY = $currentSY;

        try {
            $pdo->beginTransaction();

            // a. Update settings to next SY and reset term.
            setSettingValue('active_school_year', $nextSY);
            setSettingValue('active_term', '1st Quarter');

            // b. Archive enrollments for old SY
            $stmt = $pdo->prepare("UPDATE enrollments SET status = 'archived' WHERE school_year = :sy AND status != 'archived'");
            $stmt->execute([':sy' => $oldSY]);

            // c. Delete DepEd calendar events for old SY (manual events are NEVER deleted)
            $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE source = 'deped' AND school_year = :sy");
            $stmt->execute([':sy' => $oldSY]);

            // d. Optionally clear old attendance logs
            if ($clearAttendance) {
                // Delete attendance logs where the date falls within the old school year
                $oldStartYear = (int)$parts[0];
                $oldEndYear = (int)$parts[1];
                $stmt = $pdo->prepare("DELETE FROM attendance_logs WHERE attendance_date < :cutoff");
                $stmt->execute([':cutoff' => $oldEndYear . '-06-01']);
            }

            $pdo->commit();

            // Clear cached school year
            clearAcademicPeriodCache();

            // e. Audit log
            auditLog('school_year_reset', 'settings', null, ['school_year' => $oldSY, 'term' => $currentTerm], ['school_year' => $nextSY, 'term' => '1st Quarter']);

            setFlash('success', 'School year has been reset from ' . $oldSY . ' to ' . $nextSY . ' successfully.');
            redirect(APP_URL . '/admin/admin-schoolyear.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            logException($e, 'School year reset failed.', ['old_school_year' => $oldSY, 'next_school_year' => $nextSY]);
            $errors[] = safeErrorMessage('An error occurred during school year reset.');
        }
    }
}

// ── Summary Statistics ──────────────────────────────────
$enrolledCount = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE school_year = :sy AND status = 'enrolled'");
$enrolledCount->execute([':sy' => $currentSY]);
$enrolledCount = (int)$enrolledCount->fetchColumn();

$activeTeachers = (int)$pdo->query("SELECT COUNT(*) FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.is_active = 1")->fetchColumn();

$pendingEnroll = $pdo->prepare("
    SELECT COUNT(*) FROM enrollments
    WHERE school_year = :sy
      AND status IN (
          'submitted', 'requirements_incomplete', 'documents_under_review',
          'assessed_for_payment', 'awaiting_payment', 'paid_for_registrar', 'returned',
          'pending', 'approved', 'rejected'
      )
");
$pendingEnroll->execute([':sy' => $currentSY]);
$pendingEnroll = (int)$pendingEnroll->fetchColumn();

$depedEventsCount = $pdo->prepare("SELECT COUNT(*) FROM calendar_events WHERE source = 'deped' AND school_year = :sy");
$depedEventsCount->execute([':sy' => $currentSY]);
$depedEventsCount = (int)$depedEventsCount->fetchColumn();

$activeScheduleParams = [':sy' => $currentSY];
$activeScheduleTermClause = academicTermWhereClause('term', 'active_schedule_term', $activeScheduleParams, $currentTerm);
$activeScheduleCountStmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE school_year = :sy AND {$activeScheduleTermClause}");
$activeScheduleCountStmt->execute($activeScheduleParams);
$activeScheduleCount = (int)$activeScheduleCountStmt->fetchColumn();

$pageTitle = 'School Year Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-calendar-range me-2"></i>School Year Management</h4>
        <p class="text-muted">Manage the active school year, view statistics, and perform year-end transitions.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<!-- Current School Year Display -->
<div class="card mb-4">
    <div class="card-body text-center py-5">
        <div class="text-muted text-uppercase small fw-bold mb-2">Active School Year</div>
        <h1 class="display-4 fw-bold" style="color: var(--primary);"><?= e($currentSY) ?></h1>
        <div class="fs-5 fw-semibold text-secondary mb-2"><?= e($currentTerm) ?></div>
        <p class="text-secondary mt-2 mb-0">Schedules, grades, enrollment queues, and teacher views use this active academic period by default.</p>
    </div>
</div>

<?php if ($activeScheduleCount === 0): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>No schedules exist for <?= e(formatAcademicPeriod($currentSY, $currentTerm)) ?>.</strong>
            Teachers and guardians will see empty schedule pages until the active period has schedule entries.
            <a href="<?= APP_URL ?>/admin/admin-schedule.php" class="alert-link">Add schedules now</a>.
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-white fw-bold">
        <i class="bi bi-calendar-check me-2"></i>Set Active Academic Period
    </div>
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="update_period">
            <div class="col-md-4">
                <label class="form-label" for="active_school_year">School Year</label>
                <input type="text" class="form-control" id="active_school_year" name="active_school_year" value="<?= e($currentSY) ?>" pattern="\d{4}-\d{4}" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="active_term">Quarter</label>
                <select class="form-select" id="active_term" name="active_term">
                    <?php foreach (academicTermOptions() as $termValue => $termLabel): ?>
                        <option value="<?= e($termValue) ?>" <?= $currentTerm === $termValue ? 'selected' : '' ?>><?= e($termLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-save me-1"></i>Save Active Period
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon-wrap"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="kpi-label">Enrolled Students</div>
                <div class="kpi-value"><?= e(number_format($enrolledCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="kpi-card kpi-success">
            <div class="kpi-icon-wrap"><i class="bi bi-person-workspace"></i></div>
            <div>
                <div class="kpi-label">Active Teachers</div>
                <div class="kpi-value"><?= e(number_format($activeTeachers)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="kpi-label">Pending Enrollments</div>
                <div class="kpi-value"><?= e(number_format($pendingEnroll)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="kpi-card kpi-info">
            <div class="kpi-icon-wrap"><i class="bi bi-calendar-event-fill"></i></div>
            <div>
                <div class="kpi-label">DepEd Events</div>
                <div class="kpi-value"><?= e(number_format($depedEventsCount)) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- School Year Reset Section -->
<div class="card border-danger">
    <div class="card-header" style="background: var(--danger-light); border-bottom-color: #FECACA;">
        <span style="color: var(--danger-dark);"><i class="bi bi-exclamation-triangle-fill"></i>Close School Year & Start New Year</span>
    </div>
    <div class="card-body">
        <div class="alert alert-warning mb-4">
            <i class="bi bi-info-circle-fill me-2"></i>
            <strong>This action will:</strong>
            <ul class="mb-0 mt-2">
                <li>Change the active school year from <strong><?= e($currentSY) ?></strong> to <strong><?= e($nextSY) ?></strong></li>
                <li>Archive all enrollments for SY <?= e($currentSY) ?></li>
                <li>Remove imported DepEd calendar events for SY <?= e($currentSY) ?> (manual events are kept)</li>
                <li>Optionally clear face attendance logs from the closed school year</li>
            </ul>
        </div>

        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#confirmResetModal">
            <i class="bi bi-arrow-repeat me-1"></i>Close SY <?= e($currentSY) ?> & Start <?= e($nextSY) ?>
        </button>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmResetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm School Year Reset</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="reset-sy-form">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="reset_sy">

                    <p>You are about to close <strong>SY <?= e($currentSY) ?></strong> and transition to <strong>SY <?= e($nextSY) ?></strong>.</p>
                    <p class="text-danger fw-bold">This action cannot be undone.</p>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="clear_attendance" id="clearAttendance" value="1">
                        <label class="form-check-label" for="clearAttendance">
                            Also clear face attendance logs older than SY <?= e($currentSY) ?>
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Type <span class="text-danger">CONFIRM</span> to proceed:</label>
                        <input type="text" class="form-control" name="confirmation" id="confirmInput" placeholder="Type CONFIRM" required autocomplete="off">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="confirmResetBtn" disabled>
                        <i class="bi bi-arrow-repeat me-1"></i>Reset School Year
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('confirmInput').addEventListener('input', function() {
    document.getElementById('confirmResetBtn').disabled = (this.value !== 'CONFIRM');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
