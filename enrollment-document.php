<?php
/**
 * Protected enrollment document viewer.
 *
 * Documents may be stored in local uploads or Supabase Storage, but must never
 * be linked directly. This route enforces role and ownership checks before
 * streaming the file.
 */

require_once __DIR__ . '/includes/session-check.php';
requireRole(['admin', 'clerk', 'guardian']);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = getDB();
$documentId = (int)($_GET['id'] ?? 0);
$forceDownload = ($_GET['download'] ?? '') === '1';

if ($documentId < 1) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}

$stmt = $pdo->prepare("
    SELECT d.id, d.enrollment_id, d.document_type, d.original_name, d.file_path,
           d.mime_type, d.file_size, e.student_id, s.guardian_id
    FROM enrollment_documents d
    INNER JOIN enrollments e ON e.id = d.enrollment_id
    INNER JOIN students s ON s.id = e.student_id
    WHERE d.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $documentId]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}

$isPrivilegedStaff = hasRole('admin') || hasRole('clerk');
$isOwnerGuardian = false;
if (hasRole('guardian')) {
    $stmt = $pdo->prepare("SELECT id FROM guardians WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $_SESSION['user_id'] ?? 0]);
    $guardianId = (int)($stmt->fetchColumn() ?: 0);
    $isOwnerGuardian = $guardianId > 0 && $guardianId === (int)$document['guardian_id'];
}

if (!$isPrivilegedStaff && !$isOwnerGuardian) {
    http_response_code(403);
    echo 'You are not allowed to access this document.';
    exit;
}

$filePath = null;
$remoteFile = null;
if (enrollmentDocumentIsSupabaseStored($document)) {
    try {
        $remoteFile = downloadEnrollmentDocumentFromSupabase($document);
    } catch (Throwable $e) {
        error_log('Supabase enrollment document download failed: ' . $e->getMessage());
        http_response_code(404);
        echo 'Document file is stored in Supabase Storage, but this server is not configured to open it. Please check SUPABASE_SERVICE_ROLE_KEY and the storage bucket setup.';
        exit;
    }
} else {
    $filePath = enrollmentDocumentLocalFilePath($document);
    if ($filePath === null) {
        http_response_code(404);
        echo 'Document file is not available on this device. The database record exists, but the uploaded file must be opened from the device where it was uploaded or restored into the local uploads folder.';
        exit;
    }
}

try {
    auditLog('enrollment_document_viewed', 'enrollment_documents', (int)$document['id'], null, [
        'enrollment_id' => (int)$document['enrollment_id'],
        'document_type' => (string)$document['document_type'],
        'download' => $forceDownload,
    ]);
} catch (Exception $e) {
    error_log('Enrollment document audit error: ' . $e->getMessage());
}

$safeName = basename(str_replace('\\', '/', (string)$document['original_name']));
if ($safeName === '' || $safeName === '.' || $safeName === '..') {
    $safeName = 'enrollment-document-' . (int)$document['id'];
}
$safeName = str_replace(["\r", "\n"], '', $safeName);

$mimeType = (string)($document['mime_type'] ?? '');
$allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    $mimeType = 'application/octet-stream';
}

if (ob_get_level()) {
    ob_end_clean();
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . ($remoteFile !== null ? $remoteFile['size'] : filesize((string)$filePath)));
header(
    'Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline')
    . '; filename="' . addcslashes($safeName, "\\\"") . '"'
);

if ($remoteFile !== null) {
    echo $remoteFile['body'];
} else {
    readfile((string)$filePath);
}
exit;
