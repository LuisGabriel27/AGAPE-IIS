<?php
/**
 * Enrollment Payment Assessment
 *
 * Clerk builds a line-item breakdown (tuition, fees, discounts, scholarships)
 * for an enrollment, computes the total, and sends it for payment verification.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin', 'clerk']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$enrollmentId = (int)($_GET['enrollment_id'] ?? $_POST['enrollment_id'] ?? 0);
if ($enrollmentId < 1) {
    setFlash('danger', 'Invalid enrollment reference.');
    redirect(APP_URL . '/admin/admin-enrollments.php');
}

// ── Load enrollment + student context ───────────────────────
$stmt = $pdo->prepare("
    SELECT e.id, e.status, e.school_year, e.term, e.payment_submitted_at, e.remarks,
           CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student_name,
           s.lrn, s.grade_level
    FROM enrollments e
    INNER JOIN students s ON s.id = e.student_id
    WHERE e.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $enrollmentId]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    setFlash('danger', 'Enrollment record was not found.');
    redirect(APP_URL . '/admin/admin-enrollments.php');
}

$categories = assessmentItemCategories();
$deductionCategories = assessmentDeductionCategories();
$requiredDocuments = requiredEnrollmentDocumentsForGrade((string)($enrollment['grade_level'] ?? ''));
$documentReviewSummary = loadEnrollmentDocumentReviewSummary($pdo, $enrollmentId, $requiredDocuments);
$documentsReadyForAssessment = (bool)$documentReviewSummary['all_accepted'];
$documentReviewBlockers = enrollmentDocumentReviewBlockerText($documentReviewSummary);
$errors = [];

/**
 * Load latest assessment + items for an enrollment, or null if none exists.
 * @return array{assessment: array, items: array<int, array>}|null
 */
$loadLatestAssessment = static function (PDO $pdo, int $enrollmentId): ?array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM enrollment_assessments
        WHERE enrollment_id = :eid
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':eid' => $enrollmentId]);
    $assessment = $stmt->fetch();
    if (!$assessment) {
        return null;
    }
    $itemStmt = $pdo->prepare("
        SELECT id, category, description, amount, sort_order
        FROM enrollment_assessment_items
        WHERE assessment_id = :aid
        ORDER BY sort_order, id
    ");
    $itemStmt->execute([':aid' => (int)$assessment['id']]);
    return [
        'assessment' => $assessment,
        'items'      => $itemStmt->fetchAll(),
    ];
};

