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

    // Decisions:
    //   save_reviews - persist per-doc reviews without changing enrollment status
    //   assess       - clerk validated docs, mark assessed_for_payment
    //   submit       - registrar submits paid enrollee to teachers
    //   return       - bounce back to guardian (any open stage)
    $validDecisions = ['save_reviews', 'assess', 'submit', 'return'];
    if ($enrollId < 1) {
        $errors[] = 'Invalid enrollment reference.';
    }
    if (!in_array($decision, $validDecisions, true)) {
        $errors[] = 'Invalid review decision.';
    }

    $allowedDocReviewStatuses = ['pending', 'accepted', 'needs_replacement'];
    $docReviewInput = is_array($_POST['doc_review'] ?? null) ? $_POST['doc_review'] : [];
    $docReviewUpdates = [];
    foreach ($requiredDocuments as $docKey => $_label) {
        $entry = $docReviewInput[$docKey] ?? null;
        if (!is_array($entry)) {
            continue;
        }
        $reviewStatus = trim((string)($entry['status'] ?? ''));
        $reviewNote   = trim((string)($entry['note'] ?? ''));
        if ($reviewStatus === '') {
            continue;
        }
        if (!in_array($reviewStatus, $allowedDocReviewStatuses, true)) {
            $errors[] = 'Invalid review status for ' . $requiredDocuments[$docKey] . '.';
            continue;
        }
        $docReviewUpdates[$docKey] = [
            'status' => $reviewStatus,
            'note'   => $reviewNote !== '' ? $reviewNote : null,
        ];
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
            $docStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT document_type)
                FROM enrollment_documents
                WHERE enrollment_id = :id
                  AND document_type IN ('psa', 'medical', 'previous_school', 'parent_data')
            ");
            $docStmt->execute([':id' => $enrollId]);
            $uploadedDocumentCount = (int)$docStmt->fetchColumn();

            if ($decision === 'assess') {
                if ($uploadedDocumentCount < count($requiredDocuments)) {
                    $errors[] = 'Cannot mark as assessed for payment: required documents are not complete.';
                }
                if (!in_array($enrollment['status'], ['documents_under_review', 'requirements_incomplete', 'returned'], true)) {
                    $errors[] = 'This enrollment is not in a stage that can be assessed.';
                }
            } elseif ($decision === 'submit') {
                if ($enrollment['status'] !== 'paid_for_registrar') {
                    $errors[] = 'This enrollment is not ready for registrar submission yet. Cashier payment has not been verified.';
                }
                if ($uploadedDocumentCount < count($requiredDocuments)) {
                    $errors[] = 'Required documents are not complete.';
                }
            } elseif ($decision === 'return') {
                if (in_array($enrollment['status'], enrollmentTerminalStatuses(), true)) {
                    $errors[] = 'This enrollment is already finalized and cannot be returned.';
                }
            }
        }

        if (empty($errors) && $enrollment) {
            $pdo->beginTransaction();

            // Persist per-document reviews (applies to every decision).
            if (!empty($docReviewUpdates)) {
                $reviewerId = $_SESSION['user_id'] ?? null;
                $reviewStmt = $pdo->prepare("
                    UPDATE enrollment_documents
                    SET review_status = :status,
                        reviewer_note = :note,
                        reviewed_by = :reviewer,
                        reviewed_at = NOW()
                    WHERE enrollment_id = :enrollment_id
                      AND document_type = :document_type
                ");
                foreach ($docReviewUpdates as $docKey => $update) {
                    $reviewStmt->execute([
                        ':status'        => $update['status'],
                        ':note'          => $update['note'],
                        ':reviewer'      => $reviewerId,
                        ':enrollment_id' => $enrollId,
                        ':document_type' => $docKey,
                    ]);
                }
            }

            // Decide the next enrollment status (or skip if save_reviews).
            $statusChange = true;
            switch ($decision) {
                case 'save_reviews':
                    $statusChange = false;
                    $auditAction = 'enrollment_doc_reviews_saved';
                    $flashMessage = 'Document reviews saved.';
                    break;
                case 'assess':
                    $newStatus = 'assessed_for_payment';
                    $enrolledAt = $enrollment['payment_submitted_at']; // unchanged
                    $auditAction = 'enrollment_assessed_for_payment';
                    $flashMessage = 'Enrollment marked as assessed for payment. Guardian can now submit payment.';
                    $defaultRemarks = 'Documents validated; payment assessment generated.';
                    break;
                case 'submit':
                    $newStatus = 'enrolled';
                    $enrolledAt = date('Y-m-d H:i:s');
                    $auditAction = 'enrollment_enrolled';
                    $flashMessage = 'Enrollment has been submitted to teachers.';
                    $defaultRemarks = 'Registrar submitted enrollee to teachers.';
                    break;
                case 'return':
                default:
                    $newStatus = 'returned';
                    $enrolledAt = null;
                    $auditAction = 'enrollment_returned';
                    $flashMessage = 'Enrollment has been returned for completion.';
                    $defaultRemarks = 'Returned by registrar for completion.';
                    break;
            }

            if ($statusChange) {
                $stmt = $pdo->prepare("
                    UPDATE enrollments
                    SET status = :status,
                        remarks = :remarks,
                        enrolled_at = CASE WHEN :status2 = 'enrolled' THEN :enrolled_at ELSE enrolled_at END
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':status' => $newStatus,
                    ':status2' => $newStatus,
                    ':remarks' => $remarks !== '' ? $remarks : $defaultRemarks,
                    ':enrolled_at' => $enrolledAt,
                    ':id' => $enrollId,
                ]);
            }

            $pdo->commit();

            auditLog($auditAction, 'enrollments', $enrollId, null, [
                'decision' => $decision,
                'remarks' => $remarks,
                'doc_reviews' => array_keys($docReviewUpdates),
            ]);

            setFlash('success', $flashMessage);
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

// Single aggregation query for KPI counts
$stats = $pdo->query("
    SELECT
        COUNT(*)                                                                  AS total,
        COUNT(*) FILTER (WHERE status = 'enrolled')                               AS enrolled,
        COUNT(*) FILTER (WHERE status = 'paid_for_registrar')                     AS paid_for_registrar,
        COUNT(*) FILTER (WHERE status = 'awaiting_payment')                       AS awaiting_payment,
        COUNT(*) FILTER (WHERE status = 'assessed_for_payment')                   AS assessed_for_payment,
        COUNT(*) FILTER (WHERE status = 'documents_under_review')                 AS documents_under_review,
        COUNT(*) FILTER (WHERE status = 'requirements_incomplete')                AS requirements_incomplete,
        COUNT(*) FILTER (WHERE status = 'submitted')                              AS submitted,
        COUNT(*) FILTER (WHERE status = 'returned')                               AS returned
    FROM enrollments
")->fetch();

$totalEnrollments        = (int)$stats['total'];
$enrolledCount           = (int)$stats['enrolled'];
$paidForRegistrarCount   = (int)$stats['paid_for_registrar'];
$awaitingPaymentCount    = (int)$stats['awaiting_payment'];
$assessedForPaymentCount = (int)$stats['assessed_for_payment'];
$documentsUnderReviewCount = (int)$stats['documents_under_review'];
$requirementsIncompleteCount = (int)$stats['requirements_incomplete'];
$submittedCount          = (int)$stats['submitted'];
$returnedCount           = (int)$stats['returned'];

$pageTitle = 'Student Enrollment';
require_once __DIR__ . '/../includes/header.php';

$avatarColors = ['bg-blue', 'bg-green', 'bg-red', 'bg-purple', 'bg-orange'];
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
    <div class="col-xl col-md-4 col-6">
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon-wrap"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="kpi-label">Total</div>
                <div class="kpi-value"><?= e(number_format($totalEnrollments)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="?status=requirements_incomplete">
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon-wrap"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="kpi-label">Requirements Incomplete</div>
                    <div class="kpi-value"><?= e(number_format($requirementsIncompleteCount)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="?status=documents_under_review">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon-wrap"><i class="bi bi-clipboard-data-fill"></i></div>
                <div>
                    <div class="kpi-label">Documents Under Review</div>
                    <div class="kpi-value"><?= e(number_format($documentsUnderReviewCount)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="?status=assessed_for_payment">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon-wrap"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="kpi-label">Assessed for Payment</div>
                    <div class="kpi-value"><?= e(number_format($assessedForPaymentCount)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="?status=awaiting_payment">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="kpi-label">Awaiting Cashier</div>
                    <div class="kpi-value"><?= e(number_format($awaitingPaymentCount)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="?status=paid_for_registrar">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon-wrap"><i class="bi bi-inbox-fill"></i></div>
                <div>
                    <div class="kpi-label">Paid - For Registrar</div>
                    <div class="kpi-value"><?= e(number_format($paidForRegistrarCount)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="?status=enrolled">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon-wrap"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="kpi-label">Enrolled</div>
                    <div class="kpi-value"><?= e(number_format($enrolledCount)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="?status=returned">
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon-wrap"><i class="bi bi-arrow-counterclockwise"></i></div>
                <div>
                    <div class="kpi-label">Returned</div>
                    <div class="kpi-value"><?= e(number_format($returnedCount)) ?></div>
                </div>
            </div>
        </a>
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
                    <?php foreach (enrollmentStatuses() as $st => $label): ?>
                        <option value="<?= e($st) ?>" <?= e($filterStatus === $st ? 'selected' : '') ?>><?= e($label) ?></option>
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
                $reviewCounts = ['accepted' => 0, 'needs_replacement' => 0, 'pending' => 0, 'missing' => 0];
                foreach ($requiredDocuments as $docKey => $docLabel) {
                    if (empty($docsByType[$docKey])) {
                        $missingDocumentLabels[] = $docLabel;
                        $reviewCounts['missing']++;
                    } else {
                        $rs = (string)($docsByType[$docKey]['review_status'] ?? 'pending');
                        if (!isset($reviewCounts[$rs])) $rs = 'pending';
                        $reviewCounts[$rs]++;
                    }
                }
                $documentsComplete = empty($missingDocumentLabels);
                $allAccepted = $documentsComplete && $reviewCounts['accepted'] === count($requiredDocuments);
                $statusValue = (string)$en['status'];

                // Which clerk decisions are available for this row?
                $canAssess = $documentsComplete && in_array($statusValue, ['documents_under_review', 'requirements_incomplete', 'returned'], true);
                $canSubmitToTeachers = $documentsComplete && $statusValue === 'paid_for_registrar';
                $canReturn = !in_array($statusValue, enrollmentTerminalStatuses(), true);
                $canSaveReviews = !in_array($statusValue, enrollmentTerminalStatuses(), true);
                $canReview = $canAssess || $canSubmitToTeachers || $canReturn || $canSaveReviews;
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
                <td><?= e(formatGradeLevel((string)($en['grade_level'] ?? ''))) ?></td>
                <td><?= e($en['school_year']) ?></td>
                <td><?= e($en['term']) ?></td>
                <td>
                    <div class="mb-2">
                        <span class="badge <?= $documentsComplete ? 'badge-status-active' : 'badge-status-inactive' ?>">
                            <?= e((string)count($docsByType)) ?>/<?= e((string)count($requiredDocuments)) ?> uploaded
                        </span>
                        <?php if ($reviewCounts['accepted'] > 0): ?>
                            <span class="badge badge-doc-review-accepted ms-1"><?= (int)$reviewCounts['accepted'] ?> accepted</span>
                        <?php endif; ?>
                        <?php if ($reviewCounts['needs_replacement'] > 0): ?>
                            <span class="badge badge-doc-review-needs-replacement ms-1"><?= (int)$reviewCounts['needs_replacement'] ?> needs replace</span>
                        <?php endif; ?>
                        <?php if ($reviewCounts['missing'] > 0): ?>
                            <span class="badge badge-doc-review-missing ms-1"><?= (int)$reviewCounts['missing'] ?> missing</span>
                        <?php endif; ?>
                    </div>
                    <div class="small">
                        <?php foreach ($requiredDocuments as $docKey => $docLabel):
                            $doc = $docsByType[$docKey] ?? null;
                            $reviewStatus = $doc ? (string)($doc['review_status'] ?? 'pending') : 'missing';
                        ?>
                            <div class="d-flex align-items-center gap-2 py-1">
                                <?php if ($doc): ?>
                                    <a class="text-truncate" style="max-width: 180px;" href="<?= e($documentUrl($doc)) ?>" target="_blank" rel="noopener" title="<?= e($docLabel) ?>">
                                        <i class="bi bi-file-earmark-text me-1"></i><?= e($docLabel) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted text-truncate" style="max-width: 180px;" title="<?= e($docLabel) ?>">
                                        <i class="bi bi-dash-circle me-1"></i><?= e($docLabel) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="badge <?= e(documentReviewStatusBadgeClass($reviewStatus)) ?> ms-auto"><?= e(documentReviewStatusLabel($reviewStatus)) ?></span>
                            </div>
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
                    <?php elseif ($statusValue === 'paid_for_registrar'): ?>
                        <span class="badge badge-status-active">Paid - Ready for Registrar</span>
                        <?php if (!empty($en['payment_submitted_at'])): ?>
                            <div class="small text-muted mt-1"><?= e(date('M d, Y h:i A', strtotime($en['payment_submitted_at']))) ?></div>
                        <?php endif; ?>
                    <?php elseif ($statusValue === 'awaiting_payment'): ?>
                        <span class="badge badge-status-awaiting-payment">Awaiting Cashier</span>
                        <div class="small text-muted mt-1">Guardian submitted payment proof. Cashier needs to verify.</div>
                    <?php elseif ($statusValue === 'assessed_for_payment'): ?>
                        <span class="badge badge-status-assessed-for-payment">Assessed - Awaiting Guardian Payment</span>
                        <div class="small text-muted mt-1">Payment slip generated. Guardian to pay.</div>
                    <?php elseif ($statusValue === 'documents_under_review'): ?>
                        <span class="badge badge-status-documents-under-review">Ready for Clerk Review</span>
                        <div class="small text-muted mt-1">All documents uploaded. Clerk to assess for payment.</div>
                    <?php else: ?>
                        <span class="badge <?= e(enrollmentStatusBadgeClass($statusValue)) ?>"><?= e(enrollmentStatusLabel($statusValue)) ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= e(enrollmentStatusBadgeClass($statusValue)) ?>"><?= e(enrollmentStatusLabel($statusValue)) ?></span></td>
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
                <div class="modal-dialog modal-lg">
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
                                    <div><span class="badge <?= e(enrollmentStatusBadgeClass($statusValue)) ?> fs-6"><?= e(enrollmentStatusLabel($statusValue)) ?></span></div>
                                </div>

                                <div class="alert alert-info small">
                                    Review the uploaded requirements. From here you can return the enrollment, mark it as assessed for payment (so the guardian can pay), or — once the cashier has verified payment — submit it to teachers.
                                </div>

                                <?php if ($canSubmitToTeachers): ?>
                                    <div class="alert alert-success small">
                                        Cashier payment is verified. This enrollment is ready to be submitted to teachers.
                                    </div>
                                <?php elseif ($canAssess): ?>
                                    <div class="alert alert-warning small">
                                        Documents are complete. Validate them and click <strong>Mark Assessed for Payment</strong> to let the guardian pay.
                                    </div>
                                <?php elseif ($statusValue === 'awaiting_payment'): ?>
                                    <div class="alert alert-warning small">
                                        Guardian has submitted payment proof. Waiting for the cashier to verify before this enrollment can be submitted to teachers.
                                    </div>
                                <?php elseif ($statusValue === 'assessed_for_payment'): ?>
                                    <div class="alert alert-warning small">
                                        Payment slip generated. Waiting for the guardian to submit payment.
                                    </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Document Review Checklist</label>
                                    <div class="small text-muted mb-2">Mark each requirement as <strong>Accepted</strong> or <strong>Needs Replacement</strong>. Add a note if the guardian needs guidance on what to fix.</div>
                                    <?php foreach ($requiredDocuments as $docKey => $docLabel):
                                        $doc = $docsByType[$docKey] ?? null;
                                        $reviewStatus = $doc ? (string)($doc['review_status'] ?? 'pending') : 'missing';
                                        $reviewerNote = $doc ? (string)($doc['reviewer_note'] ?? '') : '';
                                        $isMissing = $doc === null;
                                    ?>
                                        <div class="card mb-2">
                                            <div class="card-body py-2 px-3">
                                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                                    <div class="fw-semibold">
                                                        <i class="bi bi-file-earmark-text me-1"></i><?= e($docLabel) ?>
                                                    </div>
                                                    <span class="badge <?= e(documentReviewStatusBadgeClass($reviewStatus)) ?>"><?= e(documentReviewStatusLabel($reviewStatus)) ?></span>
                                                </div>
                                                <?php if ($doc): ?>
                                                    <div class="small mb-2">
                                                        <a href="<?= e($documentUrl($doc)) ?>" target="_blank" rel="noopener">
                                                            <i class="bi bi-box-arrow-up-right me-1"></i><?= e($doc['original_name']) ?>
                                                        </a>
                                                        <span class="text-muted ms-2">Uploaded <?= e(date('M d, Y', strtotime((string)$doc['uploaded_at']))) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="small text-muted mb-2">
                                                        <i class="bi bi-exclamation-triangle me-1"></i>This document has not been uploaded yet.
                                                    </div>
                                                <?php endif; ?>

                                                <div class="row g-2">
                                                    <div class="col-md-5">
                                                        <select class="form-select form-select-sm" name="doc_review[<?= e($docKey) ?>][status]" <?= $isMissing ? 'disabled' : '' ?>>
                                                            <option value="pending" <?= $reviewStatus === 'pending' ? 'selected' : '' ?>>Pending Review</option>
                                                            <option value="accepted" <?= $reviewStatus === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                                                            <option value="needs_replacement" <?= $reviewStatus === 'needs_replacement' ? 'selected' : '' ?>>Needs Replacement</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-7">
                                                        <input type="text" class="form-control form-control-sm" name="doc_review[<?= e($docKey) ?>][note]" value="<?= e($reviewerNote) ?>" placeholder="Optional reviewer note..." <?= $isMissing ? 'disabled' : '' ?>>
                                                    </div>
                                                </div>
                                                <?php if (!empty($doc['reviewed_at'])): ?>
                                                    <div class="small text-muted mt-1">Last reviewed <?= e(date('M d, Y h:i A', strtotime((string)$doc['reviewed_at']))) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
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
                                <?php if ($canSaveReviews): ?>
                                    <button type="submit" name="decision" value="save_reviews" class="btn btn-outline-primary">
                                        <i class="bi bi-save me-1"></i>Save Reviews
                                    </button>
                                <?php endif; ?>
                                <?php if ($canReturn): ?>
                                    <button type="submit" name="decision" value="return" class="btn btn-outline-danger">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Return
                                    </button>
                                <?php endif; ?>
                                <?php if ($canAssess): ?>
                                    <button type="submit" name="decision" value="assess" class="btn btn-warning">
                                        <i class="bi bi-cash-coin me-1"></i>Mark Assessed for Payment
                                    </button>
                                <?php endif; ?>
                                <?php if ($canSubmitToTeachers): ?>
                                    <button type="submit" name="decision" value="submit" class="btn btn-success">
                                        <i class="bi bi-send-check me-1"></i>Submit to Teachers
                                    </button>
                                <?php endif; ?>
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
