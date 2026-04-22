<?php
/**
 * Guardian Dashboard
 * Shows student info, GWA, attendance summary, upcoming schedule, latest payment.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$userId = $_SESSION['user_id'];

// Get guardian info
$stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

// Get students for this guardian
$students = [];
$gwa = 0;
$studentCount = 0;
if ($guardian) {
    $stmt = $pdo->prepare("
        SELECT s.*, sec.name AS section_name 
        FROM students s 
        LEFT JOIN sections sec ON s.section_id = sec.id 
        WHERE s.guardian_id = :gid
    ");
    $stmt->execute([':gid' => $guardian['id']]);
    $students = $stmt->fetchAll();
    $studentCount = count($students);

    // Calculate GWA for first student
    if (!empty($students)) {
        $firstStudent = $students[0];
        $stmt = $pdo->prepare("
            SELECT AVG(final_grade) AS gwa FROM grades 
            WHERE student_id = :sid AND final_grade IS NOT NULL
        ");
        $stmt->execute([':sid' => $firstStudent['id']]);
        $gwaRow = $stmt->fetch();
        $gwa = $gwaRow['gwa'] ? round($gwaRow['gwa'], 2) : 0;
    }
}

// Upcoming schedules (next 3)
$upcomingSchedules = [];
if (!empty($students)) {
    $stmt = $pdo->prepare("
        SELECT sch.day_of_week, sch.time_start, sch.time_end, sub.name AS subject_name, sch.room
        FROM schedules sch
        JOIN subjects sub ON sch.subject_id = sub.id
        JOIN sections sec ON sch.section_id = sec.id
        WHERE sec.id = :secid
        ORDER BY FIELD(sch.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday'), sch.time_start
        LIMIT 3
    ");
    $stmt->execute([':secid' => $students[0]['section_id'] ?? 0]);
    $upcomingSchedules = $stmt->fetchAll();
}

// Latest payment
$latestPayment = null;
if (!empty($students)) {
    $stmt = $pdo->prepare("
        SELECT p.* FROM payments p
        JOIN enrollments e ON p.enrollment_id = e.id
        WHERE e.student_id = :sid
        ORDER BY p.paid_at DESC
        LIMIT 1
    ");
    $stmt->execute([':sid' => $students[0]['id']]);
    $latestPayment = $stmt->fetch();
}

// Attendance summary (simple count from audit log as placeholder)
$attendanceDays = 0;
$absentDays = 0;

$pageTitle = 'Guardian Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Guardian Hero Banner -->
<div class="guardian-hero">
    <div class="d-flex align-items-center gap-4 flex-wrap">
        <div class="hero-icon">
            <i class="bi bi-person-heart"></i>
        </div>
        <div class="flex-grow-1">
            <?php if (!empty($students)): ?>
                <div class="hero-title"><?= e($students[0]['full_name']) ?></div>
                <div class="hero-subtitle">
                    Grade <?= e($students[0]['grade_level'] ?? 'N/A') ?> — Section <?= e($students[0]['section_name'] ?? 'N/A') ?>
                    <?php if ($students[0]['lrn']): ?> | LRN: <?= e($students[0]['lrn']) ?><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="hero-title">Welcome, <?= e($guardian['full_name'] ?? 'Guardian') ?>!</div>
                <div class="hero-subtitle">No students linked yet. <a href="<?= APP_URL ?>/guardian/enrollment/" style="color:white;text-decoration:underline;">Enroll a student</a>.</div>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($gwa > 0): ?>
                <span class="hero-badge"><i class="bi bi-trophy me-1"></i>GWA: <?= e(number_format((float)$gwa, 2)) ?></span>
            <?php endif; ?>
            <?php if ($studentCount > 1): ?>
                <span class="hero-badge"><i class="bi bi-people me-1"></i><?= e((string)$studentCount) ?> Students</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Action Cards (2×2) -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <a href="<?= APP_URL ?>/guardian/grades.php" class="quick-action-card">
            <div class="qa-icon" style="background:#CCFBF1;color:#0D9488;">
                <i class="bi bi-card-checklist"></i>
            </div>
            <div class="qa-label">View Grades</div>
            <div class="qa-sub">Academic performance</div>
        </a>
    </div>
    <div class="col-md-3 col-6">
        <a href="<?= APP_URL ?>/guardian/schedule.php" class="quick-action-card">
            <div class="qa-icon" style="background:#D1FAE5;color:#059669;">
                <i class="bi bi-calendar2-week-fill"></i>
            </div>
            <div class="qa-label">View Schedule</div>
            <div class="qa-sub">Weekly timetable</div>
        </a>
    </div>
    <div class="col-md-3 col-6">
        <a href="<?= APP_URL ?>/guardian/payments.php" class="quick-action-card">
            <div class="qa-icon" style="background:#FEF3C7;color:#D97706;">
                <i class="bi bi-credit-card-fill"></i>
            </div>
            <div class="qa-label">Payments</div>
            <div class="qa-sub">Payment history</div>
        </a>
    </div>
    <div class="col-md-3 col-6">
        <a href="<?= APP_URL ?>/guardian/report-card.php<?= !empty($students) ? '?student_id=' . (int)$students[0]['id'] : '' ?>" class="quick-action-card">
            <div class="qa-icon" style="background:#FFE4E6;color:#E11D48;">
                <i class="bi bi-printer-fill"></i>
            </div>
            <div class="qa-label">Report Card</div>
            <div class="qa-sub">Print records</div>
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Student Info Card -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white"><i class="bi bi-person-badge me-2"></i>Student Information</div>
            <div class="card-body">
                <?php if (empty($students)): ?>
                    <p class="text-muted">No students linked to your account yet. <a href="<?= APP_URL ?>/guardian/enrollment/">Enroll a student</a>.</p>
                <?php else: ?>
                    <?php foreach ($students as $stu): ?>
                    <div class="d-flex align-items-center mb-3 p-2 rounded bg-light">
                        <div class="me-3">
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;font-size:1.2rem;">
                                <?= e(strtoupper(substr($stu['full_name'], 0, 1))) ?>
                            </div>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold"><?= e($stu['full_name']) ?></h6>
                            <small class="text-muted">
                                Grade <?= e($stu['grade_level'] ?? 'N/A') ?> — Section <?= e($stu['section_name'] ?? 'N/A') ?>
                                <?php if ($stu['lrn']): ?> | LRN: <?= e($stu['lrn']) ?><?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Upcoming Schedule -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white"><i class="bi bi-clock me-2"></i>Upcoming Schedule</div>
            <div class="card-body">
                <?php if (empty($upcomingSchedules)): ?>
                    <p class="text-muted">No upcoming classes.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($upcomingSchedules as $sched): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <div>
                                <strong><?= e($sched['subject_name']) ?></strong><br>
                                <small class="text-muted"><?= e($sched['day_of_week']) ?> | Room <?= e($sched['room'] ?? 'TBD') ?></small>
                            </div>
                            <span class="badge bg-primary"><?= e(date('g:i A', strtotime($sched['time_start']))) ?> – <?= e(date('g:i A', strtotime($sched['time_end']))) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Latest Payment -->
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white"><i class="bi bi-credit-card me-2"></i>Latest Payment</div>
            <div class="card-body">
                <?php if ($latestPayment): ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th>Date</th><td><?= e($latestPayment['paid_at'] ? date('M d, Y', strtotime($latestPayment['paid_at'])) : 'Pending') ?></td>
                            <th>Amount</th><td>₱<?= e(number_format((float)$latestPayment['amount'], 2)) ?></td>
                        </tr>
                        <tr>
                            <th>Method</th><td><?= e(ucfirst($latestPayment['method'])) ?></td>
                            <th>Status</th><td><span class="badge badge-status-<?= e($latestPayment['status']) ?>"><?= e(ucfirst($latestPayment['status'])) ?></span></td>
                        </tr>
                        <tr>
                            <th>Reference</th><td colspan="3"><?= e($latestPayment['reference_no'] ?? 'N/A') ?></td>
                        </tr>
                    </table>
                </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No payment records found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
