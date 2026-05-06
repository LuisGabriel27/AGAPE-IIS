<?php
/**
 * Teacher Dashboard
 * Shows assigned subjects and sections for the current term.
 * Includes pending grade entry count, quick links, upcoming events, and recent activity.
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

// Get upcoming calendar events (next 3)
$stmt = $pdo->prepare("
    SELECT * FROM calendar_events 
    WHERE date_end >= CURRENT_DATE 
    ORDER BY date_start 
    LIMIT 3
");
$stmt->execute();
$upcomingEvents = $stmt->fetchAll();

// ── KPI: Pending Grade Entry ────────────────────────────
$pendingGradeEntry = 0;
if ($teacher) {
    // Get distinct subject-section-term combos assigned to this teacher
    $stmt = $pdo->prepare("
        SELECT DISTINCT sch.subject_id, sch.section_id, sch.school_year, sch.term
        FROM schedules sch
        WHERE sch.teacher_id = :tid
    ");
    $stmt->execute([':tid' => $teacher['id']]);
    $teacherCombos = $stmt->fetchAll();

    foreach ($teacherCombos as $combo) {
        // Count students in this section who have NO grade record for this subject/year/term
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM students s
            WHERE s.section_id = :secid
            AND s.id NOT IN (
                SELECT g.student_id FROM grades g
                WHERE g.subject_id = :subid AND g.school_year = :sy AND g.term = :term
                AND g.final_grade IS NOT NULL
            )
        ");
        $stmt->execute([
            ':secid' => $combo['section_id'],
            ':subid' => $combo['subject_id'],
            ':sy'    => $combo['school_year'],
            ':term'  => $combo['term'],
        ]);
        $missing = (int)$stmt->fetchColumn();
        if ($missing > 0) {
            $pendingGradeEntry++;
        }
    }
}

// ── Recent Activity (last 5 audit entries for this teacher) ──
$recentActivity = [];
if ($teacher) {
    $stmt = $pdo->prepare("
        SELECT action, table_affected, timestamp
        FROM audit_log
        WHERE user_id = :uid
        ORDER BY timestamp DESC
        LIMIT 5
    ");
    $stmt->execute([':uid' => $userId]);
    $recentActivity = $stmt->fetchAll();
}

$pageTitle = 'Teacher Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-speedometer2 me-2"></i>Teacher Dashboard</h4>
        <p class="text-muted">Welcome, <?= e(format_name($teacher['first_name'] ?? '', $teacher['last_name'] ?? 'Teacher')) ?>!</p>
    </div>
</div>

<!-- KPI Row (4 cards) -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon-wrap"><i class="bi bi-book"></i></div>
            <div>
                <div class="kpi-label">Subjects</div>
                <div class="kpi-value"><?= e((string)count(array_unique(array_column($assignments, 'subject_id')))) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="kpi-card kpi-success">
            <div class="kpi-icon-wrap"><i class="bi bi-people"></i></div>
            <div>
                <div class="kpi-label">Sections</div>
                <div class="kpi-value"><?= e((string)count(array_unique(array_column($assignments, 'section_id')))) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="kpi-card kpi-info">
            <div class="kpi-icon-wrap"><i class="bi bi-clock"></i></div>
            <div>
                <div class="kpi-label">Classes/Week</div>
                <div class="kpi-value"><?= e((string)count($assignments)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="kpi-label">Pending Grades</div>
                <div class="kpi-value"><?= e((string)$pendingGradeEntry) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Links Row -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white">
                <span><i class="bi bi-lightning-charge-fill"></i>Quick Links</span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= APP_URL ?>/teacher/teacher-dashboard.php" class="btn btn-outline-primary">
                        <i class="bi bi-grid-1x2-fill me-1"></i>My Classes
                    </a>
                    <a href="<?= APP_URL ?>/teacher/teacher-grades.php" class="btn btn-outline-primary">
                        <i class="bi bi-pencil-square me-1"></i>Enter Grades
                    </a>
                    <a href="<?= APP_URL ?>/teacher/teacher-attendance.php" class="btn btn-outline-primary">
                        <i class="bi bi-clipboard-check me-1"></i>Attendance
                    </a>
                    <a href="<?= APP_URL ?>/teacher/teacher-calendar.php" class="btn btn-outline-primary">
                        <i class="bi bi-calendar-event-fill me-1"></i>Calendar
                    </a>
                    <a href="<?= APP_URL ?>/teacher/teacher-schedule.php" class="btn btn-outline-primary">
                        <i class="bi bi-calendar2-week-fill me-1"></i>My Schedule
                    </a>
                </div>
            </div>
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
                                <td><?= e((string)$a['units']) ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/teacher/teacher-grades.php?subject_id=<?= (int)$a['subject_id'] ?>&section_id=<?= (int)$a['section_id'] ?>" class="btn btn-sm btn-outline-primary">
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

    <!-- Right Column: Events + Activity -->
    <div class="col-lg-4">
        <!-- Upcoming School Events -->
        <div class="card mb-4">
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
                                <span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($ev['type'])) ?></span>
                            </div>
                            <small class="text-muted"><?= e(date('M d', strtotime($ev['date_start']))) ?> — <?= e(date('M d, Y', strtotime($ev['date_end']))) ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card">
            <div class="card-header bg-white"><i class="bi bi-activity me-2"></i>Recent Activity</div>
            <div class="card-body">
                <?php if (empty($recentActivity)): ?>
                    <p class="text-muted">No recent activity.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentActivity as $act): ?>
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold" style="font-size:0.85rem;"><?= e($act['action']) ?></div>
                                <small class="text-muted"><?= e($act['table_affected'] ?? '') ?></small>
                            </div>
                            <small class="text-muted text-nowrap ms-2"><?= e(date('M d, g:i A', strtotime($act['timestamp']))) ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
