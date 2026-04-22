<?php
/**
 * Guardian Enrollment Payment Form
 * Completes payment details and submits enrollment for admin review.
 */

require_once __DIR__ . '/../../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = getDB();
$userId = $_SESSION['user_id'];
$errors = [];

$enrollmentId = (int)($_GET['enrollment_id'] ?? $_POST['enrollment_id'] ?? 0);
if ($enrollmentId < 1) {
    setFlash('danger', 'Invalid enrollment reference.');
    redirect(APP_URL . '/guardian/enrollment/');
}

$stmt = $pdo->prepare("SELECT id FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

if (!$guardian) {
    setFlash('danger', 'Guardian profile not found. Please complete your profile first.');
    redirect(APP_URL . '/guardian/complete-profile.php');
}

function loadEnrollmentPayment(PDO $pdo, int $guardianId, int $enrollmentId): ?array
{
    $stmt = $pdo->prepare("
        SELECT e.id AS enrollment_id, e.status AS enrollment_status, e.school_year, e.term, e.payment_submitted_at,
               s.id AS student_id, s.full_name AS student_name, s.grade_level, s.lrn,
               p.id AS payment_id, p.amount, p.method, p.reference_no, p.description, p.status AS payment_status
        FROM enrollments e
        INNER JOIN students s ON s.id = e.student_id
        LEFT JOIN payments p ON p.id = (
            SELECT p2.id
            FROM payments p2
            WHERE p2.enrollment_id = e.id
            ORDER BY p2.id DESC
            LIMIT 1
        )
        WHERE e.id = :eid
          AND s.guardian_id = :gid
        LIMIT 1
    ");
    $stmt->execute([
        ':eid' => $enrollmentId,
        ':gid' => $guardianId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$record = loadEnrollmentPayment($pdo, (int)$guardian['id'], $enrollmentId);
if (!$record) {
    setFlash('danger', 'Enrollment record not found or access is not allowed.');
    redirect(APP_URL . '/guardian/enrollment/');
}

if (in_array($record['enrollment_status'], ['approved', 'enrolled'], true)) {
    setFlash('info', 'This enrollment has already been accepted by admin.');
    redirect(APP_URL . '/guardian/enrollment/certificate.php?student_id=' . (int)$record['student_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $method = trim($_POST['payment_method'] ?? 'cash');
    $referenceNo = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['payment_notes'] ?? '');

    if (!in_array($method, ['cash', 'online', 'bank'], true)) {
        $errors[] = 'Invalid payment method selected.';
    }

    if (in_array($method, ['online', 'bank'], true) && $referenceNo === '') {
        $errors[] = 'Reference number is required for online or bank payments.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $description = 'Enrollment Fee';
            if ($notes !== '') {
                $description .= ' - ' . substr($notes, 0, 120);
            }

            $paymentId = (int)($record['payment_id'] ?? 0);
            if ($paymentId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET method = :method,
                        reference_no = :reference_no,
                        description = :description,
                        status = 'pending',
                        paid_at = NULL
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':method' => $method,
                    ':reference_no' => $referenceNo !== '' ? $referenceNo : null,
                    ':description' => $description,
                    ':id' => $paymentId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (enrollment_id, amount, method, reference_no, description, status)
                    VALUES (:eid, :amount, :method, :reference_no, :description, 'pending')
                ");
                $stmt->execute([
                    ':eid' => $enrollmentId,
                    ':amount' => 15000.00,
                    ':method' => $method,
                    ':reference_no' => $referenceNo !== '' ? $referenceNo : null,
                    ':description' => $description,
                ]);
            }

            $stmt = $pdo->prepare("
                UPDATE enrollments
                SET payment_submitted_at = NOW(),
                    status = CASE WHEN status = 'rejected' THEN 'pending' ELSE status END,
                    remarks = CASE
                        WHEN status = 'rejected' THEN 'Payment details resubmitted by guardian; pending review.'
                        ELSE remarks
                    END
                WHERE id = :id
            ");
            $stmt->execute([':id' => $enrollmentId]);

            $pdo->commit();

            auditLog('enrollment_sent_for_review', 'enrollments', $enrollmentId, null, [
                'payment_method' => $method,
                'reference_no' => $referenceNo !== '' ? $referenceNo : null,
            ]);

            setFlash('success', 'Payment form submitted. Your enrollment request has been sent to admin for acceptance or decline.');
            redirect(APP_URL . '/guardian/dashboard.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Enrollment payment submission error: ' . $e->getMessage());
            $errors[] = 'Unable to submit payment form right now. Please try again.';
        }
    }

    $record = loadEnrollmentPayment($pdo, (int)$guardian['id'], $enrollmentId);
}

$pageTitle = 'Enrollment Payment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h4 class="fw-bold mb-0"><i class="bi bi-credit-card me-2"></i>Enrollment Payment Form</h4>
        <p class="text-muted mb-0">Complete payment details so your enrollment request can be reviewed by admin.</p>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/guardian/enrollment/"><i class="bi bi-arrow-left me-1"></i>Back to Enrollment</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($record['payment_submitted_at'])): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        Payment form was already submitted on <?= e(date('M d, Y h:i A', strtotime($record['payment_submitted_at']))) ?>.
        You may update it below before admin finalizes the request.
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-2"></i>Enrollment Summary</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th>Student</th><td><?= e($record['student_name']) ?></td></tr>
                    <tr><th>Grade</th><td>Grade <?= e($record['grade_level'] ?: 'N/A') ?></td></tr>
                    <tr><th>LRN</th><td><?= e($record['lrn'] ?: 'N/A') ?></td></tr>
                    <tr><th>School Year</th><td><?= e($record['school_year']) ?></td></tr>
                    <tr><th>Term</th><td><?= e($record['term']) ?></td></tr>
                    <tr><th>Enrollment Status</th><td><span class="badge badge-status-<?= e($record['enrollment_status']) ?>"><?= e(ucfirst($record['enrollment_status'])) ?></span></td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-receipt me-2"></i>Payment Details</div>
            <div class="card-body">
                <div class="card bg-light mb-3">
                    <div class="card-body py-3">
                        <h6 class="fw-bold mb-2">Fee Breakdown</h6>
                        <table class="table table-sm mb-0">
                            <tr><td>Tuition Fee</td><td class="text-end">&#8369;12,000.00</td></tr>
                            <tr><td>Miscellaneous Fee</td><td class="text-end">&#8369;2,000.00</td></tr>
                            <tr><td>Lab Fee</td><td class="text-end">&#8369;1,000.00</td></tr>
                            <tr class="fw-bold border-top"><td>Total</td><td class="text-end">&#8369;15,000.00</td></tr>
                        </table>
                    </div>
                </div>

                <form method="POST" id="enrollment-payment-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="enrollment_id" value="<?= (int)$record['enrollment_id'] ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                        <?php $selectedMethod = $record['method'] ?? 'cash'; ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="pay_cash" value="cash" <?= $selectedMethod === 'cash' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pay_cash"><i class="bi bi-cash me-1"></i>Cash</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="pay_online" value="online" <?= $selectedMethod === 'online' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pay_online"><i class="bi bi-phone me-1"></i>Online Payment</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="pay_bank" value="bank" <?= $selectedMethod === 'bank' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pay_bank"><i class="bi bi-bank me-1"></i>Bank Transfer</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="reference_no" class="form-label">Reference Number</label>
                        <input type="text" class="form-control" id="reference_no" name="reference_no" value="<?= e($record['reference_no'] ?? '') ?>" placeholder="Required for online or bank transfer">
                    </div>

                    <div class="mb-3">
                        <label for="payment_notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="payment_notes" name="payment_notes" rows="3" placeholder="Add any extra payment details for admin review."></textarea>
                    </div>

                    <div class="alert alert-info mb-3">
                        <i class="bi bi-shield-check me-1"></i>
                        Submitting this form sends your enrollment request to admin for acceptance or decline.
                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-send-check me-1"></i>Submit Payment and Send to Admin
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