// POST: save draft or send for payment verification.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $action = trim($_POST['action'] ?? '');
    if (!in_array($action, ['save_draft', 'send_to_cashier'], true)) {
        $errors[] = 'Unknown action.';
    }

    $notes = trim((string)($_POST['notes'] ?? ''));
    $submittedItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];

    // Normalize line items.
    $cleanItems = [];
    $subtotal = 0.0;
    $deductions = 0.0;
    foreach ($submittedItems as $idx => $row) {
        if (!is_array($row)) continue;
        $category = trim((string)($row['category'] ?? ''));
        $description = trim((string)($row['description'] ?? ''));
        $amountRaw = (string)($row['amount'] ?? '');
        $amount = $amountRaw === '' ? 0.0 : (float)$amountRaw;

        // Skip blank rows the user added but never filled in.
        if ($category === '' && $description === '' && $amount === 0.0) {
            continue;
        }

        if (!array_key_exists($category, $categories)) {
            $errors[] = 'Unknown category for line ' . ((int)$idx + 1) . '.';
            continue;
        }
        if ($amount < 0) {
            $errors[] = 'Line ' . ((int)$idx + 1) . ' amount cannot be negative.';
            continue;
        }

        $cleanItems[] = [
            'category'    => $category,
            'description' => $description !== '' ? $description : $categories[$category],
            'amount'      => round($amount, 2),
            'sort_order'  => count($cleanItems),
        ];

        if (in_array($category, $deductionCategories, true)) {
            $deductions += $amount;
        } else {
            $subtotal += $amount;
        }
    }
    $total = max(0.0, $subtotal - $deductions);

    if ($action === 'send_to_cashier') {
        if (!$documentsReadyForAssessment) {
            $errors[] = 'Payment assessment cannot be sent until all required documents are accepted.'
                . ($documentReviewBlockers !== '' ? ' ' . $documentReviewBlockers : '');
        }
        if (empty($cleanItems)) {
            $errors[] = 'Add at least one line item before sending for payment verification.';
        }
        if ($total <= 0) {
            $errors[] = 'Total assessed amount must be greater than zero before sending.';
        }
        if (in_array($enrollment['status'], enrollmentTerminalStatuses(), true)) {
            $errors[] = 'This enrollment is already finalized; assessment cannot be sent.';
        }
        if (!canSendEnrollmentAssessment($enrollment['status'])) {
            $errors[] = 'Payment assessment can only be sent before guardian payment verification begins.';
        }
        $existingForValidation = $loadLatestAssessment($pdo, $enrollmentId);
        if (
            $existingForValidation
            && (string)$existingForValidation['assessment']['status'] === 'sent_to_cashier'
            && in_array((string)$enrollment['status'], ['assessed_for_payment', 'awaiting_payment'], true)
        ) {
            $errors[] = 'This assessment was already sent for payment. It cannot be edited after payment begins.';
        }
        $latestPaymentForValidation = latestPaymentForEnrollment($pdo, $enrollmentId);
        if ($latestPaymentForValidation && (string)$latestPaymentForValidation['status'] === 'paid') {
            $errors[] = 'This enrollment already has a verified payment. Assessment changes are not allowed.';
        }
    } elseif (!canSendEnrollmentAssessment($enrollment['status'])) {
        $errors[] = 'Assessment drafts can only be edited before payment verification begins.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Find or create the active assessment.
            $existing = $loadLatestAssessment($pdo, $enrollmentId);
            $assessmentId = null;
            if ($existing && $existing['assessment']['status'] === 'draft') {
                $assessmentId = (int)$existing['assessment']['id'];
            } elseif ($existing && $existing['assessment']['status'] === 'sent_to_cashier' && $action === 'save_draft') {
                // Don't allow editing a sent assessment; create a new draft instead.
                $errors[] = 'Latest assessment was already sent for payment verification and cannot be edited. Create a new one if needed.';
            }

            if (empty($errors)) {
                if ($assessmentId === null) {
                    $insStmt = $pdo->prepare("
                        INSERT INTO enrollment_assessments
                            (enrollment_id, status, total_amount, notes, created_by)
                        VALUES
                            (:eid, 'draft', :total, :notes, :uid)
                        RETURNING id
                    ");
                    $insStmt->execute([
                        ':eid'   => $enrollmentId,
                        ':total' => $total,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':uid'   => $_SESSION['user_id'] ?? null,
                    ]);
                    $assessmentId = (int)$insStmt->fetchColumn();
                } else {
                    $updStmt = $pdo->prepare("
                        UPDATE enrollment_assessments
                        SET total_amount = :total,
                            notes = :notes,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $updStmt->execute([
                        ':total' => $total,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':id'    => $assessmentId,
                    ]);
                }

                // Replace items: simplest correct approach for an editable list.
                $pdo->prepare("DELETE FROM enrollment_assessment_items WHERE assessment_id = :aid")
                    ->execute([':aid' => $assessmentId]);
                if (!empty($cleanItems)) {
                    $itemIns = $pdo->prepare("
                        INSERT INTO enrollment_assessment_items
                            (assessment_id, category, description, amount, sort_order)
                        VALUES (:aid, :cat, :desc, :amt, :sort)
                    ");
                    foreach ($cleanItems as $it) {
                        $itemIns->execute([
                            ':aid'  => $assessmentId,
                            ':cat'  => $it['category'],
                            ':desc' => $it['description'],
                            ':amt'  => $it['amount'],
                            ':sort' => $it['sort_order'],
                        ]);
                    }
                }

                if ($action === 'send_to_cashier') {
                    // Lock the assessment.
                    $pdo->prepare("
                        UPDATE enrollment_assessments
                        SET status = 'sent_to_cashier',
                            sent_to_cashier_at = NOW(),
                            sent_by = :uid,
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':uid' => $_SESSION['user_id'] ?? null,
                        ':id'  => $assessmentId,
                    ]);

                    // Sync the existing non-final payment row to the assessed
                    // total, or create one if none exists.
                    $latestPayment = latestPaymentForEnrollment($pdo, $enrollmentId);
                    if ($latestPayment && (string)$latestPayment['status'] === 'paid') {
                        throw new RuntimeException('Payment is already verified; assessment cannot be changed.');
                    }
                    if ($latestPayment) {
                        $pdo->prepare("
                            UPDATE payments
                            SET amount = :amt,
                                method = 'cash',
                                reference_no = NULL,
                                description = :desc,
                                status = 'pending',
                                paid_at = NULL
                            WHERE id = :id
                        ")->execute([
                            ':amt'  => $total,
                            ':desc' => 'Enrollment Assessment',
                            ':id'   => (int)$latestPayment['id'],
                        ]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO payments
                                (enrollment_id, amount, method, description, status)
                            VALUES (:eid, :amt, 'cash', 'Enrollment Assessment', 'pending')
                        ")->execute([
                            ':eid' => $enrollmentId,
                            ':amt' => $total,
                        ]);
                    }

                    // Advance the enrollment to assessed_for_payment only while
                    // it is still before payment verification.
                    $assessmentStatuses = enrollmentAssessmentEditableStatuses();
                    $assessmentParams = [];
                    $assessmentPlaceholders = [];
                    foreach ($assessmentStatuses as $idx => $stageStatus) {
                        $param = ':stage_status_' . $idx;
                        $assessmentPlaceholders[] = $param;
                        $assessmentParams[$param] = $stageStatus;
                    }
                    $assessmentPlaceholdersSql = implode(',', $assessmentPlaceholders);
                    $assessmentRemark = 'Payment assessment issued (PHP ' . number_format($total, 2) . ').';
                    $stmt = $pdo->prepare("
                        UPDATE enrollments
                        SET status = 'assessed_for_payment',
                            remarks = :remarks
                        WHERE id = :id
                          AND status::text IN ({$assessmentPlaceholdersSql})
                    ");
                    $stmt->execute(array_merge([
                        ':remarks' => $assessmentRemark,
                        ':id'    => $enrollmentId,
                    ], $assessmentParams));
                }
            }

            if (empty($errors)) {
                $pdo->commit();
                auditLog(
                    $action === 'send_to_cashier' ? 'enrollment_assessment_sent' : 'enrollment_assessment_saved',
                    'enrollment_assessments',
                    (int)$assessmentId,
                    null,
                    ['enrollment_id' => $enrollmentId, 'total' => $total]
                );
                setFlash('success', $action === 'send_to_cashier'
                    ? 'Assessment sent for payment verification. Guardian can now submit payment details.'
                    : 'Assessment draft saved.');

                if ($action === 'send_to_cashier') {
                    redirect(APP_URL . '/admin/admin-enrollments.php');
                }
                redirect(APP_URL . '/admin/enrollment-assessment.php?enrollment_id=' . $enrollmentId);
            } else {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logException($e, 'Assessment save failed.', ['enrollment_id' => $enrollmentId]);
            $errors[] = safeErrorMessage('Could not save the assessment.');
        }
    }
}

