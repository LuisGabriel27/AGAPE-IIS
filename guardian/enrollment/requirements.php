<?php
/**
 * Guardian Enrollment Requirements Upload
 * Lets guardians upload or replace required enrollment files for an existing enrollment.
 */

require_once __DIR__ . '/../../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = getDB();
$userId = $_SESSION['user_id'];
$enrollmentId = (int)($_GET['enrollment_id'] ?? $_POST['enrollment_id'] ?? 0);

if ($enrollmentId < 1) {
    setFlash('danger', 'Invalid enrollment reference.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

if (!$guardian) {
    setFlash('danger', 'Guardian profile not found.');
    redirect(APP_URL . '/guardian/complete-profile.php');
}

$stmt = $pdo->prepare("
    SELECT e.id AS enrollment_id, e.status, e.school_year, e.term,
           s.id AS student_id, s.first_name, s.last_name, s.grade_level, s.lrn
    FROM enrollments e
    INNER JOIN students s ON e.student_id = s.id
    WHERE e.id = :eid
      AND s.guardian_id = :gid
    LIMIT 1
");
$stmt->execute([
    ':eid' => $enrollmentId,
    ':gid' => $guardian['id'],
]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    setFlash('danger', 'Enrollment record was not found.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

if (in_array($enrollment['status'], enrollmentLockedForGuardianStatuses(), true)) {
    setFlash('info', 'This enrollment is already finalized.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$requiredDocuments = requiredEnrollmentDocumentsForGrade((string)($enrollment['grade_level'] ?? ''));
$requirementDescriptions = requiredEnrollmentDocumentDescriptions();
$allowedRequirementExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
$allowedRequirementMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
$maxRequirementFileSize = 5 * 1024 * 1024;
$errors = [];

$documentsByType = loadEnrollmentDocumentsByType($pdo, $enrollmentId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $uploadedRequirements = [];
    $fileInfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    foreach ($requiredDocuments as $docKey => $docLabel) {
        $uploadError = $_FILES['requirements']['error'][$docKey] ?? UPLOAD_ERR_NO_FILE;
        $hasExistingFile = !empty($documentsByType[$docKey]);
        $existingReviewStatus = $hasExistingFile ? (string)($documentsByType[$docKey]['review_status'] ?? 'pending') : 'missing';

        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            if (!$hasExistingFile) {
                $errors[] = $docLabel . ' is required.';
            }
            continue;
        }

        if ($hasExistingFile && $existingReviewStatus === 'accepted') {
            $errors[] = $docLabel . ' has already been accepted and cannot be replaced.';
            continue;
        }

        $originalName = (string)($_FILES['requirements']['name'][$docKey] ?? '');
        $tmpName = (string)($_FILES['requirements']['tmp_name'][$docKey] ?? '');
        $size = (int)($_FILES['requirements']['size'][$docKey] ?? 0);

        if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
            $errors[] = 'Unable to upload ' . $docLabel . '. Please try again.';
            continue;
        }

        if ($size > $maxRequirementFileSize) {
            $errors[] = $docLabel . ' must be 5MB or smaller.';
            continue;
        }

        $safeOriginal = substr(basename(str_replace('\\', '/', $originalName)), 0, 255);
        $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedRequirementExtensions, true)) {
            $errors[] = $docLabel . ' must be a PDF, JPG, JPEG, or PNG file.';
            continue;
        }

        $mimeType = $fileInfo ? (string)finfo_file($fileInfo, $tmpName) : (string)($_FILES['requirements']['type'][$docKey] ?? '');
        if (!in_array($mimeType, $allowedRequirementMimeTypes, true)) {
            $errors[] = $docLabel . ' has an unsupported file type.';
            continue;
        }

        $uploadedRequirements[$docKey] = [
            'label' => $docLabel,
            'original_name' => $safeOriginal,
            'tmp_name' => $tmpName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }
    if ($fileInfo) {
        finfo_close($fileInfo);
    }

    if (empty($errors)) {
        $movedRequirementFiles = [];
        $oldFilesToDelete = [];
        $committed = false;

        try {
            $pdo->beginTransaction();

            $docStmt = $pdo->prepare("
                INSERT INTO enrollment_documents
                    (enrollment_id, document_type, original_name, file_path, mime_type, file_size, uploaded_by, review_status, reviewer_note, reviewed_by, reviewed_at)
                VALUES
                    (:enrollment_id, :document_type, :original_name, :file_path, :mime_type, :file_size, :uploaded_by, 'pending', NULL, NULL, NULL)
                ON CONFLICT (enrollment_id, document_type)
                DO UPDATE SET
                    original_name = EXCLUDED.original_name,
                    file_path = EXCLUDED.file_path,
                    mime_type = EXCLUDED.mime_type,
                    file_size = EXCLUDED.file_size,
                    uploaded_by = EXCLUDED.uploaded_by,
                    uploaded_at = NOW(),
                    review_status = 'pending',
                    reviewer_note = NULL,
                    reviewed_by = NULL,
                    reviewed_at = NULL
            ");

            foreach ($uploadedRequirements as $docKey => $file) {
                $storedName = $docKey . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $file['extension'];
                $storedPath = storeEnrollmentDocumentUpload(
                    $file['tmp_name'],
                    $enrollmentId,
                    $storedName,
                    $file['mime_type']
                );
                $movedRequirementFiles[] = $storedPath;
                if (!empty($documentsByType[$docKey]['file_path'])) {
                    $oldFilesToDelete[] = (string)$documentsByType[$docKey]['file_path'];
                }

                $docStmt->execute([
                    ':enrollment_id' => $enrollmentId,
                    ':document_type' => $docKey,
                    ':original_name' => $file['original_name'],
                    ':file_path' => $storedPath,
                    ':mime_type' => $file['mime_type'],
                    ':file_size' => $file['size'],
                    ':uploaded_by' => $userId,
                ]);
            }

            // Recompute status based only on the documents required for this grade level.
            $documentKeysAfterUpload = array_unique(array_merge(
                array_keys($documentsByType),
                array_keys($uploadedRequirements)
            ));
            $newDocCount = count(array_intersect(array_keys($requiredDocuments), $documentKeysAfterUpload));

            $resubmittableStatuses = [
                'returned',
                'requirements_incomplete',
                'submitted',
                'documents_under_review',
                'assessed_for_payment',
                'awaiting_payment',
            ];
            if (in_array($enrollment['status'], $resubmittableStatuses, true)) {
                $newStatus = enrollmentStatusForDocumentCount($newDocCount, count($requiredDocuments));
                $resubmitRemark = $enrollment['status'] === 'returned'
                    ? 'Requirements resubmitted by guardian after return; awaiting clerk review.'
                    : 'Requirements updated by guardian; awaiting clerk review.';
                $pdo->prepare("
                    UPDATE enrollments
                    SET status = :status,
                        remarks = :remarks
                    WHERE id = :id
                ")->execute([
                    ':status'  => $newStatus,
                    ':remarks' => $resubmitRemark,
                    ':id'      => $enrollmentId,
                ]);
            }

            $pdo->commit();
            $committed = true;

            foreach ($oldFilesToDelete as $path) {
                deleteEnrollmentDocumentStoredFile($path);
            }

            try {
                auditLog('enrollment_requirements_uploaded', 'enrollment_documents', $enrollmentId, null, [
                    'documents' => array_keys($uploadedRequirements),
                ]);
            } catch (Exception $auditError) {
                error_log('Enrollment requirements audit error: ' . $auditError->getMessage());
            }

            setFlash('success', 'Enrollment requirements uploaded. The Enrollment Clerk can now review and validate them.');
            redirect(APP_URL . '/guardian/dashboard.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!$committed) {
                foreach ($movedRequirementFiles as $path) {
                    deleteEnrollmentDocumentStoredFile($path);
                }
            }
            error_log('Enrollment requirements upload error: ' . $e->getMessage());
            $errors[] = 'An error occurred while saving requirements. Please try again.';
        }
    }

    $documentsByType = loadEnrollmentDocumentsByType($pdo, $enrollmentId);
}

$pageTitle = 'Upload Requirements';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-file-earmark-arrow-up me-2"></i>Upload Enrollment Requirements</h4>
                <div class="text-muted small"><?= e(format_name($enrollment['first_name'], $enrollment['last_name'])) ?> - <?= e(formatGradeLevel((string)($enrollment['grade_level'] ?? ''))) ?></div>
            </div>
            <a href="<?= APP_URL ?>/guardian/dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
        $documentSummary = summarizeEnrollmentDocumentsByType($documentsByType, $requiredDocuments);
        $needsReplacementCount = (int)$documentSummary['counts']['needs_replacement'];
        $hasReplaceableDocuments = false;
        foreach ($requiredDocuments as $docKey => $_label) {
            $existing = $documentsByType[$docKey] ?? null;
            if (!$existing) {
                $hasReplaceableDocuments = true;
                continue;
            }
            $rs = (string)($existing['review_status'] ?? 'pending');
            if ($rs !== 'accepted') {
                $hasReplaceableDocuments = true;
            }
        }
        ?>

        <?php if ($needsReplacementCount > 0): ?>
            <div class="alert alert-warning small mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                The Enrollment Clerk has flagged <strong><?= (int)$needsReplacementCount ?></strong> file<?= $needsReplacementCount === 1 ? '' : 's' ?> for replacement. Look for the reviewer notes below and upload corrected copies.
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-4">
                <div class="alert alert-info small">
                    Upload clear copies of the required documents for this grade level. Accepted files are locked; only missing, pending, or replacement-needed files can be changed.
                </div>

                <?php if (!$hasReplaceableDocuments): ?>
                    <div class="alert alert-success small">
                        <i class="bi bi-check-circle me-1"></i>
                        All required documents have been accepted by the Enrollment Clerk.
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="enrollment_id" value="<?= (int)$enrollmentId ?>">

                    <div class="row">
                        <?php foreach ($requiredDocuments as $docKey => $docLabel):
                            $existing = $documentsByType[$docKey] ?? null;
                            $reviewStatus = $existing ? (string)($existing['review_status'] ?? 'pending') : 'missing';
                            $reviewerNote = $existing ? (string)($existing['reviewer_note'] ?? '') : '';
                            $isAccepted = $existing && $reviewStatus === 'accepted';
                        ?>
                            <div class="col-md-6 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                                    <label class="form-label mb-0">
                                        <?= e($docLabel) ?> <?= $existing ? '' : '<span class="text-danger">*</span>' ?>
                                    </label>
                                    <span class="badge <?= e(documentReviewStatusBadgeClass($reviewStatus)) ?>">
                                        <?= e(documentReviewStatusLabel($reviewStatus)) ?>
                                    </span>
                                </div>
                                <?php if ($existing): ?>
                                    <div class="small mb-2">
                                        <?php if (enrollmentDocumentIsAvailable($existing)): ?>
                                            <a href="<?= e(enrollmentDocumentUrl($existing)) ?>" target="_blank" rel="noopener">
                                                <i class="bi bi-file-earmark-text me-1"></i><?= e($existing['original_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-warning">
                                                <i class="bi bi-exclamation-triangle me-1"></i><?= e($existing['original_name']) ?>
                                            </span>
                                            <span class="text-muted ms-1">File is not available from this server. Please contact the school office.</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($reviewerNote !== ''): ?>
                                    <div class="alert alert-warning small py-2 mb-2">
                                        <i class="bi bi-chat-left-text me-1"></i><strong>Clerk note:</strong> <?= e($reviewerNote) ?>
                                    </div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="requirements[<?= e($docKey) ?>]" accept=".pdf,.jpg,.jpeg,.png" <?= $existing ? '' : 'required' ?> <?= $isAccepted ? 'disabled' : '' ?>>
                                <div class="form-text">
                                    <?= e($requirementDescriptions[$docKey] ?? '') ?>
                                    <?= $isAccepted ? ' Accepted documents are locked.' : ' PDF, JPG, JPEG, or PNG. Max 5MB.' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= APP_URL ?>/guardian/dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-success" <?= $hasReplaceableDocuments ? '' : 'disabled' ?>>
                            <i class="bi bi-upload me-1"></i>Upload Requirements for Clerk Review
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
