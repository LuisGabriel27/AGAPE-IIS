<?php
/**
 * Guardian Payments Page
 * Full payment history table with sorting and running total.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

// Get guardian → students → enrollments → payments
$stmt = $pdo->prepare("SELECT id FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

$payments = [];
$totalPaid = 0;
if ($guardian) {
    $sort = in_array($_GET['sort'] ?? '', ['paid_at','amount','status']) ? $_GET['sort'] : 'paid_at';
    $dir  = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    $stmt = $pdo->prepare("
        SELECT p.*, e.school_year, e.term, s.full_name AS student_name
        FROM payments p
        JOIN enrollments e ON p.enrollment_id = e.id
        JOIN students s ON e.student_id = s.id
        WHERE s.guardian_id = :gid
        ORDER BY {$sort} {$dir}
    ");
    $stmt->execute([':gid' => $guardian['id']]);
    $payments = $stmt->fetchAll();

    foreach ($payments as $pay) {
        if ($pay['status'] === 'paid') {
            $totalPaid += $pay['amount'];
        }
    }
}

$pageTitle = 'Payment History';
require_once __DIR__ . '/../includes/header.php';

function sortLink(string $col, string $label, string $currentSort, string $currentDir): string {
    $newDir = ($currentSort === $col && $currentDir === 'DESC') ? 'asc' : 'desc';
    $icon   = '';
    if ($currentSort === $col) {
        $icon = $currentDir === 'ASC' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
    }
    return '<a href="?sort=' . $col . '&dir=' . $newDir . '" class="text-decoration-none text-dark">' . $label . $icon . '</a>';
}
$currentSort = in_array($_GET['sort'] ?? '', ['paid_at','amount','status']) ? $_GET['sort'] : 'paid_at';
$currentDir  = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h4 class="fw-bold mb-0"><i class="bi bi-credit-card me-2"></i>Payment History</h4>
        <span class="badge bg-success fs-6">Total Paid: ₱<?= number_format($totalPaid, 2) ?></span>
    </div>
</div>

<div class="table-container">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="payments-table">
            <thead>
                <tr>
                    <th><?= sortLink('paid_at', 'Date', $currentSort, $currentDir) ?></th>
                    <th>Student</th>
                    <th>Description</th>
                    <th>School Year</th>
                    <th class="text-end"><?= sortLink('amount', 'Amount', $currentSort, $currentDir) ?></th>
                    <th>Method</th>
                    <th>Reference No.</th>
                    <th class="text-center"><?= sortLink('status', 'Status', $currentSort, $currentDir) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No payment records found.</td></tr>
                <?php else: ?>
                    <?php $runningTotal = 0; ?>
                    <?php foreach ($payments as $pay): ?>
                        <?php if ($pay['status'] === 'paid') $runningTotal += $pay['amount']; ?>
                    <tr>
                        <td><?= $pay['paid_at'] ? e(date('M d, Y', strtotime($pay['paid_at']))) : '—' ?></td>
                        <td><?= e($pay['student_name']) ?></td>
                        <td><?= e($pay['description'] ?? 'Payment') ?></td>
                        <td><?= e($pay['school_year']) ?> — <?= e($pay['term']) ?></td>
                        <td class="text-end fw-bold">₱<?= number_format($pay['amount'], 2) ?></td>
                        <td><?= e(ucfirst($pay['method'])) ?></td>
                        <td><?= e($pay['reference_no'] ?? 'N/A') ?></td>
                        <td class="text-center">
                            <span class="badge badge-status-<?= e($pay['status']) ?>"><?= e(ucfirst($pay['status'])) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
