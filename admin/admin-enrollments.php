<?php
/**
 * Admin Enrollments — View all, approve/reject with remarks, filter by status/year
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$filterStatus = $_GET['status'] ?? '';
$filterYear   = $_GET['year'] ?? '';
$errors       = [];

// ── Handle approve/reject ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $enrollId  = (int)($_POST['enrollment_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $remarks   = trim($_POST['remarks'] ?? '');

    if ($enrollId && in_array($newStatus, ['approved', 'rejected', 'enrolled'])) {
        $enrolledAt = $newStatus === 'enrolled' ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare("UPDATE enrollments SET status = :s, remarks = :r, enrolled_at = :ea WHERE id = :id");
        $stmt->execute([':s' => $newStatus, ':r' => $remarks, ':ea' => $enrolledAt, ':id' => $enrollId]);
        auditLog('enrollment_' . $newStatus, 'enrollments', $enrollId);
        setFlash('success', 'Enrollment ' . $newStatus . '.');
        redirect(APP_URL . '/admin/admin-enrollments.php?status=' . urlencode($filterStatus) . '&year=' . urlencode($filterYear));
    }
}

// Build filters
$where = []; $params = [];
if ($filterStatus) { $where[] = "e.status = :fs"; $params[':fs'] = $filterStatus; }
if ($filterYear) { $where[] = "e.school_year = :fy"; $params[':fy'] = $filterYear; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->prepare("SELECT COUNT(*) FROM enrollments e {$whereSQL}"); $total->execute($params);
[$offset, $limit, $page, $totalPages] = paginate($total->fetchColumn(), 15);

$stmt = $pdo->prepare("
    SELECT e.*, s.full_name AS student_name, s.grade_level
    FROM enrollments e
    JOIN students s ON e.student_id = s.id
    {$whereSQL}
    ORDER BY e.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$enrollments = $stmt->fetchAll();

$years = $pdo->query("SELECT DISTINCT school_year FROM enrollments ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// KPI counts
$totalEnrollments = $pdo->query("SELECT COUNT(*) FROM enrollments")->fetchColumn();
$enrolledCount    = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'enrolled'")->fetchColumn();
$approvedCount    = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'approved'")->fetchColumn();
$pendingCount     = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending'")->fetchColumn();
$rejectedCount    = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'rejected'")->fetchColumn();

$pageTitle = 'Student Enrollment';
require_once __DIR__ . '/../includes/header.php';

$avatarColors = ['bg-blue', 'bg-green', 'bg-red', 'bg-purple', 'bg-orange'];
?>

<!-- KPI Row -->
<div class="row g-3 mb-4">
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon-wrap"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="kpi-label">Total</div>
                <div class="kpi-value"><?= e(number_format($totalEnrollments)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-success">
            <div class="kpi-icon-wrap"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="kpi-label">Enrolled</div>
                <div class="kpi-value"><?= e(number_format($enrolledCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-info">
            <div class="kpi-icon-wrap"><i class="bi bi-hand-thumbs-up-fill"></i></div>
            <div>
                <div class="kpi-label">Approved</div>
                <div class="kpi-value"><?= e(number_format($approvedCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="kpi-label">Pending</div>
                <div class="kpi-value"><?= e(number_format($pendingCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-danger">
            <div class="kpi-icon-wrap"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="kpi-label">Rejected</div>
                <div class="kpi-value"><?= e(number_format($rejectedCount)) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-center" id="enrollment-filter">
            <div class="col-md-3">
                <select class="form-select form-select-sm" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['pending','approved','rejected','enrolled'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= e($filterStatus === $st ? 'selected' : '') ?>><?= e(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" name="year">
                    <option value="">All Years</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= e($y) ?>" <?= e($filterYear === $y ? 'selected' : '') ?>><?= e($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-filter me-1"></i>Filter</button>
            </div>
            <?php if ($filterStatus || $filterYear): ?>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/admin/admin-enrollments.php" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Table -->
<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="enrollments-table">
        <thead>
            <tr><th>#</th><th>Student</th><th>Grade</th><th>School Year</th><th>Term</th><th>Status</th><th>Remarks</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php if (empty($enrollments)): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox d-block"></i><p>No enrollments found.</p></div></td></tr>
            <?php else: foreach ($enrollments as $i => $en):
                $color = $avatarColors[$i % count($avatarColors)];
                $initial = strtoupper(substr($en['student_name'], 0, 1));
            ?>
            <tr>
                <td><?= e((string)($offset + $i + 1)) ?></td>
                <td>
                    <div class="user-row">
                        <div class="user-avatar <?= e($color) ?>"><?= e($initial) ?></div>
                        <div>
                            <div class="user-name"><?= e($en['student_name']) ?></div>
                        </div>
                    </div>
                </td>
                <td>Grade <?= e($en['grade_level'] ?? 'N/A') ?></td>
                <td><?= e($en['school_year']) ?></td>
                <td><?= e($en['term']) ?></td>
                <td><span class="badge badge-status-<?= e($en['status']) ?>"><?= e(ucfirst($en['status'])) ?></span></td>
                <td><small class="text-muted"><?= e($en['remarks'] ?? '') ?></small></td>
                <td>
                    <?php if ($en['status'] === 'pending'): ?>
                        <button class="btn btn-sm btn-success btn-icon" data-bs-toggle="modal" data-bs-target="#actionModal<?= (int)$en['id'] ?>" title="Review">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    <?php elseif ($en['status'] === 'approved'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="enrollment_id" value="<?= (int)$en['id'] ?>">
                            <input type="hidden" name="new_status" value="enrolled">
                            <input type="hidden" name="remarks" value="">
                            <button class="btn btn-sm btn-primary btn-icon" title="Mark as Enrolled"><i class="bi bi-mortarboard-fill"></i></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>

            <?php if ($en['status'] === 'pending'): ?>
            <!-- Action Modal -->
            <div class="modal fade" id="actionModal<?= (int)$en['id'] ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Review Enrollment — <?= e($en['student_name']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="enrollment_id" value="<?= (int)$en['id'] ?>">
                                <div class="mb-3">
                                    <label class="form-label">Decision</label>
                                    <select class="form-select" name="new_status" required>
                                        <option value="approved">Approve</option>
                                        <option value="rejected">Reject</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Remarks</label>
                                    <textarea class="form-control" name="remarks" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?status=' . urlencode($filterStatus) . '&year=' . urlencode($filterYear)) ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
