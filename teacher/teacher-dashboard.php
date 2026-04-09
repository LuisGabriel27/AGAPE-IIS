<?php
/**
 * Teacher Dashboard
 * Shows assigned subjects and sections for the current term.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('teacher');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

// Get teacher record
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$teacher = $stmt->fetch();

// Get assigned schedules with subject & section info
$assignments = [];
if ($teacher) {
    $stmt = $pdo->prepare("
        SELECT sch.*, sub.name AS subject_name, sub.code AS subject_code, sub.units,
               sec.name AS section_name, sec.grade_level
        FROM schedules sch
        JOIN subjects sub ON sch.subject_id = sub.id
        JOIN sections sec ON sch.section_id = sec.id
        WHERE sch.teacher_id = :tid
        ORDER BY sub.name, sec.name
    ");
    $stmt->execute([':tid' => $teacher['id']]);
    $assignments = $stmt->fetchAll();
}

// Unique subjects + sections
$uniqueSubjects = [];
foreach ($assignments as $a) {
    $key = $a['subject_id'] . '-' . $a['section_id'];
    if (!isset($uniqueSubjects[$key])) {
        $uniqueSubjects[$key] = $a;
    }
}

// Get upcoming calendar events
$stmt = $pdo->prepare("
    SELECT * FROM calendar_events 
    WHERE date_end >= CURDATE() 
    ORDER BY date_start 
    LIMIT 5
");
$stmt->execute();
$upcomingEvents = $stmt->fetchAll();

$pageTitle = 'Teacher Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-speedometer2 me-2"></i>Teacher Dashboard</h4>
        <p class="text-muted">Welcome, <?= e($teacher['full_name'] ?? 'Teacher') ?>!</p>
    </div>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-6">
        <div class="kpi-card bg-gradient-primary">
            <div class="kpi-icon"><i class="bi bi-book"></i></div>
            <div class="kpi-label">Subjects</div>
            <div class="kpi-value"><?= count(array_unique(array_column($assignments, 'subject_id'))) ?></div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="kpi-card bg-gradient-success">
            <div class="kpi-icon"><i class="bi bi-people"></i></div>
            <div class="kpi-label">Sections</div>
            <div class="kpi-value"><?= count(array_unique(array_column($assignments, 'section_id'))) ?></div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="kpi-card bg-gradient-info">
            <div class="kpi-icon"><i class="bi bi-clock"></i></div>
            <div class="kpi-label">Classes/Week</div>
            <div class="kpi-value"><?= count($assignments) ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Assigned Classes -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white"><i class="bi bi-clipboard-check me-2"></i>Assigned Classes This Term</div>
            <div class="card-body p-0">
                <?php if (empty($uniqueSubjects)): ?>
                    <p class="text-muted p-3">No classes assigned yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>Subject</th><th>Code</th><th>Section</th><th>Grade Level</th><th>Units</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($uniqueSubjects as $a): ?>
                            <tr>
                                <td><?= e($a['subject_name']) ?></td>
                                <td><span class="badge bg-secondary"><?= e($a['subject_code']) ?></span></td>
                                <td><?= e($a['section_name']) ?></td>
                                <td>Grade <?= e($a['grade_level']) ?></td>
                                <td><?= $a['units'] ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/teacher/teacher-grades.php?subject_id=<?= $a['subject_id'] ?>&section_id=<?= $a['section_id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i>Grades
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Upcoming Events -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white"><i class="bi bi-calendar-event me-2"></i>Upcoming Events</div>
            <div class="card-body">
                <?php if (empty($upcomingEvents)): ?>
                    <p class="text-muted">No upcoming events.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($upcomingEvents as $ev):
                            $badgeClass = match($ev['type']) {
                                'holiday' => 'bg-danger',
                                'exam'    => 'bg-warning text-dark',
                                'event'   => 'bg-info',
                                default   => 'bg-secondary',
                            };
                        ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between">
                                <strong><?= e($ev['title']) ?></strong>
                                <span class="badge <?= $badgeClass ?>"><?= e(ucfirst($ev['type'])) ?></span>
                            </div>
                            <small class="text-muted"><?= e(date('M d', strtotime($ev['date_start']))) ?> — <?= e(date('M d, Y', strtotime($ev['date_end']))) ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