// ── Load (possibly just-saved) assessment for display ───────
$current = $loadLatestAssessment($pdo, $enrollmentId);
$displayItems = [];
$displayNotes = '';
$assessmentStatus = null;
$assessmentSentAt = null;
if ($current) {
    $displayItems = $current['items'];
    $displayNotes = (string)($current['assessment']['notes'] ?? '');
    $assessmentStatus = (string)$current['assessment']['status'];
    $assessmentSentAt = $current['assessment']['sent_to_cashier_at'] ?? null;
}

// If POST validation failed, re-display what the clerk typed instead of DB state.
if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayItems = [];
    foreach (($_POST['items'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $displayItems[] = [
            'category'    => (string)($row['category'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'amount'      => (string)($row['amount'] ?? ''),
        ];
    }
    $displayNotes = (string)($_POST['notes'] ?? '');
}

// Default starter rows for a fresh assessment.
if (empty($displayItems)) {
    $displayItems = [
        ['category' => 'tuition',        'description' => 'Tuition Fee',         'amount' => ''],
        ['category' => 'enrollment_fee', 'description' => 'Enrollment Fee',      'amount' => ''],
        ['category' => 'miscellaneous',  'description' => 'Miscellaneous Fee',   'amount' => ''],
    ];
}

$isLocked = $assessmentStatus === 'sent_to_cashier';
$pageTitle = 'Payment Assessment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-cash-coin me-2"></i>Payment Assessment</h4>
        <div class="text-muted small">
            <?= e($enrollment['student_name']) ?>
            <?php if (!empty($enrollment['lrn'])): ?> &middot; LRN: <?= e($enrollment['lrn']) ?><?php endif; ?>
            &middot; <?= e(formatGradeLevel((string)$enrollment['grade_level'])) ?>
            &middot; <?= e($enrollment['school_year']) ?> <?= e(normalizeAcademicTerm($enrollment['term'] ?? '')) ?>
        </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge <?= e(enrollmentStatusBadgeClass((string)$enrollment['status'])) ?>"><?= e(enrollmentStatusLabel((string)$enrollment['status'])) ?></span>
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/admin-enrollments.php"><i class="bi bi-arrow-left me-1"></i>Back to Queue</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$documentsReadyForAssessment): ?>
    <div class="alert alert-warning">
        <i class="bi bi-shield-exclamation me-1"></i>
        Payment assessment is locked until the clerk accepts every required document.
        <?php if ($documentReviewBlockers !== ''): ?>
            <div class="small mt-1"><?= e($documentReviewBlockers) ?></div>
        <?php endif; ?>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/admin/admin-enrollments.php?status=documents_under_review">
                Review documents
            </a>
        </div>
    </div>
<?php endif; ?>

<?php if ($isLocked): ?>
    <div class="alert alert-info">
        <i class="bi bi-lock me-1"></i>
        This assessment was sent for payment verification on <?= e($assessmentSentAt ? date('M d, Y h:i A', strtotime((string)$assessmentSentAt)) : 'an earlier date') ?>. It can be viewed but not edited.
    </div>
<?php endif; ?>

<form method="POST" id="assessment-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="enrollment_id" value="<?= (int)$enrollmentId ?>">

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-1"></i>Assessment Line Items</span>
            <?php if (!$isLocked): ?>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-row">
                    <i class="bi bi-plus-lg me-1"></i>Add Line Item
                </button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="items-table">
                    <thead>
                        <tr>
                            <th style="width: 220px;">Category</th>
                            <th>Description</th>
                            <th style="width: 180px;">Amount (₱)</th>
                            <?php if (!$isLocked): ?><th style="width: 60px;"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                        <?php foreach ($displayItems as $rowIdx => $item):
                            $cat = (string)($item['category'] ?? '');
                            $desc = (string)($item['description'] ?? '');
                            $amt  = (string)($item['amount'] ?? '');
                            $isDeduction = in_array($cat, $deductionCategories, true);
                        ?>
                            <tr class="item-row" data-deduction="<?= $isDeduction ? '1' : '0' ?>">
                                <td>
                                    <select class="form-select form-select-sm item-category" name="items[<?= (int)$rowIdx ?>][category]" <?= $isLocked ? 'disabled' : '' ?>>
                                        <?php foreach ($categories as $catKey => $catLabel): ?>
                                            <option value="<?= e($catKey) ?>" <?= $cat === $catKey ? 'selected' : '' ?>><?= e($catLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" name="items[<?= (int)$rowIdx ?>][description]" value="<?= e($desc) ?>" placeholder="e.g. SY 2025-2026 tuition" <?= $isLocked ? 'readonly' : '' ?>>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm item-amount" name="items[<?= (int)$rowIdx ?>][amount]" value="<?= e($amt) ?>" placeholder="0.00" <?= $isLocked ? 'readonly' : '' ?>>
                                </td>
                                <?php if (!$isLocked): ?>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Remove line" aria-label="Remove assessment line">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2" class="text-end">Subtotal (charges)</th>
                            <td><span id="subtotal-display">₱0.00</span></td>
                            <?php if (!$isLocked): ?><td></td><?php endif; ?>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-end">Deductions (discounts &amp; scholarships)</th>
                            <td>− <span id="deductions-display">₱0.00</span></td>
                            <?php if (!$isLocked): ?><td></td><?php endif; ?>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-end fs-5">Total Assessed Amount</th>
                            <td class="fw-bold fs-5"><span id="total-display">₱0.00</span></td>
                            <?php if (!$isLocked): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-chat-left-text me-1"></i>Clerk Notes</div>
        <div class="card-body">
            <textarea class="form-control" name="notes" rows="3" placeholder="Optional notes to admin or guardian." <?= $isLocked ? 'readonly' : '' ?>><?= e($displayNotes) ?></textarea>
        </div>
    </div>

    <?php if (!$isLocked): ?>
    <div class="d-flex justify-content-end gap-2 mb-4">
        <button type="submit" name="action" value="save_draft" class="btn btn-outline-primary">
            <i class="bi bi-save me-1"></i>Save Draft
        </button>
        <button type="submit" name="action" value="send_to_cashier" class="btn btn-success" id="btn-send-cashier" <?= $documentsReadyForAssessment ? '' : 'disabled' ?>>
            <i class="bi bi-send-check me-1"></i>Send for Payment
        </button>
    </div>
    <?php endif; ?>
</form>

<script>
(function () {
    const deductionCategories = <?= json_encode(array_values($deductionCategories), JSON_UNESCAPED_SLASHES) ?>;
    const categoryOptions = <?= json_encode($categories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const peso = (n) => '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const tbody = document.getElementById('items-body');
    const subtotalEl = document.getElementById('subtotal-display');
    const deductionsEl = document.getElementById('deductions-display');
    const totalEl = document.getElementById('total-display');
    let nextRowIndex = tbody.querySelectorAll('.item-row').length;

    function recompute() {
        let subtotal = 0;
        let deductions = 0;
        tbody.querySelectorAll('.item-row').forEach((row) => {
            const cat = row.querySelector('.item-category')?.value ?? '';
            const amount = parseFloat(row.querySelector('.item-amount')?.value || '0') || 0;
            const isDeduction = deductionCategories.includes(cat);
            row.dataset.deduction = isDeduction ? '1' : '0';
            if (isDeduction) deductions += amount;
            else subtotal += amount;
        });
        subtotalEl.textContent = peso(subtotal);
        deductionsEl.textContent = peso(deductions);
        totalEl.textContent = peso(Math.max(0, subtotal - deductions));
    }

    function buildRow(idx) {
        const tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.dataset.deduction = '0';

        const optionsHtml = Object.entries(categoryOptions)
            .map(([k, v]) => `<option value="${k}">${v}</option>`)
            .join('');

        tr.innerHTML = `
            <td>
                <select class="form-select form-select-sm item-category" name="items[${idx}][category]">
                    ${optionsHtml}
                </select>
            </td>
            <td><input type="text" class="form-control form-control-sm" name="items[${idx}][description]" placeholder="Description"></td>
            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm item-amount" name="items[${idx}][amount]" placeholder="0.00"></td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Remove line" aria-label="Remove assessment line">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        `;
        return tr;
    }

    document.getElementById('btn-add-row')?.addEventListener('click', () => {
        tbody.appendChild(buildRow(nextRowIndex++));
        recompute();
    });

    tbody.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-remove-row');
        if (!btn) return;
        btn.closest('.item-row')?.remove();
        recompute();
    });

    tbody.addEventListener('input', (e) => {
        if (e.target.classList.contains('item-amount') || e.target.classList.contains('item-category')) {
            recompute();
        }
    });
    tbody.addEventListener('change', (e) => {
        if (e.target.classList.contains('item-category')) recompute();
    });

    recompute();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
