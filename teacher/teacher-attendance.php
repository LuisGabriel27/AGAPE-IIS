<?php
/**
 * Teacher Attendance Quick-Mark
 * Select a section, pick a date, and mark Present/Absent/Late for each student.
 * Teachers can only mark attendance for their assigned sections.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('teacher');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];
$errors = [];

// Get teacher record
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    setFlash('danger', 'Teacher profile not found.');
    redirect(APP_URL . '/teacher/teacher-dashboard.php');
}

// Get assigned sections (only sections this teacher teaches)
$stmt = $pdo->prepare("
    SELECT DISTINCT sec.id, sec.name, sec.grade_level
    FROM schedules sch
    JOIN sections sec ON sch.section_id = sec.id
    WHERE sch.teacher_id = :tid
    ORDER BY sec.grade_level, sec.name
");
$stmt->execute([':tid' => $teacher['id']]);
$sections = $stmt->fetchAll();

$selSection = (int)($_GET['section_id'] ?? $_POST['section_id'] ?? ($sections[0]['id'] ?? 0));
$selDate    = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');

// Verify teacher has access to this section
$hasAccess = false;
$currentSection = null;
foreach ($sections as $sec) {
    if ($sec['id'] == $selSection) {
        $hasAccess = true;
        $currentSection = $sec;
        break;
    }
}

// Get enrolled students for selected section
$students = [];
$existingRecords = [];
if ($hasAccess && $selSection) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.lrn
        FROM students s
        WHERE s.section_id = :secid
        ORDER BY s.full_name
    ");
    $stmt->execute([':secid' => $selSection]);
    $students = $stmt->fetchAll();

    // Check for existing attendance records
    if (!empty($students)) {
        $studentIds = array_column($students, 'id');
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id, attendance_status, id AS record_id
            FROM attendance_logs
            WHERE student_id IN ({$placeholders}) AND attendance_date = ?
        ");
        $params = array_merge($studentIds, [$selDate]);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $existingRecords[$r['student_id']] = $r;
        }
    }
}

$hasExisting = !empty($existingRecords);

// ── Handle Attendance Submission ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasAccess) {
    validateCsrf();

    $statuses   = $_POST['status'] ?? [];
    $studentIds = $_POST['student_ids'] ?? [];
    $postDate   = $_POST['date'] ?? date('Y-m-d');
    $postSection = (int)($_POST['section_id'] ?? 0);

    // Re-verify teacher has access
    $accessCheck = false;
    foreach ($sections as $sec) {
        if ($sec['id'] == $postSection) {
            $accessCheck = true;
            break;
        }
    }

    if (!$accessCheck) {
        $errors[] = 'You do not have access to this section.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            foreach ($studentIds as $idx => $studentId) {
                $studentId = (int)$studentId;
                $status = $statuses[$idx] ?? 'present';
                if (!in_array($status, ['present', 'absent', 'late'])) {
                    $status = 'present';
                }

                // Check if record exists
                $stmt = $pdo->prepare("
                    SELECT id FROM attendance_logs
                    WHERE student_id = :sid AND attendance_date = :d
                    LIMIT 1
                ");
                $stmt->execute([':sid' => $studentId, ':d' => $postDate]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // UPDATE existing record
                    $stmt = $pdo->prepare("
                        UPDATE attendance_logs
                        SET attendance_status = :status, method = 'manual', marked_by = :uid, marked_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([':status' => $status, ':uid' => $_SESSION['user_id'], ':id' => $existing['id']]);
                } else {
                    // INSERT new record
                    $stmt = $pdo->prepare("
                        INSERT INTO attendance_logs (student_id, attendance_date, attendance_status, method, marked_by, marked_at)
                        VALUES (:sid, :d, :status, 'manual', :uid, NOW())
                    ");
                    $stmt->execute([':sid' => $studentId, ':d' => $postDate, ':status' => $status, ':uid' => $_SESSION['user_id']]);
                }
            }

            $pdo->commit();
            auditLog('attendance_marked', 'attendance_logs', $postSection, null, [
                'section_id' => $postSection,
                'date' => $postDate,
                'count' => count($studentIds),
            ]);
            setFlash('success', 'Attendance recorded successfully for ' . count($studentIds) . ' students.');
            redirect(APP_URL . '/teacher/teacher-attendance.php?section_id=' . $postSection . '&date=' . urlencode($postDate));
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Attendance save error: ' . $e->getMessage());
            $errors[] = 'An error occurred while saving attendance. Please try again.';
        }
    }
}

$pageTitle = 'Mark Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-clipboard-check me-2"></i>Quick-Mark Attendance</h4>
        <p class="text-muted">Select a section and date to record attendance for your students.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<!-- Section Tabs & Date Picker -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label fw-semibold">Section</label>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($sections as $sec): ?>
                        <a href="?section_id=<?= (int)$sec['id'] ?>&date=<?= e(urlencode($selDate)) ?>"
                           class="btn <?= $selSection == $sec['id'] ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">
                            <?= e($sec['name']) ?> (Grade <?= e($sec['grade_level']) ?>)
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($sections)): ?>
                        <span class="text-muted">No sections assigned to you.</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Date</label>
                <form method="GET" class="d-flex gap-2">
                    <input type="hidden" name="section_id" value="<?= (int)$selSection ?>">
                    <input type="date" class="form-control" name="date" value="<?= e($selDate) ?>" onchange="this.form.submit()">
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($hasAccess && !empty($students)): ?>

<?php if ($hasExisting): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle-fill me-2"></i>
        Attendance records already exist for this section on <strong><?= e(date('M d, Y', strtotime($selDate))) ?></strong>.
        You can update the records below.
    </div>
<?php endif; ?>

<!-- Attendance Form -->
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-list-check me-2"></i>
            <?= e($currentSection['name'] ?? '') ?> (Grade <?= e($currentSection['grade_level'] ?? '') ?>) — <?= e(date('M d, Y', strtotime($selDate))) ?>
        </span>
        <div class="d-flex gap-2">
            <span class="badge bg-secondary"><?= e((string)count($students)) ?> students</span>
            <button type="button" class="btn btn-sm btn-outline-success" onclick="markAll('present')">All Present</button>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="markAll('absent')">All Absent</button>
        </div>
    </div>
    <div class="card-body p-0">
        <form method="POST" id="attendance-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section_id" value="<?= (int)$selSection ?>">
            <input type="hidden" name="date" value="<?= e($selDate) ?>">

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>LRN</th>
                            <th class="text-center" style="width: 30%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $idx => $stu):
                            $existing = $existingRecords[$stu['id']] ?? null;
                            $currentStatus = $existing ? $existing['attendance_status'] : 'present';
                        ?>
                        <tr>
                            <td><?= e((string)($idx + 1)) ?></td>
                            <td class="fw-semibold"><?= e($stu['full_name']) ?></td>
                            <td><small class="text-muted"><?= e($stu['lrn'] ?? 'N/A') ?></small></td>
                            <td>
                                <input type="hidden" name="student_ids[]" value="<?= (int)$stu['id'] ?>">
                                <div class="d-flex justify-content-center gap-3">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input attendance-radio" type="radio"
                                               name="status[<?= (int)$idx ?>]" value="present"
                                               id="present_<?= (int)$idx ?>"
                                               <?= $currentStatus === 'present' ? 'checked' : '' ?>>
                                        <label class="form-check-label text-success fw-semibold" for="present_<?= (int)$idx ?>">
                                            <i class="bi bi-check-circle"></i> Present
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input attendance-radio" type="radio"
                                               name="status[<?= (int)$idx ?>]" value="absent"
                                               id="absent_<?= (int)$idx ?>"
                                               <?= $currentStatus === 'absent' ? 'checked' : '' ?>>
                                        <label class="form-check-label text-danger fw-semibold" for="absent_<?= (int)$idx ?>">
                                            <i class="bi bi-x-circle"></i> Absent
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input attendance-radio" type="radio"
                                               name="status[<?= (int)$idx ?>]" value="late"
                                               id="late_<?= (int)$idx ?>"
                                               <?= $currentStatus === 'late' ? 'checked' : '' ?>>
                                        <label class="form-check-label text-warning fw-semibold" for="late_<?= (int)$idx ?>">
                                            <i class="bi bi-clock"></i> Late
                                        </label>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i><?= $hasExisting ? 'Update Attendance' : 'Save Attendance' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function markAll(status) {
    document.querySelectorAll('input.attendance-radio[value="' + status + '"]').forEach(r => r.checked = true);
}
</script>

<?php elseif ($hasAccess): ?>
    <div class="alert alert-info">No students found in this section.</div>
<?php elseif (!empty($sections)): ?>
    <div class="alert alert-info">Please select a section above to mark attendance.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
