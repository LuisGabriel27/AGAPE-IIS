<?php
/**
 * Admin Enrollments
 * Registrar enrollment queue: intake, document requirements, payment assessment,
 * cashier payment, and final submission to teachers.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin', 'clerk']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$filterStatus = $_GET['status'] ?? '';
$filterYear   = $_GET['year'] ?? '';
$search       = trim($_GET['search'] ?? '');
$errors = [];
$requiredDocuments = [
    'psa' => 'PSA Birth Certificate',
    'medical' => 'Medical Records',
    'previous_school' => 'Previous School Records',
    'parent_data' => 'Parent / Guardian Data',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $enrollId = (int)($_POST['enrollment_id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($enrollId < 1) {
        $errors[] = 'Invalid enrollment reference.';
    }

    if (!in_array($decision, ['submit', 'return', 'approve', 'decline'], true)) {
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
        } else {
            $isSubmit = in_array($decision, ['submit', 'approve'], true);
            $docStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT document_type)
                FROM enrollment_documents
                WHERE enrollment_id = :id
                  AND document_type IN ('psa', 'medical', 'previous_school', 'parent_data')
            ");
            $docStmt->execute([':id' => $enrollId]);
            $uploadedDocumentCount = (int)$docStmt->fetchColumn();

            if ($isSubmit && $uploadedDocumentCount < count($requiredDocuments)) {
                $errors[] = 'This enrollment is not ready for submission. Required documents are not complete.';
            } elseif ($isSubmit && empty($enrollment['payment_submitted_at'])) {
                $errors[] = 'This enrollment is not ready for registrar submission yet. Cashier payment has not been recorded.';
            }
        }

        if (empty($errors) && $enrollment) {
            $isSubmit = in_array($decision, ['submit', 'approve'], true);
            $newStatus = $isSubmit ? 'enrolled' : 'rejected';
            $enrolledAt = $newStatus === 'enrolled' ? date('Y-m-d H:i:s') : null;
            $defaultRemarks = $isSubmit
                ? 'Registrar submitted enrollee to teachers.'
                : 'Returned by registrar for completion.';

            $stmt = $pdo->prepare("
                UPDATE enrollments
                SET status = :status,
                    remarks = :remarks,
                    enrolled_at = :enrolled_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $newStatus,
                ':remarks' => $remarks !== '' ? $remarks : $defaultRemarks,
                ':enrolled_at' => $enrolledAt,
                ':id' => $enrollId,
            ]);

            auditLog('enrollment_' . $newStatus, 'enrollments', $enrollId, null, [
                'decision' => $decision,
                'remarks' => $remarks,
            ]);

            setFlash('success', $isSubmit
                ? 'Enrollment has been submitted to teachers.'
                : 'Enrollment has been returned for completion.');
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
if ($search !== '') {
    $where[] = '(s.last_name ILIKE :search OR s.first_name ILIKE :search2 OR s.lrn ILIKE :search3)';
    $params[':search']  = "{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':search3'] = "%{$search}%";
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments e INNER JOIN students s ON e.student_id = s.id {$whereSQL}");
$totalStmt->execute($params);
[$offset, $limit, $page, $totalPages] = paginate((int)$totalStmt->fetchColumn(), 15);

$stmt = $pdo->prepare("
    SELECT e.*,
           CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student_name,
           s.lrn AS student_lrn, s.grade_level,
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
    ORDER BY s.last_name, s.first_name, e.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$enrollments = $stmt->fetchAll();
$documentsByEnrollment = [];
if (!empty($enrollments)) {
    $enrollmentIds = array_map(static fn(array $row): int => (int)$row['id'], $enrollments);
    $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
    $docStmt = $pdo->prepare("
        SELECT *
        FROM enrollment_documents
        WHERE enrollment_id IN ({$placeholders})
        ORDER BY enrollment_id, document_type
    ");
    $docStmt->execute($enrollmentIds);
    foreach ($docStmt->fetchAll() as $doc) {
        $documentsByEnrollment[(int)$doc['enrollment_id']][] = $doc;
    }
}

$years = $pdo->query("SELECT DISTINCT school_year FROM enrollments ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// Single aggregation query instead of 6 separate round trips
$stats = $pdo->query("
    SELECT
        COUNT(*)                                                          AS total,
        COUNT(*) FILTER (WHERE status IN ('approved', 'enrolled'))       AS submitted,
        COUNT(*) FILTER (WHERE status = 'pending')                      AS pending,
        COUNT(*) FILTER (WHERE status = 'pending'
                           AND payment_submitted_at IS NOT NULL)         AS for_review,
        COUNT(*) FILTER (WHERE status = 'pending'
                           AND payment_submitted_at IS NULL)             AS awaiting_payment,
        COUNT(*) FILTER (WHERE status = 'rejected')                     AS rejected
    FROM enrollments
")->fetch();

$totalEnrollments     = (int)$stats['total'];
$submittedCount       = (int)$stats['submitted'];
$pendingCount         = (int)$stats['pending'];
$forReviewCount       = (int)$stats['for_review'];
$awaitingPaymentCount = (int)$stats['awaiting_payment'];
$rejectedCount        = (int)$stats['rejected'];

$pageTitle = 'Student Enrollment';
require_once __DIR__ . '/../includes/header.php';

$avatarColors = ['bg-blue', 'bg-green', 'bg-red', 'bg-purple', 'bg-orange'];
$statusLabels = [
    'pending' => 'In Process',
    'approved' => 'Submitted to Teachers',
    'enrolled' => 'Submitted to Teachers',
    'rejected' => 'Returned',
    'archived' => 'Archived',
];
$statusLabel = static fn(string $status): string => $statusLabels[$status] ?? ucfirst($status);
$documentUrl = static fn(array $doc): string => APP_URL . '/' . ltrim((string)$doc['file_path'], '/');
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="alert alert-light border mb-4">
    <div class="fw-semibold mb-2"><i class="bi bi-diagram-3 me-1"></i>Enrollment Chain</div>
    <div class="row g-3 small">
        <div class="col-md-3"><strong>1. Guardian Uploads</strong><br><span class="text-muted">Guardian submits enrollment and uploads all required files.</span></div>
        <div class="col-md-3"><strong>2. Clerk Assessment</strong><br><span class="text-muted">Enrollment Clerk checks PSA, medical records, previous school records, and parent data, then assesses payment.</span></div>
        <div class="col-md-3"><strong>3. Cashier Payment</strong><br><span class="text-muted">Enrollee proceeds to the treasurer / cashier for payment.</span></div>
        <div class="col-md-3"><strong>4. Submit to Teachers</strong><br><span class="text-muted">After payment, registrar submits the enrollee to teachers.</span></div>
    </div>
</div>

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
                <div class="kpi-label">Paid - For Registrar</div>
                <div class="kpi-value"><?= e(number_format($forReviewCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="kpi-label">For Cashier</div>
                <div class="kpi-value"><?= e(number_format($awaitingPaymentCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-success">
            <div class="kpi-icon-wrap"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="kpi-label">Submitted</div>
                <div class="kpi-value"><?= e(number_format($submittedCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-danger">
            <div class="kpi-icon-wrap"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="kpi-label">Returned</div>
                <div class="kpi-value"><?= e(number_format($rejectedCount)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-3 col-6">
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon-wrap"><i class="bi bi-list-check"></i></div>
            <div>
                <div class="kpi-label">In Process</div>
                <div class="kpi-value"><?= e(number_format($pendingCount)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-center" id="enrollment-filter">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Search by student name or LRN..."
                       value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['pending','enrolled','approved','rejected','archived'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= e($filterStatus === $st ? 'selected' : '') ?>><?= e($statusLabel($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="year">
                    <option value="">All Years</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= e($y) ?>" <?= e($filterYear === $y ? 'selected' : '') ?>><?= e($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-search me-1"></i>Search</button>
            </div>
            <?php if ($filterStatus || $filterYear || $search): ?>
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
                <th>Requirements</th>
                <th>Cashier Payment</th>
                <th>Registrar Step</th>
                <th>Status</th>
                <th>Remarks</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($enrollments)): ?>
                <tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox d-block"></i><p>No enrollments found.</p></div></td></tr>
            <?php else: foreach ($enrollments as $i => $en):
                $color = $avatarColors[$i % count($avatarColors)];
                $initial = strtoupper(substr($en['student_name'], 0, 1));
                $docs = $documentsByEnrollment[(int)$en['id']] ?? [];
                $docsByType = [];
                foreach ($docs as $doc) {
                    $docsByType[(string)$doc['document_type']] = $doc;
                }
                $missingDocumentLabels = [];
                foreach ($requiredDocuments as $docKey => $docLabel) {
                    if (empty($docsByType[$docKey])) {
                        $missingDocumentLabels[] = $docLabel;
                    }
                }
                $documentsComplete = empty($missingDocumentLabels);
                $isReadyForReview = !empty($en['payment_submitted_at']);
                $canReview = $documentsComplete && in_array($en['status'], ['pending', 'approved', 'rejected'], true);
            ?>
            <tr>
                <td><?= e((string)($offset + $i + 1)) ?></td>
                <td>
                    <div class="user-row">
                        <div class="user-avatar <?= e($color) ?>"><?= e($initial) ?></div>
                        <div>
                            <div class="user-name"><?= e($en['student_name']) ?></div>
                            <?php if (!empty($en['student_lrn'])): ?>
                                <small class="text-muted">LRN: <?= e($en['student_lrn']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>Grade <?= e($en['grade_level'] ?? 'N/A') ?></td>
                <td><?= e($en['school_year']) ?></td>
                <td><?= e($en['term']) ?></td>
                <td>
                    <span class="badge <?= $documentsComplete ? 'badge-status-active' : 'badge-status-inactive' ?>">
                        <?= e((string)count($docsByType)) ?>/<?= e((string)count($requiredDocuments)) ?> uploaded
                    </span>
                    <div class="small mt-1">
                        <?php foreach ($requiredDocuments as $docKey => $docLabel): ?>
                            <?php if (!empty($docsByType[$docKey])): $doc = $docsByType[$docKey]; ?>
                                <a class="d-block" href="<?= e($documentUrl($doc)) ?>" target="_blank" rel="noopener">
                                    <i class="bi bi-file-earmark-text me-1"></i><?= e($docLabel) ?>
                                </a>
                            <?php else: ?>
                                <span class="d-block text-muted"><i class="bi bi-dash-circle me-1"></i><?= e($docLabel) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </td>
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
                    <?php if (!$documentsComplete): ?>
                        <span class="badge badge-status-inactive">Waiting for Requirements</span>
                        <div class="small text-muted mt-1"><?= e(implode(', ', $missingDocumentLabels)) ?></div>
                    <?php elseif ($isReadyForReview): ?>
                        <span class="badge badge-status-active">Paid - Ready for Registrar</span>
                        <div class="small text-muted mt-1"><?= e(date('M d, Y h:i A', strtotime($en['payment_submitted_at']))) ?></div>
                    <?php else: ?>
                        <span class="badge badge-status-pending">Requirements Uploaded</span>
                        <div class="small text-muted mt-1">Ready for clerk assessment and cashier payment.</div>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-status-<?= e($en['status']) ?>"><?= e($statusLabel($en['status'])) ?></span></td>
                <td><small class="text-muted"><?= e($en['remarks'] ?? '') ?></small></td>
                <td>
                    <?php if ($canReview): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reviewModal<?= (int)$en['id'] ?>" title="Review Requirements">
                            <i class="bi bi-clipboard-check me-1"></i>Review
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
                            <h5 class="modal-title">Enrollment Review - <?= e($en['student_name']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="enrollment_id" value="<?= (int)$en['id'] ?>">

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Current Status</label>
                                    <div><span class="badge badge-status-<?= e($en['status']) ?> fs-6"><?= e($statusLabel($en['status'])) ?></span></div>
                                </div>

                                <div class="alert alert-info small">
                                    Review the uploaded requirements. You may return the enrollment for corrections, or submit it to teachers after cashier payment is recorded.
                                </div>

                                <?php if (!$isReadyForReview): ?>
                                    <div class="alert alert-warning small">
                                        Cashier payment has not been recorded yet. Final submission is locked until the cashier marks the assessment payment as paid.
                                    </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Uploaded Requirements</label>
                                    <div class="list-group small">
                                        <?php foreach ($requiredDocuments as $docKey => $docLabel): $doc = $docsByType[$docKey] ?? null; ?>
                                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                               href="<?= $doc ? e($documentUrl($doc)) : '#' ?>"
                                               target="_blank"
                                               rel="noopener">
                                                <span><i class="bi bi-file-earmark-text me-1"></i><?= e($docLabel) ?></span>
                                                <span class="text-muted"><?= $doc ? e($doc['original_name']) : 'Missing' ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Cashier Payment Snapshot</label>
                                    <div class="small text-muted">
                                        Amount: <?= !empty($en['payment_amount']) ? '&#8369;' . e(number_format((float)$en['payment_amount'], 2)) : 'N/A' ?><br>
                                        Method: <?= e(ucfirst($en['payment_method'] ?? 'N/A')) ?><br>
                                        Reference: <?= e($en['payment_reference_no'] ?: 'N/A') ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Remarks</label>
                                    <textarea class="form-control" name="remarks" rows="3" placeholder="Optional registrar remarks."><?= e($en['remarks'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="decision" value="return" class="btn btn-outline-danger">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Return
                                </button>
                                <button type="submit" name="decision" value="submit" class="btn btn-success" <?= $isReadyForReview ? '' : 'disabled' ?>>
                                    <i class="bi bi-send-check me-1"></i>Submit to Teachers
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

<?= paginationLinks($page, $totalPages, '?status=' . urlencode($filterStatus) . '&year=' . urlencode($filterYear) . '&search=' . urlencode($search)) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
