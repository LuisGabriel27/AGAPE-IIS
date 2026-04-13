<?php
/**
 * Guardian Printable Payment Receipt
 * Print-friendly receipt for a payment owned by the logged-in guardian.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$userId = $_SESSION['user_id'];
$paymentId = (int)($_GET['payment_id'] ?? 0);

if ($paymentId < 1) {
    setFlash('danger', 'Invalid payment reference.');
    redirect(APP_URL . '/guardian/payments.php');
}

$stmt = $pdo->prepare('SELECT id, full_name FROM guardians WHERE user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

if (!$guardian) {
    setFlash('danger', 'Guardian profile not found.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$stmt = $pdo->prepare("
    SELECT p.id, p.amount, p.method, p.reference_no, p.description, p.status, p.paid_at,
           e.school_year, e.term,
           s.full_name AS student_name, s.lrn
    FROM payments p
    INNER JOIN enrollments e ON e.id = p.enrollment_id
    INNER JOIN students s ON s.id = e.student_id
    WHERE p.id = :pid
      AND s.guardian_id = :gid
    LIMIT 1
");
$stmt->execute([
    ':pid' => $paymentId,
    ':gid' => $guardian['id'],
]);
$payment = $stmt->fetch();

if (!$payment) {
    setFlash('danger', 'Payment receipt not found or access is not allowed.');
    redirect(APP_URL . '/guardian/payments.php');
}

$issuedAt = $payment['paid_at'] ?: date('Y-m-d H:i:s');
$receiptNo = 'RCPT-' . date('Ymd', strtotime($issuedAt)) . '-' . str_pad((string)$payment['id'], 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?= e($receiptNo) ?></title>
    <link rel="icon" type="image/jpeg" href="<?= APP_URL ?>/assets/images/branding/agape-logo.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
        }
        .sheet {
            max-width: 820px;
            margin: 24px auto;
            background: #fff;
            border: 1px solid #dfe6ef;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        .sheet-header {
            background: #0f766e;
            color: #fff;
            padding: 18px 22px;
        }
        .school-logo {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.65);
        }
        .receipt-label {
            color: #475569;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .signature-line {
            border-top: 1px solid #64748b;
            width: 220px;
            margin-top: 42px;
            padding-top: 6px;
            font-size: 0.82rem;
            color: #475569;
            text-align: center;
        }
        @media print {
            body {
                background: #fff;
            }
            .no-print {
                display: none !important;
            }
            .sheet {
                margin: 0;
                max-width: none;
                border: none;
                box-shadow: none;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
<div class="container-fluid no-print py-3 d-flex justify-content-end gap-2">
    <button class="btn btn-outline-primary btn-sm" onclick="window.print()">Print</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/guardian/payments.php">Back</a>
</div>

<div class="sheet">
    <div class="sheet-header">
        <div class="d-flex align-items-center gap-3">
            <img src="<?= APP_URL ?>/assets/images/branding/agape-logo.jpg" alt="School Logo" class="school-logo">
            <div>
                <div class="fw-bold fs-5"><?= e(APP_NAME) ?></div>
                <div class="small">Official Payment Receipt</div>
            </div>
            <div class="ms-auto text-end small">
                <div>Receipt No: <?= e($receiptNo) ?></div>
                <div>Date Issued: <?= e(date('F d, Y', strtotime($issuedAt))) ?></div>
            </div>
        </div>
    </div>

    <div class="p-4">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="receipt-label">Received From</div>
                <div class="fw-semibold"><?= e($guardian['full_name']) ?></div>
            </div>
            <div class="col-md-6">
                <div class="receipt-label">Student</div>
                <div class="fw-semibold"><?= e($payment['student_name']) ?></div>
            </div>
            <div class="col-md-6">
                <div class="receipt-label">School Year / Term</div>
                <div><?= e($payment['school_year']) ?> / <?= e($payment['term']) ?></div>
            </div>
            <div class="col-md-6">
                <div class="receipt-label">Status</div>
                <div><?= e(ucfirst($payment['status'])) ?></div>
            </div>
        </div>

        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Description</th>
                    <th>Method</th>
                    <th>Reference No.</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= e($payment['description'] ?: 'School Payment') ?></td>
                    <td><?= e(ucfirst($payment['method'])) ?></td>
                    <td><?= e($payment['reference_no'] ?: 'N/A') ?></td>
                    <td class="text-end fw-bold">&#8369;<?= e(number_format((float)$payment['amount'], 2)) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td colspan="3" class="text-end">Total</td>
                    <td class="text-end">&#8369;<?= e(number_format((float)$payment['amount'], 2)) ?></td>
                </tr>
            </tfoot>
        </table>

        <?php if ($payment['status'] !== 'paid'): ?>
            <div class="alert alert-warning py-2 mt-3 mb-0">
                This receipt is marked as <strong><?= e(ucfirst($payment['status'])) ?></strong>.
                Final posting may still be in progress.
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-end mt-4">
            <div class="signature-line">Cashier / Authorized Signatory</div>
        </div>
    </div>
</div>
</body>
</html>
