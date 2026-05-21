<?php
/**
 * Admin Payments â€” View all payments, record manual, export CSV
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin', 'clerk']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$action = $_GET['action'] ?? '';
$errors = [];
$paymentMethods = paymentMethods();
$paymentStatuses = paymentStatuses();
$selectedEnrollmentId = (int)($_GET['enrollment_id'] ?? $_POST['enrollment_id'] ?? 0);

// â”€â”€ Export CSV â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'export') {
    $stmt = $pdo->query("
        SELECT p.id, CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student, e.school_year, e.term, p.amount, p.method, p.reference_no, p.description, p.status, p.paid_at
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

// â”€â”€ Record Manual Payment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'record' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    $amount       = (float)($_POST['amount'] ?? 0);
    $method       = trim($_POST['method'] ?? 'cash');
    $refNo        = trim($_POST['reference_no'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $status       = trim($_POST['status'] ?? 'paid');
    $enrollment   = null;
    $latestPayment = null;

    if (!$enrollmentId) $errors[] = 'Enrollment is required.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if (!array_key_exists($method, $paymentMethods)) $errors[] = 'Invalid payment method selected.';
    if (!array_key_exists($status, $paymentStatuses)) $errors[] = 'Invalid payment status selected.';
    if ($status !== 'failed' && in_array($method, ['online', 'bank'], true) && $refNo === '') {
        $errors[] = 'Reference number is required for online or bank payments.';
    }

    if ($enrollmentId > 0) {
        $enrollStmt = $pdo->prepare("
            SELECT e.id, e.status, e.school_year, e.term,
                   CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student_name
            FROM enrollments e
            INNER JOIN students s ON s.id = e.student_id
            WHERE e.id = :id
            LIMIT 1
        ");
        $enrollStmt->execute([':id' => $enrollmentId]);
        $enrollment = $enrollStmt->fetch() ?: null;

        if (!$enrollment) {
            $errors[] = 'Enrollment record was not found.';
        } elseif (!canVerifyEnrollmentPayment($enrollment['status'])) {
            $errors[] = 'Payment can only be verified after the enrollment has a payment assessment and is still awaiting payment.';
        }
    }

    if ($enrollmentId > 0 && in_array($status, ['paid', 'failed'], true)) {
        $documentReviewSummary = loadEnrollmentDocumentReviewSummary($pdo, $enrollmentId);
        if (!$documentReviewSummary['all_accepted']) {
            $blockers = enrollmentDocumentReviewBlockerText($documentReviewSummary);
            $errors[] = 'Payment cannot be verified until all required enrollment documents are accepted.'
                . ($blockers !== '' ? ' ' . $blockers : '');
        }
    }

    if (empty($errors) && $enrollmentId > 0) {
        $assessmentStmt = $pdo->prepare("
            SELECT id, total_amount
            FROM enrollment_assessments
            WHERE enrollment_id = :eid
              AND status = 'sent_to_cashier'
            ORDER BY id DESC
            LIMIT 1
        ");
        $assessmentStmt->execute([':eid' => $enrollmentId]);
        $sentAssessment = $assessmentStmt->fetch();
        if (!$sentAssessment) {
            $errors[] = 'A payment assessment must be sent before payment can be verified.';
        }

        $latestPayment = latestPaymentForEnrollment($pdo, $enrollmentId);
        if ($latestPayment && (string)$latestPayment['status'] === 'paid') {
            $errors[] = 'This enrollment already has a paid payment record. Paid records are kept as history and cannot be overwritten.';
        }
        if ($latestPayment && (float)($latestPayment['amount'] ?? 0) > 0) {
            $assessedAmount = (float)$latestPayment['amount'];
            if (abs($amount - $assessedAmount) > 0.009) {
                $errors[] = 'Payment amount must match the latest assessment amount of PHP ' . number_format($assessedAmount, 2) . '. Change the assessment first if the amount is wrong.';
            }
        } elseif ($sentAssessment && (float)($sentAssessment['total_amount'] ?? 0) > 0) {
            $assessedAmount = (float)$sentAssessment['total_amount'];
            if (abs($amount - $assessedAmount) > 0.009) {
                $errors[] = 'Payment amount must match the latest assessment amount of PHP ' . number_format($assessedAmount, 2) . '. Change the assessment first if the amount is wrong.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
            $paymentId = (int)($latestPayment['id'] ?? 0);
            $latestPaymentStatus = (string)($latestPayment['status'] ?? '');
            $shouldInsertNewPayment = $paymentId < 1 || ($latestPaymentStatus === 'failed' && $status !== 'failed');

            if (!$shouldInsertNewPayment) {
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET amount = :amt,
                        method = :m,
                        reference_no = :ref,
                        description = :desc,
                        status = :st,
                        paid_at = :pa,
                        recorded_by = :rb
                    WHERE id = :id
                      AND status::text <> 'paid'
                ");
                $stmt->execute([
                    ':amt' => $amount,
                    ':m' => $method,
                    ':ref' => $refNo ?: null,
                    ':desc' => $description !== '' ? $description : 'Enrollment Assessment Payment',
                    ':st' => $status,
                    ':pa' => $paidAt,
                    ':rb' => $_SESSION['user_id'],
                    ':id' => $paymentId,
                ]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Unable to update payment because the latest payment is already finalized.');
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO payments
                        (enrollment_id, amount, method, reference_no, description, status, paid_at, recorded_by)
                    VALUES
                        (:eid, :amt, :m, :ref, :desc, :st, :pa, :rb)
                    RETURNING id
                ");
                $stmt->execute([
                    ':eid' => $enrollmentId,
                    ':amt' => $amount,
                    ':m' => $method,
                    ':ref' => $refNo ?: null,
                    ':desc' => $description !== '' ? $description : 'Enrollment Assessment Payment',
                    ':st' => $status,
                    ':pa' => $paidAt,
                    ':rb' => $_SESSION['user_id'],
                ]);
                $paymentId = (int)$stmt->fetchColumn();
            }

            if ($status === 'paid') {
                $advancingStatuses = paymentVerificationStatuses();
                $placeholders = implode(',', array_fill(0, count($advancingStatuses), '?'));
                $stmt = $pdo->prepare("
                    UPDATE enrollments
                    SET payment_submitted_at = COALESCE(payment_submitted_at, NOW()),
                        status = 'paid_for_registrar',
                        remarks = 'Payment verified; ready for registrar submission.'
                    WHERE id = ?
                      AND status::text IN ({$placeholders})
                ");
                $stmt->execute(array_merge([$enrollmentId], $advancingStatuses));
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Enrollment is no longer in a payment-verification stage.');
                }
            } elseif ($status === 'failed') {
                $retryStatuses = paymentVerificationStatuses();
                $placeholders = implode(',', array_fill(0, count($retryStatuses), '?'));
                $stmt = $pdo->prepare("
                    UPDATE enrollments
                    SET status = 'assessed_for_payment',
                        remarks = 'Payment verification failed; guardian must resubmit payment reference.'
                    WHERE id = ?
                      AND status::text IN ({$placeholders})
                ");
                $stmt->execute(array_merge([$enrollmentId], $retryStatuses));
            }

            auditLog('record_payment_verification', 'payments', $paymentId, null, [
                'enrollment_id' => $enrollmentId,
                'status' => $status,
            ]);
            $pdo->commit();

            setFlash('success', match ($status) {
                'paid' => 'Payment verified. Enrollment is ready for registrar submission to teachers.',
                'failed' => 'Payment marked failed. Guardian can resubmit the payment reference.',
                default => 'Pending payment record saved.',
            });
            redirect(APP_URL . '/admin/admin-payments.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Payment verification error: ' . $e->getMessage());
            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to save payment right now. Please try again.';
        }
    }
}

// Enrollments dropdown: only rows that have reached the payment stage.
$paymentStageStatuses = paymentVerificationStatuses();
$paymentStagePlaceholders = implode(',', array_fill(0, count($paymentStageStatuses), '?'));
$enrollmentStmt = $pdo->prepare("
    SELECT e.id,
           CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student_name,
           e.school_year, e.term, e.status,
           p.amount AS latest_payment_amount, p.status AS latest_payment_status
    FROM enrollments e
    JOIN students s ON e.student_id = s.id
    LEFT JOIN payments p ON p.id = (
        SELECT p2.id
        FROM payments p2
        WHERE p2.enrollment_id = e.id
        ORDER BY p2.id DESC
        LIMIT 1
    )
    WHERE e.status::text IN ({$paymentStagePlaceholders})
      AND EXISTS (
          SELECT 1
          FROM enrollment_assessments ea
          WHERE ea.enrollment_id = e.id
            AND ea.status = 'sent_to_cashier'
      )
    ORDER BY e.id DESC
");
$enrollmentStmt->execute($paymentStageStatuses);
$enrollmentsList = $enrollmentStmt->fetchAll();

// List with pagination
$total = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
[$offset, $limit, $page, $totalPages] = paginate($total, 15);

$stmt = $pdo->query("
    SELECT p.*,
           CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student_name,
           e.school_year, e.term
    FROM payments p
    JOIN enrollments e ON p.enrollment_id = e.id
    JOIN students s ON e.student_id = s.id
    ORDER BY p.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$payments = $stmt->fetchAll();

$totalPaid = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid'")->fetchColumn();

$pageTitle = 'Payment Verification';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-4"><h4 class="fw-bold"><i class="bi bi-cash-stack me-2"></i>Payment Verification</h4></div>
    <div class="col-md-8 text-md-end d-flex justify-content-md-end gap-2">
        <span class="badge bg-success fs-6 align-self-center">Total Collected: &#8369;<?= e(number_format($totalPaid, 2)) ?></span>
        <a href="?action=record" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Record / Verify Payment</a>
        <a href="?action=export" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($action === 'record'): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold">Record / Verify Treasurer Payment</div>
    <div class="card-body">
        <?php if (empty($enrollmentsList)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                No enrollments are currently in the payment stage. Create a payment assessment first, or wait for a guardian payment reference.
            </div>
        <?php endif; ?>
        <form method="POST" id="payment-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Enrollment <span class="text-danger">*</span></label>
                    <select class="form-select" name="enrollment_id" id="enrollment_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($enrollmentsList as $en): ?>
                            <option value="<?= (int)$en['id'] ?>" data-amount="<?= e((string)($en['latest_payment_amount'] ?? '')) ?>" <?= $selectedEnrollmentId === (int)$en['id'] ? 'selected' : '' ?>>
                                <?= e($en['student_name']) ?> - <?= e($en['school_year']) ?> (<?= e($en['term']) ?>)
                                / <?= e(enrollmentStatusLabel((string)$en['status'])) ?>
                                <?php if (!empty($en['latest_payment_amount'])): ?>
                                    / &#8369;<?= e(number_format((float)$en['latest_payment_amount'], 2)) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="amount" id="payment_amount" step="0.01" min="0.01" required>
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
                    <input type="text" class="form-control" name="description" value="Enrollment Assessment Payment">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach ($paymentStatuses as $st => $label): ?>
                            <option value="<?= e($st) ?>" <?= $st === 'paid' ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" <?= empty($enrollmentsList) ? 'disabled' : '' ?>>
                <i class="bi bi-save me-1"></i>Save Payment Status
            </button>
            <a href="<?= APP_URL ?>/admin/admin-payments.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<script>
(function () {
    const enrollmentSelect = document.getElementById('enrollment_id');
    const amountInput = document.getElementById('payment_amount');
    if (!enrollmentSelect || !amountInput) return;

    function syncAssessedAmount() {
        const option = enrollmentSelect.selectedOptions[0];
        const amount = option ? parseFloat(option.dataset.amount || '0') : 0;
        if (amount > 0) {
            amountInput.value = amount.toFixed(2);
        }
    }

    enrollmentSelect.addEventListener('change', syncAssessedAmount);
    syncAssessedAmount();
})();
</script>
<?php endif; ?>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="payments-table">
        <thead><tr><th>#</th><th>Student</th><th>Year/Term</th><th>Description</th><th class="text-end">Amount</th><th>Method</th><th>Ref No.</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
            <?php if (empty($payments)): ?>
                <?= emptyStateRow(9, 'No payments match the current view.', 'Payments are created when the enrollment clerk assesses fees and payment is recorded or verified. Clear any filters above, or check back after a fee assessment is issued.', 'bi-receipt') ?>
            <?php else: foreach ($payments as $i => $p): ?>
            <tr>
                <td><?= e((string)($offset + $i + 1)) ?></td>
                <td class="fw-bold"><?= e($p['student_name']) ?></td>
                <td><?= e($p['school_year']) ?> - <?= e($p['term']) ?></td>
                <td><?= e($p['description'] ?? '') ?></td>
                <td class="text-end">&#8369;<?= e(number_format($p['amount'], 2)) ?></td>
                <td><?= e(ucfirst($p['method'])) ?></td>
                <td><small><?= e($p['reference_no'] ?? 'N/A') ?></small></td>
                <td><span class="badge <?= e(paymentStatusBadgeClass($p['status'])) ?>"><?= e(paymentStatusLabel($p['status'])) ?></span></td>
                <td><?= $p['paid_at'] ? e(date('M d, Y', strtotime($p['paid_at']))) : '-' ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?x=1') ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

