<?php
/**
 * Guardian Enrollment Payment Reference
 * Legacy page for recording payment details before registrar submission.
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
               s.id AS student_id,
               CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student_name,
               s.grade_level, s.lrn,
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

function loadGuardianLatestSentAssessment(PDO $pdo, int $enrollmentId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM enrollment_assessments
        WHERE enrollment_id = :eid
          AND status = 'sent_to_cashier'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':eid' => $enrollmentId]);
    $assessment = $stmt->fetch();
    if (!$assessment) {
        return null;
    }

    $itemStmt = $pdo->prepare("
        SELECT category, description, amount, sort_order
        FROM enrollment_assessment_items
        WHERE assessment_id = :aid
        ORDER BY sort_order, id
    ");
    $itemStmt->execute([':aid' => (int)$assessment['id']]);

    return [
        'assessment' => $assessment,
        'items' => $itemStmt->fetchAll(),
    ];
}

$record = loadEnrollmentPayment($pdo, (int)$guardian['id'], $enrollmentId);
if (!$record) {
    setFlash('danger', 'Enrollment record not found or access is not allowed.');
    redirect(APP_URL . '/guardian/enrollment/');
}

$assessmentDetails = loadGuardianLatestSentAssessment($pdo, $enrollmentId);
$documentReviewSummary = loadEnrollmentDocumentReviewSummary($pdo, $enrollmentId);
$documentsReadyForPayment = (bool)$documentReviewSummary['all_accepted'];
$documentReviewBlockers = enrollmentDocumentReviewBlockerText($documentReviewSummary);
$hasPaymentAssessment = $assessmentDetails !== null
    && !empty($record['payment_id'])
    && (float)($record['amount'] ?? 0) > 0;
$canSubmitPayment = $documentsReadyForPayment
    && canGuardianSubmitEnrollmentPayment($record['enrollment_status'])
    && $hasPaymentAssessment
    && (string)($record['payment_status'] ?? 'pending') !== 'paid';

if (in_array($record['enrollment_status'], ['enrolled', 'archived'], true)) {
    setFlash('info', 'This enrollment has already been submitted by the Registrar.');
    redirect(APP_URL . '/guardian/enrollment/certificate.php?student_id=' . (int)$record['student_id']);
}

if (in_array($record['enrollment_status'], ['paid_for_registrar'], true)) {
    setFlash('info', 'Payment is already verified. The Registrar will review this enrollment shortly.');
    redirect(APP_URL . '/guardian/dashboard.php');
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

    if (!$documentsReadyForPayment) {
        $errors[] = 'Payment cannot be submitted until all required documents are accepted by the Enrollment Clerk.'
            . ($documentReviewBlockers !== '' ? ' ' . $documentReviewBlockers : '');
    }

    if (!canGuardianSubmitEnrollmentPayment($record['enrollment_status'])) {
        $errors[] = 'Payment can only be submitted after the enrollment has been assessed for payment.';
    }

    if (!$hasPaymentAssessment) {
        $errors[] = 'No payment assessment has been issued for this enrollment yet.';
    }

    if ((string)($record['payment_status'] ?? 'pending') === 'paid') {
        $errors[] = 'This payment has already been verified.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $description = (string)($record['description'] ?? 'Enrollment Assessment');
            if ($notes !== '') {
                $description = 'Guardian payment reference - ' . substr($notes, 0, 120);
            }

            $paymentId = (int)($record['payment_id'] ?? 0);
            if ((string)($record['payment_status'] ?? '') === 'failed') {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (enrollment_id, amount, method, reference_no, description, status)
                    VALUES (:eid, :amount, :method, :reference_no, :description, 'pending')
                ");
                $stmt->execute([
                    ':eid' => $enrollmentId,
                    ':amount' => (float)$record['amount'],
                    ':method' => $method,
                    ':reference_no' => $referenceNo !== '' ? $referenceNo : null,
                    ':description' => $description,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET method = :method,
                        reference_no = :reference_no,
                        description = :description,
                        status = 'pending',
                        paid_at = NULL
                    WHERE id = :id
                      AND status::text <> 'paid'
                ");
                $stmt->execute([
                    ':method' => $method,
                    ':reference_no' => $referenceNo !== '' ? $referenceNo : null,
                    ':description' => $description,
                    ':id' => $paymentId,
                ]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Payment can no longer be updated.');
                }
            }

            // Move the enrollment forward to payment verification.
            $advancingStatuses = guardianPaymentSubmissionStatuses();
            $placeholders = implode(',', array_fill(0, count($advancingStatuses), '?'));
            $remark = 'Payment reference submitted by guardian; awaiting payment verification.';
            $stmt = $pdo->prepare("
                UPDATE enrollments
                SET payment_submitted_at = NOW(),
                    status = 'awaiting_payment',
                    remarks = ?
                WHERE id = ?
                  AND status::text IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$remark], [$enrollmentId], $advancingStatuses));

            $pdo->commit();

            auditLog('enrollment_payment_reference_submitted', 'enrollments', $enrollmentId, null, [
                'payment_method' => $method,
                'reference_no' => $referenceNo !== '' ? $referenceNo : null,
            ]);

            setFlash('success', 'Payment reference submitted. School staff can now verify the payment.');
            redirect(APP_URL . '/guardian/dashboard.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Enrollment payment submission error: ' . $e->getMessage());
            $errors[] = 'Unable to submit payment reference right now. Please try again.';
        }
    }

    $record = loadEnrollmentPayment($pdo, (int)$guardian['id'], $enrollmentId);
    $assessmentDetails = loadGuardianLatestSentAssessment($pdo, $enrollmentId);
    $documentReviewSummary = loadEnrollmentDocumentReviewSummary($pdo, $enrollmentId);
    $documentsReadyForPayment = (bool)$documentReviewSummary['all_accepted'];
    $documentReviewBlockers = enrollmentDocumentReviewBlockerText($documentReviewSummary);
    $hasPaymentAssessment = $assessmentDetails !== null
        && !empty($record['payment_id'])
        && (float)($record['amount'] ?? 0) > 0;
    $canSubmitPayment = $documentsReadyForPayment
        && canGuardianSubmitEnrollmentPayment($record['enrollment_status'])
        && $hasPaymentAssessment
        && (string)($record['payment_status'] ?? 'pending') !== 'paid';
}

$pageTitle = 'Enrollment Payment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h4 class="fw-bold mb-0"><i class="bi bi-credit-card me-2"></i>Payment Reference</h4>
        <p class="text-muted mb-0">Submit payment details after the Enrollment Clerk issues the assessment.</p>
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
        Payment reference was already submitted on <?= e(date('M d, Y h:i A', strtotime($record['payment_submitted_at']))) ?>.
        You may update it below before admin finalizes the request.
    </div>
<?php endif; ?>

<?php if ((string)($record['payment_status'] ?? '') === 'failed'): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-1"></i>
        The latest payment reference was not verified. Please check the details and submit an updated reference.
    </div>
<?php endif; ?>

<?php if (!$canSubmitPayment): ?>
    <div class="alert alert-warning">
        <i class="bi bi-shield-exclamation me-1"></i>
        Payment submission is locked until required documents are accepted and a payment assessment has been issued.
        <?php if ($documentReviewBlockers !== ''): ?>
            <div class="small mt-1"><?= e($documentReviewBlockers) ?></div>
        <?php endif; ?>
        <?php if (!$hasPaymentAssessment): ?>
            <div class="small mt-1">No assessed payment amount is available yet.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-2"></i>Enrollment Summary</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th>Student</th><td><?= e($record['student_name']) ?></td></tr>
                    <tr><th>Grade</th><td><?= e(formatGradeLevel((string)($record['grade_level'] ?: ''))) ?></td></tr>
                    <tr><th>LRN</th><td><?= e($record['lrn'] ?: 'N/A') ?></td></tr>
                    <tr><th>School Year</th><td><?= e($record['school_year']) ?></td></tr>
                    <tr><th>Term</th><td><?= e($record['term']) ?></td></tr>
                    <tr><th>Enrollment Status</th><td><span class="badge <?= e(enrollmentStatusBadgeClass($record['enrollment_status'])) ?>"><?= e(enrollmentStatusLabel($record['enrollment_status'])) ?></span></td></tr>
                    <tr><th>Payment Status</th><td><span class="badge <?= e(paymentStatusBadgeClass($record['payment_status'] ?? 'pending')) ?>"><?= e(paymentStatusLabel($record['payment_status'] ?? 'pending')) ?></span></td></tr>
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
                        <h6 class="fw-bold mb-2">Assessment Breakdown</h6>
                        <?php if ($assessmentDetails && !empty($assessmentDetails['items'])): ?>
                            <?php $deductionCategories = assessmentDeductionCategories(); ?>
                            <table class="table table-sm mb-0">
                                <?php foreach ($assessmentDetails['items'] as $item):
                                    $category = (string)($item['category'] ?? '');
                                    $isDeduction = in_array($category, $deductionCategories, true);
                                    $amount = (float)($item['amount'] ?? 0);
                                ?>
                                    <tr>
                                        <td>
                                            <?= e($item['description'] ?: assessmentItemCategoryLabel($category)) ?>
                                            <div class="small text-muted"><?= e(assessmentItemCategoryLabel($category)) ?></div>
                                        </td>
                                        <td class="text-end">
                                            <?= $isDeduction ? '-' : '' ?>&#8369;<?= e(number_format($amount, 2)) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="fw-bold border-top">
                                    <td>Total</td>
                                    <td class="text-end">&#8369;<?= e(number_format((float)$assessmentDetails['assessment']['total_amount'], 2)) ?></td>
                                </tr>
                            </table>
                        <?php elseif ($hasPaymentAssessment): ?>
                            <table class="table table-sm mb-0">
                                <tr>
                                    <td><?= e($record['description'] ?: 'Enrollment Assessment') ?></td>
                                    <td class="text-end">&#8369;<?= e(number_format((float)$record['amount'], 2)) ?></td>
                                </tr>
                                <tr class="fw-bold border-top">
                                    <td>Total</td>
                                    <td class="text-end">&#8369;<?= e(number_format((float)$record['amount'], 2)) ?></td>
                                </tr>
                            </table>
                        <?php else: ?>
                            <div class="text-muted small">
                                The registrar has not issued a payment assessment for this enrollment yet.
                            </div>
                        <?php endif; ?>
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
                        Submitting this reference sends it for payment verification. Enrollment is not final until school staff marks the payment as paid.
                    </div>

                    <button type="submit" class="btn btn-success" <?= $canSubmitPayment ? '' : 'disabled' ?>>
                        <i class="bi bi-send-check me-1"></i>Submit Payment Reference
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
