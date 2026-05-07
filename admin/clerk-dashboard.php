<?php
/**
 * Enrollment Clerk Dashboard
 * Pipeline KPIs and quick links to clerk workflows.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('clerk');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$schoolYear = currentSchoolYear();

$stats = $pdo->query("
    SELECT
        COUNT(*) FILTER (
            WHERE e.status IN ('submitted', 'requirements_incomplete', 'documents_under_review')
        ) AS pending_review,
        COUNT(*) FILTER (
            WHERE e.status IN ('submitted', 'requirements_incomplete')
        ) AS missing_requirements,
        COUNT(*) FILTER (
            WHERE e.status = 'documents_under_review'
        ) AS ready_for_assessment,
        COUNT(*) FILTER (
            WHERE e.status IN ('assessed_for_payment', 'awaiting_payment')
        ) AS awaiting_cashier,
        COUNT(*) FILTER (
            WHERE e.status = 'paid_for_registrar'
        ) AS ready_for_registrar,
        COUNT(*) FILTER (
            WHERE e.status = 'returned'
        ) AS returned,
        COUNT(*) FILTER (
            WHERE e.status = 'enrolled'
        ) AS completed
    FROM enrollments e
")->fetch();

$pendingReview        = (int)($stats['pending_review'] ?? 0);
$missingRequirements  = (int)($stats['missing_requirements'] ?? 0);
$readyForAssessment   = (int)($stats['ready_for_assessment'] ?? 0);
$awaitingCashier      = (int)($stats['awaiting_cashier'] ?? 0);
$readyForRegistrar    = (int)($stats['ready_for_registrar'] ?? 0);
$returnedCount        = (int)($stats['returned'] ?? 0);
$completedCount       = (int)($stats['completed'] ?? 0);

$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

$pipelineStatuses = enrollmentInPipelineStatuses();
$placeholders = implode(',', array_fill(0, count($pipelineStatuses), '?'));
$stmt = $pdo->prepare("
    SELECT e.id,
           e.status,
           e.payment_submitted_at,
           e.school_year,
           CASE
               WHEN COALESCE(s.first_name, '') = '' THEN COALESCE(s.last_name, '')
               WHEN COALESCE(s.last_name, '') = '' THEN COALESCE(s.first_name, '')
               ELSE s.last_name || ', ' || s.first_name
           END AS student_name,
           s.lrn,
           s.grade_level,
           COALESCE(d.doc_count, 0) AS doc_count
    FROM enrollments e
    INNER JOIN students s ON s.id = e.student_id
    LEFT JOIN (
        SELECT enrollment_id, COUNT(DISTINCT document_type) AS doc_count
        FROM enrollment_documents
        WHERE document_type IN ('psa', 'medical', 'previous_school', 'parent_data')
        GROUP BY enrollment_id
    ) d ON d.enrollment_id = e.id
    WHERE e.status::text IN ({$placeholders})
    ORDER BY e.id DESC
    LIMIT 8
");
$stmt->execute($pipelineStatuses);
$recentPending = $stmt->fetchAll();

$pageTitle = 'Clerk Dashboard';
require_once __DIR__ . '/../includes/header.php';

$avatarColors = ['bg-blue', 'bg-green', 'bg-red', 'bg-purple', 'bg-orange'];

$pipelineStage = static function (array $row): array {
    $status = (string)($row['status'] ?? '');
    return [enrollmentStatusLabel($status), enrollmentStatusBadgeClass($status)];
};
?>

<div class="alert alert-light border mb-4">
    <div class="fw-semibold mb-2"><i class="bi bi-clipboard2-check-fill me-1"></i>Enrollment Clerk Workspace</div>
    <div class="small text-muted">
        Active school year: <strong><?= e($schoolYear) ?></strong>.
        Track every application from document review through registrar submission.
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="<?= APP_URL ?>/admin/admin-enrollments.php?status=documents_under_review">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon-wrap"><i class="bi bi-clipboard-data-fill"></i></div>
                <div>
                    <div class="kpi-label">Pending Document Review</div>
                    <div class="kpi-value"><?= e(number_format($pendingReview)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="<?= APP_URL ?>/admin/admin-enrollments.php?status=requirements_incomplete">
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon-wrap"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="kpi-label">Missing Requirements</div>
                    <div class="kpi-value"><?= e(number_format($missingRequirements)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="<?= APP_URL ?>/admin/admin-enrollments.php?status=documents_under_review">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon-wrap"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="kpi-label">Ready for Payment Assessment</div>
                    <div class="kpi-value"><?= e(number_format($readyForAssessment)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="<?= APP_URL ?>/admin/admin-enrollments.php?status=awaiting_payment">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="kpi-label">Awaiting Cashier Payment</div>
                    <div class="kpi-value"><?= e(number_format($awaitingCashier)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="<?= APP_URL ?>/admin/admin-enrollments.php?status=paid_for_registrar">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon-wrap"><i class="bi bi-send-check-fill"></i></div>
                <div>
                    <div class="kpi-label">Paid - For Registrar Submission</div>
                    <div class="kpi-value"><?= e(number_format($readyForRegistrar)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="<?= APP_URL ?>/admin/admin-enrollments.php?status=returned">
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon-wrap"><i class="bi bi-arrow-counterclockwise"></i></div>
                <div>
                    <div class="kpi-label">Returned Applications</div>
                    <div class="kpi-value"><?= e(number_format($returnedCount)) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl col-md-4 col-6">
        <a class="text-decoration-none" href="<?= APP_URL ?>/admin/admin-enrollments.php?status=enrolled">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon-wrap"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="kpi-label">Completed / Enrolled</div>
                    <div class="kpi-value"><?= e(number_format($completedCount)) ?></div>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Recent Pending Enrollments -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-clock-history"></i>Recent Pending Applications</span>
                <a href="<?= APP_URL ?>/admin/admin-enrollments.php" class="btn btn-sm btn-outline-primary">View Queue</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentPending)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox d-block"></i>
                        <p>No pending applications.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Grade</th>
                                    <th>Docs</th>
                                    <th>Stage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentPending as $i => $row):
                                    $color = $avatarColors[$i % count($avatarColors)];
                                    $initial = strtoupper(substr($row['student_name'], 0, 1));
                                    [$stageLabel, $stageBadge] = $pipelineStage($row);
                                ?>
                                    <tr>
                                        <td>
                                            <div class="user-row">
                                                <div class="user-avatar <?= e($color) ?>"><?= e($initial) ?></div>
                                                <div>
                                                    <div class="user-name"><?= e($row['student_name']) ?></div>
                                                    <?php if (!empty($row['lrn'])): ?>
                                                        <small class="text-muted">LRN: <?= e($row['lrn']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= e(formatGradeLevel((string)($row['grade_level'] ?? ''))) ?></td>
                                        <td><?= e((string)$row['doc_count']) ?>/4</td>
                                        <td><span class="badge <?= e($stageBadge) ?>"><?= e($stageLabel) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-lightning-charge-fill"></i>Quick Actions</span>
            </div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="<?= APP_URL ?>/admin/admin-enrollments.php" class="quick-action">
                    <div class="qa-icon" style="background:var(--primary-light);color:var(--primary);">
                        <i class="bi bi-pencil-square"></i>
                    </div>
                    <div>
                        <div class="qa-text">Enrollment Queue</div>
                        <div class="qa-sub"><?= e((string)$pendingReview) ?> pending applications</div>
                    </div>
                </a>
                <a href="<?= APP_URL ?>/admin/admin-students.php" class="quick-action">
                    <div class="qa-icon" style="background:var(--info-light);color:var(--info);">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="qa-text">Student Records</div>
                        <div class="qa-sub"><?= e((string)$totalStudents) ?> students on file</div>
                    </div>
                </a>
                <a href="<?= APP_URL ?>/admin/admin-enrollments.php?status=documents_under_review" class="quick-action">
                    <div class="qa-icon" style="background:var(--warning-light);color:var(--warning-dark);">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                    <div>
                        <div class="qa-text">Payment / Assessment Workflow</div>
                        <div class="qa-sub"><?= e((string)$readyForAssessment) ?> ready, <?= e((string)$awaitingCashier) ?> at cashier</div>
                    </div>
                </a>
                <a href="<?= APP_URL ?>/admin/admin-enrollments.php?status=paid_for_registrar" class="quick-action">
                    <div class="qa-icon" style="background:var(--success-light);color:var(--success);">
                        <i class="bi bi-send-check-fill"></i>
                    </div>
                    <div>
                        <div class="qa-text">Submit to Registrar</div>
                        <div class="qa-sub"><?= e((string)$readyForRegistrar) ?> awaiting submission</div>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
