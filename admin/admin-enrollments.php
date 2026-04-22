<?php
/**
 * Admin Enrollments
 * Review enrollment requests after guardian payment-form submission.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$filterStatus = $_GET['status'] ?? '';
$filterYear = $_GET['year'] ?? '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $enrollId = (int)($_POST['enrollment_id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($enrollId < 1) {
        $errors[] = 'Invalid enrollment reference.';
    }

    if (!in_array($decision, ['approve', 'decline'], true)) {
        $errors[] = 'Invalid review decision.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT id, status, payment_submitted_at
            FROM enrollments
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $enrollId]);
        $enrollment = $stmt->fetch();

        if (!$enrollment) {
            $errors[] = 'Enrollment record was not found.';
        } elseif (empty($enrollment['payment_submitted_at'])) {
            $errors[] = 'This enrollment is not ready for review yet. Guardian has not submitted the payment form.';
        } else {
            $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
            $enrolledAt = $newStatus === 'approved' ? date('Y-m-d H:i:s') : null;

            $stmt = $pdo->prepare("
                UPDATE enrollments
                SET status = :status,
                    remarks = :remarks,
                    enrolled_at = :enrolled_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $newStatus,
                ':remarks' => $remarks !== '' ? $remarks : null,
                ':enrolled_at' => $enrolledAt,
                ':id' => $enrollId,
            ]);

            auditLog('enrollment_' . $newStatus, 'enrollments', $enrollId, null, [
                'decision' => $decision,
                'remarks' => $remarks,
            ]);

            setFlash('success', 'Enrollment has been ' . ($decision === 'approve' ? 'accepted' : 'declined') . '.');
            redirect(APP_URL . '/admin/admin-enrollments.php?status=' . urlencode($filterStatus) . '&year=' . urlencode($filterYear));
        }
    }
}

$where = [];
$params = [];
if ($filterStatus !== '') {
    $where[] = 'e.status = :status';
    $params[':status'] = $filterStatus;
}
if ($filterYear !== '') {
    $where[] = 'e.school_year = :year';
    $params[':year'] = $filterYear;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments e {$whereSQL}");
$totalStmt->execute($params);
[$offset, $limit, $page, $totalPages] = paginate((int)$totalStmt->fetchColumn(), 15);

$stmt = $pdo->prepare("
    SELECT e.*, s.full_name AS student_name, s.grade_level,
           p.id AS payment_id, p.amount AS payment_amount, p.method AS payment_method,
           p.reference_no AS payment_reference_no, p.status AS payment_status, p.paid_at AS payment_paid_at
    FROM enrollments e
    INNER JOIN students s ON e.student_id = s.id
    LEFT JOIN payments p ON p.id = (
        SELECT p2.id
        FROM payments p2
        WHERE p2.enrollment_id = e.id
        ORDER BY p2.id DESC
        LIMIT 1
    )
    {$whereSQL}
    ORDER BY e.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$enrollments = $stmt->fetchAll();

$years = $pdo->query("SELECT DISTINCT school_year FROM enrollments ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);

$totalEnrollments = (int)$pdo->query("SELECT COUNT(*) FROM enrollments")->fetchColumn();
$approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'approved'")->fetchColumn();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending'")->fetchColumn();
$forReviewCount = (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending' AND payment_submitted_at IS NOT NULL")->fetchColumn();
$awaitingPaymentCount = (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending' AND payment_submitted_at IS NULL")->fetchColumn();
$rejectedCount = (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'rejected'")->fetchColumn();

$pageTitle = 'Student Enrollment';
require_once __DIR__ . '/../includes/header.php';

$avatarColors = ['bg-blue', 'bg-green', 'bg-red', 'bg-purple', 'bg-orange'];
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

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
        <div class="kpi-card kpi-info">
            <div class="kpi-icon-wrap"><i class="bi bi-inbox-fill"></i></div>
            <div>
                <div class="kpi-label">For Review</div>
                <div class="kpi-value"><?= e(number_format($forReviewCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="kpi-label">Awaiting Payment</div>
                <div class="kpi-value"><?= e(number_format($awaitingPaymentCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-success">
            <div class="kpi-icon-wrap"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="kpi-label">Approved</div>
                <div class="kpi-value"><?= e(number_format($approvedCount)) ?></div>
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
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-list-check"></i></div>
            <div>
                <div class="kpi-label">Pending Total</div>
                <div class="kpi-value"><?= e(number_format($pendingCount)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-center" id="enrollment-filter">
            <div class="col-md-3">
                <select class="form-select form-select-sm" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['pending','approved','rejected','enrolled','archived'] as $st): ?>
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

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="enrollments-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Student</th>
                <th>Grade</th>
                <th>School Year</th>
                <th>Term</th>
                <th>Payment</th>
                <th>Review Queue</th>
                <th>Status</th>
                <th>Remarks</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($enrollments)): ?>
                <tr><td colspan="10"><div class="empty-state"><i class="bi bi-inbox d-block"></i><p>No enrollments found.</p></div></td></tr>
            <?php else: foreach ($enrollments as $i => $en):
                $color = $avatarColors[$i % count($avatarColors)];
                $initial = strtoupper(substr($en['student_name'], 0, 1));
                $isReadyForReview = !empty($en['payment_submitted_at']);
                $canReview = $isReadyForReview && in_array($en['status'], ['pending', 'approved', 'rejected'], true);
            ?>
            <tr>
                <td><?= e((string)($offset + $i + 1)) ?></td>
                <td>
                    <div class="user-row">
                        <div class="user-avatar <?= e($color) ?>"><?= e($initial) ?></div>
                        <div><div class="user-name"><?= e($en['student_name']) ?></div></div>
                    </div>
                </td>
                <td>Grade <?= e($en['grade_level'] ?? 'N/A') ?></td>
                <td><?= e($en['school_year']) ?></td>
                <td><?= e($en['term']) ?></td>
                <td>
                    <?php if (!empty($en['payment_id'])): ?>
                        <div class="small">
                            <span class="badge badge-status-<?= e($en['payment_status'] ?: 'pending') ?>"><?= e(ucfirst($en['payment_status'] ?: 'pending')) ?></span>
                        </div>
                        <div class="small text-muted mt-1">Method: <?= e(ucfirst($en['payment_method'] ?? 'N/A')) ?></div>
                        <div class="small text-muted">Ref: <?= e($en['payment_reference_no'] ?: 'N/A') ?></div>
                    <?php else: ?>
                        <span class="badge badge-status-inactive">No Payment</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isReadyForReview): ?>
                        <span class="badge badge-status-active">Ready</span>
                        <div class="small text-muted mt-1"><?= e(date('M d, Y h:i A', strtotime($en['payment_submitted_at']))) ?></div>
                    <?php else: ?>
                        <span class="badge badge-status-inactive">Awaiting Payment Form</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-status-<?= e($en['status']) ?>"><?= e(ucfirst($en['status'])) ?></span></td>
                <td><small class="text-muted"><?= e($en['remarks'] ?? '') ?></small></td>
                <td>
                    <?php if ($canReview): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reviewModal<?= (int)$en['id'] ?>" title="Review Enrollment">
                            <i class="bi bi-check2-square me-1"></i>Review
                        </button>
                    <?php else: ?>
                        <button class="btn btn-sm btn-outline-secondary" disabled>
                            <i class="bi bi-lock me-1"></i>Waiting
                        </button>
                    <?php endif; ?>
                </td>
            </tr>

            <?php if ($canReview): ?>
            <div class="modal fade" id="reviewModal<?= (int)$en['id'] ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Review Enrollment - <?= e($en['student_name']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="enrollment_id" value="<?= (int)$en['id'] ?>">

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Current Status</label>
                                    <div><span class="badge badge-status-<?= e($en['status']) ?> fs-6"><?= e(ucfirst($en['status'])) ?></span></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Payment Snapshot</label>
                                    <div class="small text-muted">
                                        Amount: <?= !empty($en['payment_amount']) ? '&#8369;' . e(number_format((float)$en['payment_amount'], 2)) : 'N/A' ?><br>
                                        Method: <?= e(ucfirst($en['payment_method'] ?? 'N/A')) ?><br>
                                        Reference: <?= e($en['payment_reference_no'] ?: 'N/A') ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Remarks</label>
                                    <textarea class="form-control" name="remarks" rows="3" placeholder="Optional admin remarks."><?= e($en['remarks'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="decision" value="decline" class="btn btn-outline-danger">
                                    <i class="bi bi-x-circle me-1"></i>Decline
                                </button>
                                <button type="submit" name="decision" value="approve" class="btn btn-success">
                                    <i class="bi bi-check-circle me-1"></i>Accept
                                </button>
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
