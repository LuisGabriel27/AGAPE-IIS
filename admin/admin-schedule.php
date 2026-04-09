<?php
/**
 * Admin Schedules — CRUD: assign subject + section + teacher + room + day/time
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
        'school_year' => trim($_POST['school_year'] ?? currentSchoolYear()),
        'term'        => trim($_POST['term'] ?? '1st Semester'),
    ];

    if (!$data['subject_id']) $errors[] = 'Subject is required.';
    if (!$data['section_id']) $errors[] = 'Section is required.';
    if (!$data['teacher_id']) $errors[] = 'Teacher is required.';
    if (empty($data['day_of_week'])) $errors[] = 'Day is required.';
    if (empty($data['time_start']) || empty($data['time_end'])) $errors[] = 'Time range is required.';

    if (empty($errors)) {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO schedules (subject_id, section_id, teacher_id, room, day_of_week, time_start, time_end, school_year, term) VALUES (:sub,:sec,:tch,:rm,:day,:ts,:te,:sy,:trm)");
            $stmt->execute([':sub'=>$data['subject_id'],':sec'=>$data['section_id'],':tch'=>$data['teacher_id'],':rm'=>$data['room'],':day'=>$data['day_of_week'],':ts'=>$data['time_start'],':te'=>$data['time_end'],':sy'=>$data['school_year'],':trm'=>$data['term']]);
            auditLog('create_schedule', 'schedules', (int)$pdo->lastInsertId());
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

$subjectsList = $pdo->query("SELECT id, code, name FROM subjects ORDER BY name")->fetchAll();
$sectionsList = $pdo->query("SELECT id, name, grade_level FROM sections ORDER BY grade_level, name")->fetchAll();
$teachersList = $pdo->query("SELECT id, full_name FROM teachers ORDER BY full_name")->fetchAll();

$total = $pdo->query("SELECT COUNT(*) FROM schedules")->fetchColumn();
[$offset, $limit, $page, $totalPages] = paginate($total, 15);

$stmt = $pdo->query("
    SELECT sch.*, sub.name AS subject_name, sub.code AS subject_code,
           sec.name AS section_name, sec.grade_level, t.full_name AS teacher_name
    FROM schedules sch
    JOIN subjects sub ON sch.subject_id = sub.id
    JOIN sections sec ON sch.section_id = sec.id
    JOIN teachers t ON sch.teacher_id = t.id
    ORDER BY FIELD(sch.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday'), sch.time_start
    LIMIT {$limit} OFFSET {$offset}
");
$schedules = $stmt->fetchAll();

$pageTitle = 'Manage Schedules';
require_once __DIR__ . '/../includes/header.php';
$days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
?>

<div class="row mb-4">
    <div class="col-md-6"><h4 class="fw-bold"><i class="bi bi-calendar3 me-2"></i>Class Schedules</h4></div>
    <div class="col-md-6 text-md-end"><a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Schedule</a></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold"><?= $action === 'create' ? 'Add Schedule' : 'Edit Schedule' ?></div>
    <div class="card-body">
        <form method="POST" id="schedule-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Subject <span class="text-danger">*</span></label>
                    <select class="form-select" name="subject_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($subjectsList as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($editSched['subject_id'] ?? 0) == $s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' — ' . $s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Section <span class="text-danger">*</span></label>
                    <select class="form-select" name="section_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($sectionsList as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($editSched['section_id'] ?? 0) == $s['id'] ? 'selected' : '' ?>><?= e($s['name'] . ' (Gr. ' . $s['grade_level'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Teacher <span class="text-danger">*</span></label>
                    <select class="form-select" name="teacher_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($teachersList as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($editSched['teacher_id'] ?? 0) == $t['id'] ? 'selected' : '' ?>><?= e($t['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Day <span class="text-danger">*</span></label>
                    <select class="form-select" name="day_of_week" required>
                        <?php foreach ($days as $d): ?>
                            <option value="<?= $d ?>" <?= ($editSched['day_of_week'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
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
                    <input type="text" class="form-control" name="school_year" value="<?= e($editSched['school_year'] ?? currentSchoolYear()) ?>">
                </div>
            </div>
            <input type="hidden" name="term" value="<?= e($editSched['term'] ?? '1st Semester') ?>">
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
                <tr><td colspan="7" class="text-center text-muted py-3">No schedules found.</td></tr>
            <?php else: foreach ($schedules as $s): ?>
            <tr>
                <td><?= e($s['day_of_week']) ?></td>
                <td><?= e(date('g:i A', strtotime($s['time_start']))) ?> – <?= e(date('g:i A', strtotime($s['time_end']))) ?></td>
                <td><span class="badge bg-secondary"><?= e($s['subject_code']) ?></span> <?= e($s['subject_name']) ?></td>
                <td><?= e($s['section_name']) ?> (Gr. <?= e($s['grade_level']) ?>)</td>
                <td><?= e($s['teacher_name']) ?></td>
                <td><?= e($s['room'] ?? 'TBD') ?></td>
                <td>
                    <a href="?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="?action=delete&id=<?= $s['id'] ?>" class="d-inline" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?x=1') ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
