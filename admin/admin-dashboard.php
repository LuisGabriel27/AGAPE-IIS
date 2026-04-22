<?php
/**
 * Admin Dashboard
 * KPI cards, recent transactions, quick links, upcoming events.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();

$totalStudents     = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalRevenue      = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'")->fetchColumn();
$enrolledThisTerm  = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'enrolled' AND school_year = '" . currentSchoolYear() . "'")->fetchColumn();
$totalStudentsForRate = max(1, $totalStudents);
$enrollmentRate    = round(($enrolledThisTerm / $totalStudentsForRate) * 100, 1);

// These queries reference the payment_submitted_at column which may not exist yet
// (added in upgrade_migrations.sql). Graceful fallback if the column is missing.
try {
    $pendingEnroll     = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending' AND payment_submitted_at IS NOT NULL")->fetchColumn();
    $pendingPayment    = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending' AND payment_submitted_at IS NULL")->fetchColumn();
} catch (PDOException $e) {
    // Fallback: count all pending enrollments without distinguishing by payment_submitted_at
    $pendingEnroll  = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending'")->fetchColumn();
    $pendingPayment = 0;
}

$totalUsers        = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalTeachers     = $pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
$attendanceEnabled = attendanceModuleEnabled();

// Recent transactions (payments)
$stmt = $pdo->query("
    SELECT p.*, s.full_name AS student_name, e.school_year, e.term
    FROM payments p
    JOIN enrollments e ON p.enrollment_id = e.id
    JOIN students s ON e.student_id = s.id
    ORDER BY p.id DESC
    LIMIT 6
");
$recentPayments = $stmt->fetchAll();

// Recent activity (from audit_log)
$stmt = $pdo->query("
    SELECT a.*, u.email 
    FROM audit_log a 
    LEFT JOIN users u ON a.user_id = u.id 
    ORDER BY a.timestamp DESC 
    LIMIT 8
");
$recentActivity = $stmt->fetchAll();

// Upcoming events
$stmt = $pdo->query("SELECT * FROM calendar_events WHERE date_end >= CURDATE() ORDER BY date_start LIMIT 5");
$upcomingEvents = $stmt->fetchAll();

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';

$avatarColors = ['bg-blue', 'bg-green', 'bg-red', 'bg-purple', 'bg-orange'];
?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6 col-6">
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon-wrap"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="kpi-label">Total Students</div>
                <div class="kpi-value"><?= e(number_format($totalStudents)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="kpi-card kpi-success">
            <div class="kpi-icon-wrap"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="kpi-label">Total Revenue</div>
                <div class="kpi-value">&#8369;<?= e(number_format($totalRevenue, 0)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="kpi-card kpi-info">
            <div class="kpi-icon-wrap"><i class="bi bi-graph-up-arrow"></i></div>
            <div>
                <div class="kpi-label">Enrollment Rate</div>
                <div class="kpi-value"><?= e((string)$enrollmentRate) ?>%</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="kpi-label">Pending Review</div>
                <div class="kpi-value"><?= e(number_format($pendingEnroll)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Recent Transactions -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-clock-history"></i>Recent Transactions</span>
                <a href="<?= APP_URL ?>/admin/admin-payments.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentPayments)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox d-block"></i>
                        <p>No recent transactions.</p>
                    </div>
                <?php else: ?>
                    <div class="px-3 py-2">
                    <?php foreach ($recentPayments as $i => $p): 
                        $color = $avatarColors[$i % count($avatarColors)];
                        $initial = strtoupper(substr($p['student_name'], 0, 1));
                    ?>
                        <div class="transaction-item">
                            <div class="t-avatar user-avatar <?= e($color) ?>"><?= e($initial) ?></div>
                            <div class="t-info">
                                <div class="t-name"><?= e($p['student_name']) ?></div>
                                <div class="t-desc"><?= e($p['description'] ?? 'Payment') ?> &middot; <?= e(ucfirst($p['method'])) ?></div>
                            </div>
                            <div class="text-end">
                                <div class="t-amount amount-positive">&#8369;<?= e(number_format($p['amount'], 2)) ?></div>
                                <div class="t-date"><?= e($p['paid_at'] ? date('M d', strtotime($p['paid_at'])) : 'Pending') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-lightning-charge-fill"></i>Quick Actions</span>
            </div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="<?= APP_URL ?>/admin/admin-enrollments.php?status=pending" class="quick-action">
                    <div class="qa-icon" style="background:var(--warning-light);color:var(--warning-dark);">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div>
                        <div class="qa-text">Pending Enrollments</div>
                        <div class="qa-sub"><?= e((string)$pendingEnroll) ?> ready, <?= e((string)$pendingPayment) ?> awaiting payment</div>
                    </div>
                </a>
                <a href="<?= APP_URL ?>/admin/admin-students.php" class="quick-action">
                    <div class="qa-icon" style="background:var(--primary-light);color:var(--primary);">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="qa-text">Manage Students</div>
                        <div class="qa-sub"><?= e((string)$totalStudents) ?> total students</div>
                    </div>
                </a>
                <a href="<?= APP_URL ?>/admin/admin-payments.php" class="quick-action">
                    <div class="qa-icon" style="background:var(--success-light);color:var(--success);">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div>
                        <div class="qa-text">Financial Ledger</div>
                        <div class="qa-sub">View all payments</div>
                    </div>
                </a>
                <a href="<?= APP_URL ?>/admin/admin-calendar.php" class="quick-action">
                    <div class="qa-icon" style="background:var(--info-light);color:var(--info);">
                        <i class="bi bi-calendar-event-fill"></i>
                    </div>
                    <div>
                        <div class="qa-text">School Calendar</div>
                        <div class="qa-sub">Events & holidays</div>
                    </div>
                </a>
                <a href="<?= APP_URL ?>/admin/admin-attendance.php" class="quick-action">
                    <div class="qa-icon" style="background:<?= $attendanceEnabled ? 'var(--orange-light)' : 'var(--secondary-light)' ?>;color:<?= $attendanceEnabled ? 'var(--orange)' : 'var(--secondary)' ?>;">
                        <i class="bi bi-camera-video-fill"></i>
                    </div>
                    <div>
                        <div class="qa-text">Face Attendance</div>
                        <div class="qa-sub"><?= $attendanceEnabled ? 'Take attendance by camera' : 'Module currently disabled' ?></div>
                    </div>
                </a>
                <a href="<?= APP_URL ?>/admin/admin-users.php" class="quick-action">
                    <div class="qa-icon" style="background:var(--purple-light);color:var(--purple);">
                        <i class="bi bi-person-gear"></i>
                    </div>
                    <div>
                        <div class="qa-text">User Management</div>
                        <div class="qa-sub"><?= e((string)$totalUsers) ?> accounts</div>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Second Row: Activity + Events -->
<div class="row g-4 mt-0">
    <!-- Recent Activity -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-activity"></i>Recent Activity</span>
            </div>
            <div class="card-body p-0" style="max-height:350px;overflow-y:auto;">
                <?php if (empty($recentActivity)): ?>
                    <div class="empty-state">
                        <i class="bi bi-clock d-block"></i>
                        <p>No recent activity.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentActivity as $act): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-start py-3">
                            <div>
                                <div class="fw-semibold" style="font-size:0.85rem;"><?= e($act['action']) ?></div>
                                <small class="text-muted"><?= e($act['email'] ?? 'System') ?> &middot; <?= e($act['table_affected'] ?? '') ?></small>
                            </div>
                            <small class="text-muted text-nowrap ms-3"><?= e(date('M d, g:i A', strtotime($act['timestamp']))) ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Upcoming Events -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-calendar-event-fill"></i>Upcoming Events</span>
                <a href="<?= APP_URL ?>/admin/admin-calendar.php" class="btn btn-sm btn-outline-primary">View Calendar</a>
            </div>
            <div class="card-body">
                <?php if (empty($upcomingEvents)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar d-block"></i>
                        <p>No upcoming events.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingEvents as $ev):
                        $badgeClass = match($ev['type']) {
                            'holiday' => 'badge-status-rejected',
                            'exam'    => 'badge-status-pending',
                            'event'   => 'badge-status-enrolled',
                            default   => 'badge-status-inactive',
                        };
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3 rounded" style="background:var(--body-bg);">
                        <div>
                            <div class="fw-semibold" style="font-size:0.9rem;"><?= e($ev['title']) ?></div>
                            <small class="text-muted">
                                <i class="bi bi-calendar3 me-1"></i>
                                <?= e(date('M d', strtotime($ev['date_start']))) ?> - <?= e(date('M d, Y', strtotime($ev['date_end']))) ?>
                            </small>
                        </div>
                        <span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($ev['type'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

