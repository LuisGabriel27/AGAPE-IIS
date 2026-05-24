<?php
/**
 * Admin Schedules â€” CRUD: assign subject + section + teacher + room + day/time
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];
$activeYear = currentSchoolYear();
$activeTerm = currentAcademicTerm();

if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $pdo->prepare("DELETE FROM schedules WHERE id = :id")->execute([':id' => $id]);
    auditLog('delete_schedule', 'schedules', $id);
    setFlash('success', 'Schedule deleted.');
    redirect(APP_URL . '/admin/admin-schedule.php');
}

if (in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $data = [
        'subject_id'  => (int)($_POST['subject_id'] ?? 0),
        'section_id'  => (int)($_POST['section_id'] ?? 0),
        'teacher_id'  => (int)($_POST['teacher_id'] ?? 0),
        'room'        => trim($_POST['room'] ?? ''),
        'day_of_week' => trim($_POST['day_of_week'] ?? ''),
        'time_start'  => trim($_POST['time_start'] ?? ''),
        'time_end'    => trim($_POST['time_end'] ?? ''),
        'school_year' => trim($_POST['school_year'] ?? $activeYear),
        'term'        => normalizeAcademicTerm($_POST['term'] ?? $activeTerm),
    ];

    if (!$data['subject_id']) $errors[] = 'Subject is required.';
    if (!$data['section_id']) $errors[] = 'Section is required.';
    if (!$data['teacher_id']) $errors[] = 'Teacher is required.';
    if (empty($data['day_of_week'])) $errors[] = 'Day is required.';
    if (empty($data['time_start']) || empty($data['time_end'])) $errors[] = 'Time range is required.';
    if ($data['subject_id'] && $data['section_id']) {
        $stmt = $pdo->prepare("
            SELECT sub.grade_level AS subject_grade_level, sec.grade_level AS section_grade_level
            FROM subjects sub
            CROSS JOIN sections sec
            WHERE sub.id = :subid AND sec.id = :secid
            LIMIT 1
        ");
        $stmt->execute([':subid' => $data['subject_id'], ':secid' => $data['section_id']]);
        $gradeMatch = $stmt->fetch();
        if ($gradeMatch && !empty($gradeMatch['subject_grade_level']) && $gradeMatch['subject_grade_level'] !== $gradeMatch['section_grade_level']) {
            $errors[] = 'Subject grade level must match the selected section grade level.';
        }
    }

    if (empty($errors)) {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO schedules (subject_id, section_id, teacher_id, room, day_of_week, time_start, time_end, school_year, term) VALUES (:sub,:sec,:tch,:rm,:day,:ts,:te,:sy,:trm) RETURNING id");
            $stmt->execute([':sub'=>$data['subject_id'],':sec'=>$data['section_id'],':tch'=>$data['teacher_id'],':rm'=>$data['room'],':day'=>$data['day_of_week'],':ts'=>$data['time_start'],':te'=>$data['time_end'],':sy'=>$data['school_year'],':trm'=>$data['term']]);
            auditLog('create_schedule', 'schedules', (int)$stmt->fetchColumn());
            setFlash('success', 'Schedule created.');
        } else {
            $stmt = $pdo->prepare("UPDATE schedules SET subject_id=:sub, section_id=:sec, teacher_id=:tch, room=:rm, day_of_week=:day, time_start=:ts, time_end=:te, school_year=:sy, term=:trm WHERE id=:id");
            $stmt->execute([':sub'=>$data['subject_id'],':sec'=>$data['section_id'],':tch'=>$data['teacher_id'],':rm'=>$data['room'],':day'=>$data['day_of_week'],':ts'=>$data['time_start'],':te'=>$data['time_end'],':sy'=>$data['school_year'],':trm'=>$data['term'],':id'=>$id]);
            auditLog('update_schedule', 'schedules', $id);
            setFlash('success', 'Schedule updated.');
        }
        redirect(APP_URL . '/admin/admin-schedule.php');
    }
}

$editSched = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editSched = $stmt->fetch();
}

$subjectsList = $pdo->query("
    SELECT id, code, name, grade_level
    FROM subjects
    ORDER BY CASE grade_level
        WHEN 'Preschool' THEN 0
        WHEN 'Kindergarten' THEN 1
        WHEN '1' THEN 2
        WHEN '2' THEN 3
        WHEN '3' THEN 4
        WHEN '4' THEN 5
        WHEN '5' THEN 6
        WHEN '6' THEN 7
        ELSE 99
    END, name
")->fetchAll();
$sectionsList = $pdo->query("SELECT id, name, grade_level FROM sections ORDER BY grade_level, name")->fetchAll();
$teachersList = $pdo->query("SELECT id, first_name, last_name FROM teachers ORDER BY last_name, first_name")->fetchAll();

$scheduleListParams = [':sy' => $activeYear];
$scheduleListTermClause = academicTermWhereClause('term', 'schedule_list_term', $scheduleListParams, $activeTerm);
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE school_year = :sy AND {$scheduleListTermClause}");
$totalStmt->execute($scheduleListParams);
$total = (int)$totalStmt->fetchColumn();
[$offset, $limit, $page, $totalPages] = paginate($total, 15);

$scheduleRowsParams = [':sy' => $activeYear];
$scheduleRowsTermClause = academicTermWhereClause('sch.term', 'schedule_rows_term', $scheduleRowsParams, $activeTerm);
$stmt = $pdo->prepare("
    SELECT sch.*, sub.name AS subject_name, sub.code AS subject_code,
           sec.name AS section_name, sec.grade_level,
           CASE WHEN t.first_name = '' THEN t.last_name ELSE t.last_name || ', ' || t.first_name END AS teacher_name
    FROM schedules sch
    JOIN subjects sub ON sch.subject_id = sub.id
    JOIN sections sec ON sch.section_id = sec.id
    JOIN teachers t ON sch.teacher_id = t.id
    WHERE sch.school_year = :sy
      AND {$scheduleRowsTermClause}
    ORDER BY CASE sch.day_of_week WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3 WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 END, sch.time_start
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($scheduleRowsParams);
$schedules = $stmt->fetchAll();

$pageTitle = 'Manage Schedules';
require_once __DIR__ . '/../includes/header.php';
$days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h4 class="fw-bold"><i class="bi bi-calendar3 me-2"></i>Class Schedules</h4>
        <div class="text-muted small">Showing active period: <?= activeAcademicPeriodBadge() ?></div>
    </div>
    <div class="col-md-6 text-md-end"><a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Schedule</a></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold"><?= e($action === 'create' ? 'Add Schedule' : 'Edit Schedule') ?></div>
    <div class="card-body">
        <form method="POST" id="schedule-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Subject <span class="text-danger">*</span></label>
                    <select class="form-select" name="subject_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($subjectsList as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= e(($editSched['subject_id'] ?? 0) == $s['id'] ? 'selected' : '') ?>>
                                <?= e(formatGradeLevel($s['grade_level'] ?? '') . ' - ' . $s['code'] . ' - ' . $s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Section <span class="text-danger">*</span></label>
                    <select class="form-select" name="section_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($sectionsList as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= e(($editSched['section_id'] ?? 0) == $s['id'] ? 'selected' : '') ?>><?= e($s['name'] . ' (' . formatGradeLevel((string)$s['grade_level']) . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Teacher <span class="text-danger">*</span></label>
                    <select class="form-select" name="teacher_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($teachersList as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= e(($editSched['teacher_id'] ?? 0) == $t['id'] ? 'selected' : '') ?>><?= e(format_name($t['first_name'], $t['last_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Day <span class="text-danger">*</span></label>
                    <select class="form-select" name="day_of_week" required>
                        <?php foreach ($days as $d): ?>
                            <option value="<?= e($d) ?>" <?= e(($editSched['day_of_week'] ?? '') === $d ? 'selected' : '') ?>><?= e($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Start Time <span class="text-danger">*</span></label>
                    <input type="time" class="form-control" name="time_start" value="<?= e($editSched['time_start'] ?? '08:00') ?>" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">End Time <span class="text-danger">*</span></label>
                    <input type="time" class="form-control" name="time_end" value="<?= e($editSched['time_end'] ?? '09:00') ?>" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Room</label>
                    <input type="text" class="form-control" name="room" value="<?= e($editSched['room'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">School Year</label>
                    <input type="text" class="form-control" name="school_year" value="<?= e($editSched['school_year'] ?? $activeYear) ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Quarter</label>
                    <select class="form-select" name="term">
                        <?php foreach (academicTermOptions() as $termValue => $termLabel): ?>
                            <option value="<?= e($termValue) ?>" <?= normalizeAcademicTerm($editSched['term'] ?? $activeTerm) === $termValue ? 'selected' : '' ?>><?= e($termLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
            <a href="<?= APP_URL ?>/admin/admin-schedule.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="schedule-table">
        <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Section</th><th>Teacher</th><th>Room</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($schedules)): ?>
                <?= emptyStateRow(7, 'No class schedules for the active school year.', 'Add schedule entries using the form above. Guardians and teachers will see timetables only after schedules exist for the active school year.', 'bi-calendar-week') ?>
            <?php else: foreach ($schedules as $s): ?>
            <tr>
                <td><?= e($s['day_of_week']) ?></td>
                <td><?= e(date('g:i A', strtotime($s['time_start']))) ?> - <?= e(date('g:i A', strtotime($s['time_end']))) ?></td>
                <td><span class="badge bg-secondary"><?= e($s['subject_code']) ?></span> <?= e($s['subject_name']) ?></td>
                <td><?= e($s['section_name']) ?> (<?= e(formatGradeLevel((string)$s['grade_level'])) ?>)</td>
                <td><?= e($s['teacher_name']) ?></td>
                <td><?= e($s['room'] ?? 'TBD') ?></td>
                <td>
                    <a href="?action=edit&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit schedule entry" aria-label="Edit schedule entry"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="?action=delete&id=<?= (int)$s['id'] ?>" class="d-inline" data-confirm="Delete this schedule entry? This cannot be undone." data-confirm-variant="danger">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Delete schedule entry" aria-label="Delete schedule entry"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?x=1') ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

