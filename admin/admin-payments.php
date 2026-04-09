<?php
/**
 * Admin Payments — View all payments, record manual, export CSV
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$action = $_GET['action'] ?? '';
$errors = [];

// ── Export CSV ──────────────────────────────────────────
if ($action === 'export') {
    $stmt = $pdo->query("
        SELECT p.id, s.full_name AS student, e.school_year, e.term, p.amount, p.method, p.reference_no, p.description, p.status, p.paid_at
        FROM payments p
        JOIN enrollments e ON p.enrollment_id = e.id
        JOIN students s ON e.student_id = s.id
        ORDER BY p.id DESC
    ");
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payments_export_' . date('Ymd') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Student', 'School Year', 'Term', 'Amount', 'Method', 'Reference No', 'Description', 'Status', 'Paid At']);
    foreach ($rows as $r) { fputcsv($out, $r); }
    fclose($out);
    exit;
}

// ── Record Manual Payment ───────────────────────────────
if ($action === 'record' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    $amount       = (float)($_POST['amount'] ?? 0);
    $method       = trim($_POST['method'] ?? 'cash');
    $refNo        = trim($_POST['reference_no'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $status       = trim($_POST['status'] ?? 'paid');

    if (!$enrollmentId) $errors[] = 'Enrollment is required.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';

    if (empty($errors)) {
        $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare("INSERT INTO payments (enrollment_id, amount, method, reference_no, description, status, paid_at, recorded_by) VALUES (:eid, :amt, :m, :ref, :desc, :st, :pa, :rb)");
        $stmt->execute([':eid'=>$enrollmentId,':amt'=>$amount,':m'=>$method,':ref'=>$refNo,':desc'=>$description,':st'=>$status,':pa'=>$paidAt,':rb'=>$_SESSION['user_id']]);
        auditLog('record_payment', 'payments', (int)$pdo->lastInsertId());
        setFlash('success', 'Payment recorded.');
        redirect(APP_URL . '/admin/admin-payments.php');
    }
}

// Enrollments dropdown
$enrollmentsList = $pdo->query("
    SELECT e.id, s.full_name, e.school_year, e.term 
    FROM enrollments e JOIN students s ON e.student_id = s.id 
    ORDER BY e.id DESC
")->fetchAll();

// List with pagination
$total = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
[$offset, $limit, $page, $totalPages] = paginate($total, 15);

$stmt = $pdo->query("
    SELECT p.*, s.full_name AS student_name, e.school_year, e.term
    FROM payments p
    JOIN enrollments e ON p.enrollment_id = e.id
    JOIN students s ON e.student_id = s.id
    ORDER BY p.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$payments = $stmt->fetchAll();

$totalPaid = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid'")->fetchColumn();

$pageTitle = 'Manage Payments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-4"><h4 class="fw-bold"><i class="bi bi-cash-stack me-2"></i>Payments</h4></div>
    <div class="col-md-8 text-md-end d-flex justify-content-md-end gap-2">
        <span class="badge bg-success fs-6 align-self-center">Total Collected: ₱<?= number_format($totalPaid, 2) ?></span>
        <a href="?action=record" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Record Payment</a>
        <a href="?action=export" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($action === 'record'): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold">Record Manual Payment</div>
    <div class="card-body">
        <form method="POST" id="payment-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Enrollment <span class="text-danger">*</span></label>
                    <select class="form-select" name="enrollment_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($enrollmentsList as $en): ?>
                            <option value="<?= $en['id'] ?>"><?= e($en['full_name']) ?> — <?= e($en['school_year']) ?> (<?= e($en['term']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Method</label>
                    <select class="form-select" name="method">
                        <option value="cash">Cash</option>
                        <option value="online">Online</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Reference No.</label>
                    <input type="text" class="form-control" name="reference_no">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" name="description" value="Manual Payment">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Record</button>
            <a href="<?= APP_URL ?>/admin/admin-payments.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="payments-table">
        <thead><tr><th>#</th><th>Student</th><th>Year/Term</th><th>Description</th><th class="text-end">Amount</th><th>Method</th><th>Ref No.</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
            <?php if (empty($payments)): ?>
                <tr><td colspan="9" class="text-center text-muted py-3">No payments found.</td></tr>
            <?php else: foreach ($payments as $i => $p): ?>
            <tr>
                <td><?= $offset + $i + 1 ?></td>
                <td class="fw-bold"><?= e($p['student_name']) ?></td>
                <td><?= e($p['school_year']) ?> — <?= e($p['term']) ?></td>
                <td><?= e($p['description'] ?? '') ?></td>
                <td class="text-end">₱<?= number_format($p['amount'], 2) ?></td>
                <td><?= e(ucfirst($p['method'])) ?></td>
                <td><small><?= e($p['reference_no'] ?? 'N/A') ?></small></td>
                <td><span class="badge badge-status-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span></td>
                <td><?= $p['paid_at'] ? e(date('M d, Y', strtotime($p['paid_at']))) : '—' ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?x=1') ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
