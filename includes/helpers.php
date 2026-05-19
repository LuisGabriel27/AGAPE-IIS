<?php
/**
 * Shared Helper Functions
 */

require_once __DIR__ . '/db.php';

/**
 * Escape output for HTML context.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect helper.
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Write an entry to the audit log.
 */
function auditLog(string $action, ?string $table = null, ?int $recordId = null, $oldValue = null, $newValue = null): void
{
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO audit_log (user_id, action, table_affected, record_id, old_value, new_value, ip_address, timestamp)
        VALUES (:uid, :action, :tbl, :rid, :old, :new, :ip, NOW())
    ");
    $stmt->execute([
        ':uid'    => $_SESSION['user_id'] ?? null,
        ':action' => $action,
        ':tbl'    => $table,
        ':rid'    => $recordId,
        ':old'    => $oldValue ? json_encode($oldValue) : null,
        ':new'    => $newValue ? json_encode($newValue) : null,
        ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    ]);
}

/**
 * Normalize login/account emails before validation and storage.
 */
function normalizeEmailAddress(string $email): string
{
    return strtolower(trim($email));
}

function nullIfBlank(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

/**
 * Find an existing student with the same LRN or exact full name.
 */
function findDuplicateStudent(PDO $pdo, string $firstName, string $lastName, string $lrn = '', int $excludeStudentId = 0): ?array
{
    $excludeSql = $excludeStudentId > 0 ? ' AND id <> :exclude_id' : '';

    $lrn = trim($lrn);
    if ($lrn !== '') {
        $params = [':lrn' => $lrn];
        if ($excludeStudentId > 0) {
            $params[':exclude_id'] = $excludeStudentId;
        }

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, lrn
            FROM students
            WHERE lrn = :lrn{$excludeSql}
            LIMIT 1
        ");
        $stmt->execute($params);
        $student = $stmt->fetch();
        if ($student) {
            $student['duplicate_type'] = 'lrn';
            return $student;
        }
    }

    $firstName = trim($firstName);
    $lastName = trim($lastName);
    if ($firstName !== '' && $lastName !== '') {
        $params = [
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
        ];
        if ($excludeStudentId > 0) {
            $params[':exclude_id'] = $excludeStudentId;
        }

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, lrn
            FROM students
            WHERE LOWER(TRIM(COALESCE(first_name, ''))) = LOWER(TRIM(:first_name))
              AND LOWER(TRIM(COALESCE(last_name, ''))) = LOWER(TRIM(:last_name))
              {$excludeSql}
            LIMIT 1
        ");
        $stmt->execute($params);
        $student = $stmt->fetch();
        if ($student) {
            $student['duplicate_type'] = 'name';
            return $student;
        }
    }

    return null;
}

function findUserByEmail(PDO $pdo, string $email): ?array
{
    $email = normalizeEmailAddress($email);
    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE LOWER(TRIM(email)) = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function findTeacherProfileByUserId(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT t.*, u.email
        FROM teachers t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);
    $teacher = $stmt->fetch();

    return $teacher ?: null;
}

function findGuardianProfileByUserId(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT g.*, u.email
        FROM guardians g
        INNER JOIN users u ON u.id = g.user_id
        WHERE g.user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);
    $guardian = $stmt->fetch();

    return $guardian ?: null;
}

/**
 * Find a likely duplicate teacher by identity details.
 * Same name alone is not enough; same contact or same linked email is.
 */
function findDuplicateTeacherProfile(
    PDO $pdo,
    string $firstName,
    string $lastName,
    string $contact = '',
    string $email = '',
    int $excludeTeacherId = 0
): ?array {
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $contact = trim($contact);
    $email = normalizeEmailAddress($email);

    if ($lastName === '') {
        return null;
    }

    $params = [
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':contact' => $contact,
        ':email' => $email,
    ];
    $excludeSql = '';
    if ($excludeTeacherId > 0) {
        $excludeSql = ' AND t.id <> :exclude_id';
        $params[':exclude_id'] = $excludeTeacherId;
    }

    $stmt = $pdo->prepare("
        SELECT t.*, u.email
        FROM teachers t
        INNER JOIN users u ON u.id = t.user_id
        WHERE LOWER(TRIM(COALESCE(t.first_name, ''))) = LOWER(TRIM(:first_name))
          AND LOWER(TRIM(COALESCE(t.last_name, ''))) = LOWER(TRIM(:last_name))
          AND (
              (:contact <> '' AND LOWER(TRIM(COALESCE(t.contact_number, ''))) = LOWER(TRIM(:contact)))
              OR (:email <> '' AND LOWER(TRIM(u.email)) = :email)
          )
          {$excludeSql}
        LIMIT 1
    ");
    $stmt->execute($params);
    $teacher = $stmt->fetch();

    return $teacher ?: null;
}

/**
 * Find a likely duplicate guardian by identity details.
 * Same name alone is not enough; same contact or same linked email is.
 */
function findDuplicateGuardianProfile(
    PDO $pdo,
    string $firstName,
    string $lastName,
    string $contact = '',
    string $email = '',
    int $excludeGuardianId = 0
): ?array {
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $contact = trim($contact);
    $email = normalizeEmailAddress($email);

    if ($lastName === '') {
        return null;
    }

    $params = [
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':contact' => $contact,
        ':email' => $email,
    ];
    $excludeSql = '';
    if ($excludeGuardianId > 0) {
        $excludeSql = ' AND g.id <> :exclude_id';
        $params[':exclude_id'] = $excludeGuardianId;
    }

    $stmt = $pdo->prepare("
        SELECT g.*, u.email
        FROM guardians g
        INNER JOIN users u ON u.id = g.user_id
        WHERE LOWER(TRIM(COALESCE(g.first_name, ''))) = LOWER(TRIM(:first_name))
          AND LOWER(TRIM(COALESCE(g.last_name, ''))) = LOWER(TRIM(:last_name))
          AND (
              (:contact <> '' AND LOWER(TRIM(COALESCE(g.contact_number, ''))) = LOWER(TRIM(:contact)))
              OR (:email <> '' AND LOWER(TRIM(u.email)) = :email)
          )
          {$excludeSql}
        LIMIT 1
    ");
    $stmt->execute($params);
    $guardian = $stmt->fetch();

    return $guardian ?: null;
}

/**
 * Roles supported by the application.
 *
 * @return array<int, string>
 */
function validUserRoles(): array
{
    return ['admin', 'clerk', 'teacher', 'guardian'];
}

function isValidUserRole(string $role): bool
{
    return in_array($role, validUserRoles(), true);
}

/**
 * Normalize role values and keep a predictable display order.
 *
 * @param array<int, mixed> $roles
 * @return array<int, string>
 */
function normalizeUserRoles(array $roles): array
{
    $seen = [];
    foreach ($roles as $role) {
        $role = (string)$role;
        if (isValidUserRole($role)) {
            $seen[$role] = true;
        }
    }

    return array_values(array_filter(validUserRoles(), static fn(string $role): bool => isset($seen[$role])));
}

/**
 * Read a user's roles from user_roles. Falls back to users.role only for
 * legacy rows that have not been seeded into user_roles yet.
 *
 * @return array<int, string>
 */
function getUserRolesForUser(PDO $pdo, int $userId, ?string $fallbackRole = null, bool $repairMissingFallback = false): array
{
    $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid ORDER BY role");
    $stmt->execute([':uid' => $userId]);
    $roles = normalizeUserRoles($stmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($roles) && $fallbackRole !== null && isValidUserRole($fallbackRole)) {
        if ($repairMissingFallback) {
            ensureUserRole($pdo, $userId, $fallbackRole);
        }
        return [$fallbackRole];
    }

    return $roles;
}

function ensureUserRole(PDO $pdo, int $userId, string $role): void
{
    if (!isValidUserRole($role)) {
        throw new InvalidArgumentException('Invalid user role.');
    }

    $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (:uid, :role) ON CONFLICT DO NOTHING");
    $stmt->execute([':uid' => $userId, ':role' => $role]);
}

function syncPrimaryUserRole(PDO $pdo, int $userId, string $role): void
{
    if (!isValidUserRole($role)) {
        throw new InvalidArgumentException('Invalid user role.');
    }

    $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :uid");
    $stmt->execute([':role' => $role, ':uid' => $userId]);
    ensureUserRole($pdo, $userId, $role);
}

function removeSecondaryUserRole(PDO $pdo, int $userId, string $role): bool
{
    if (!isValidUserRole($role)) {
        throw new InvalidArgumentException('Invalid user role.');
    }

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $primaryRole = (string)$stmt->fetchColumn();
    if ($role === $primaryRole) {
        return false;
    }

    $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid AND role = :role");
    $stmt->execute([':uid' => $userId, ':role' => $role]);
    return true;
}

/**
 * Simple pagination helper. Returns [offset, limit, currentPage, totalPages].
 */
function paginate(int $totalRecords, int $perPage = 10, string $pageParam = 'page'): array
{
    $totalPages  = max(1, (int)ceil($totalRecords / $perPage));
    $currentPage = max(1, min($totalPages, (int)($_GET[$pageParam] ?? 1)));
    $offset      = ($currentPage - 1) * $perPage;
    return [$offset, $perPage, $currentPage, $totalPages];
}

/**
 * Render Bootstrap pagination links.
 */
function paginationLinks(int $currentPage, int $totalPages, string $baseUrl): string
{
    if ($totalPages <= 1) return '';

    $html = '<nav><ul class="pagination justify-content-center">';

    // Previous
    $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
    $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="' . e($baseUrl) . '&page=' . ($currentPage - 1) . '">&laquo;</a></li>';

    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . e($baseUrl) . '&page=' . $i . '">' . $i . '</a></li>';
    }

    // Next
    $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
    $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="' . e($baseUrl) . '&page=' . ($currentPage + 1) . '">&raquo;</a></li>';

    $html .= '</ul></nav>';
    return $html;
}

/**
 * Flash message helper — set.
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Flash message helper — display and clear.
 */
function displayFlash(): string
{
    if (empty($_SESSION['flash'])) return '';
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);

    $icons = [
        'success' => 'bi-check-circle-fill',
        'danger'  => 'bi-exclamation-triangle-fill',
        'warning' => 'bi-exclamation-circle-fill',
        'info'    => 'bi-info-circle-fill',
    ];
    $type = (string)($f['type'] ?? 'info');
    if (!isset($icons[$type])) {
        $type = 'info';
    }
    $role = in_array($type, ['danger', 'warning'], true) ? 'alert' : 'status';

    return '<div class="app-toast-zone">'
         . '<div class="app-toast app-toast-' . e($type) . '" role="' . $role . '" aria-live="polite">'
         . '<span class="app-toast-icon"><i class="bi ' . $icons[$type] . '"></i></span>'
         . '<div class="app-toast-message">' . e($f['message']) . '</div>'
         . '<button type="button" class="app-toast-close" aria-label="Dismiss notification">&times;</button>'
         . '</div>'
         . '</div>';
}

/**
 * Render a consistent, friendly empty-state block: an icon, a primary
 * message, and an optional plain-language hint telling the user who can
 * resolve the missing data (no technical details).
 *
 * @param string $message Primary line, e.g. "No grades to show yet".
 * @param string $hint    Who can fix it, e.g. "Grades appear once the teacher publishes them."
 * @param string $icon    Bootstrap icon class, e.g. "bi-card-checklist".
 */
function emptyStateHtml(string $message, string $hint = '', string $icon = 'bi-inbox'): string
{
    $html  = '<div class="empty-state">';
    $html .= '<i class="bi ' . e($icon) . ' d-block"></i>';
    $html .= '<p>' . e($message) . '</p>';
    if ($hint !== '') {
        $html .= '<p class="empty-state-hint">' . e($hint) . '</p>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * emptyStateHtml() wrapped in a full-width table row so it can drop straight
 * into a <tbody> without breaking the column layout.
 */
function emptyStateRow(int $colspan, string $message, string $hint = '', string $icon = 'bi-inbox'): string
{
    return '<tr><td colspan="' . max(1, $colspan) . '">'
        . emptyStateHtml($message, $hint, $icon)
        . '</td></tr>';
}

/**
 * Read a value from the settings table.
 */
function getSettingValue(string $key, ?string $default = null): ?string
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT \"value\" FROM settings WHERE \"key\" = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Upsert a value in the settings table.
 */
function setSettingValue(string $key, string $value): void
{
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO settings (\"key\", \"value\") VALUES (:key, :value) ON CONFLICT (\"key\") DO UPDATE SET \"value\" = EXCLUDED.\"value\"");
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

/**
 * Global attendance module switch.
 */
function attendanceModuleEnabled(): bool
{
    $value = strtolower(trim((string)getSettingValue('attendance_module_enabled', '1')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Get current school year string from the settings table.
 * Result is cached in $_SESSION for the request lifetime.
 * Falls back to date-based calculation if settings table is unavailable.
 */
function currentSchoolYear(): string
{
    // Check session cache first
    if (!empty($_SESSION['_cached_school_year'])) {
        return $_SESSION['_cached_school_year'];
    }

    $result = getSettingValue('active_school_year');
    if (!empty($result)) {
        $_SESSION['_cached_school_year'] = $result;
        return $result;
    }

    // Fallback: date-based calculation
    $year = (int)date('Y');
    $month = (int)date('m');
    if ($month >= 6) {
        $sy = $year . '-' . ($year + 1);
    } else {
        $sy = ($year - 1) . '-' . $year;
    }
    $_SESSION['_cached_school_year'] = $sy;
    return $sy;
}

/**
 * Returns "Last, First" display format. Falls back to last_name alone for single-word names.
 */
function format_name(?string $first, ?string $last): string
{
    $first = trim((string)$first);
    $last  = trim((string)$last);

    if ($last === '') {
        return $first;
    }

    return $first !== '' ? $last . ', ' . $first : $last;
}

/**
 * Splits a "Full Name" string into [first_name, last_name].
 * Single-word: ['', 'Word'].  Multi-word: ['First', 'rest of name'].
 *
 * @return array{0: string, 1: string}
 */
function splitName(string $full): array
{
    $full = trim($full);
    $pos  = strpos($full, ' ');
    if ($pos === false) {
        return ['', $full];
    }
    return [substr($full, 0, $pos), trim(substr($full, $pos + 1))];
}

/**
 * Canonical ordered list of enrollment workflow statuses with display labels.
 *
 * @return array<string, string>
 */
function enrollmentStatuses(): array
{
    return [
        'submitted'                => 'Submitted',
        'requirements_incomplete'  => 'Requirements Incomplete',
        'documents_under_review'   => 'Documents Under Review',
        'assessed_for_payment'     => 'Assessed for Payment',
        'awaiting_payment'         => 'Awaiting Payment Verification',
        'paid_for_registrar'       => 'Paid - For Registrar',
        'enrolled'                 => 'Enrolled',
        'returned'                 => 'Returned',
        'archived'                 => 'Archived',
    ];
}

/**
 * Display label for any enrollment status, including legacy values.
 */
function enrollmentStatusLabel(?string $status): string
{
    $status = (string)$status;
    $labels = enrollmentStatuses() + [
        // Legacy fallbacks (pre-v7 rows that have not yet been remapped)
        'pending'  => 'In Process',
        'approved' => 'Paid - For Registrar',
        'rejected' => 'Returned',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Bootstrap badge class for an enrollment status.
 */
function enrollmentStatusBadgeClass(?string $status): string
{
    return match ((string)$status) {
        'submitted'                => 'badge-status-submitted',
        'requirements_incomplete'  => 'badge-status-requirements-incomplete',
        'documents_under_review'   => 'badge-status-documents-under-review',
        'assessed_for_payment'     => 'badge-status-assessed-for-payment',
        'awaiting_payment'         => 'badge-status-awaiting-payment',
        'paid_for_registrar'       => 'badge-status-paid-for-registrar',
        'enrolled'                 => 'badge-status-enrolled',
        'returned'                 => 'badge-status-returned',
        'archived'                 => 'badge-status-archived',
        // Legacy fallbacks
        'pending'                  => 'badge-status-submitted',
        'approved'                 => 'badge-status-paid-for-registrar',
        'rejected'                 => 'badge-status-returned',
        default                    => 'badge-status-submitted',
    };
}

/**
 * Statuses that mean the enrollment can no longer be edited by the guardian
 * (already submitted to teachers, archived, or in payment verification).
 *
 * @return list<string>
 */
function enrollmentLockedForGuardianStatuses(): array
{
    return ['paid_for_registrar', 'enrolled', 'archived'];
}

/**
 * Statuses where a registrar/clerk has finished with the enrollment.
 *
 * @return list<string>
 */
function enrollmentTerminalStatuses(): array
{
    return ['enrolled', 'archived'];
}

/**
 * Statuses considered open work-in-progress for the clerk pipeline.
 *
 * @return list<string>
 */
function enrollmentInPipelineStatuses(): array
{
    return [
        'submitted',
        'requirements_incomplete',
        'documents_under_review',
        'assessed_for_payment',
        'awaiting_payment',
        'paid_for_registrar',
        'returned',
    ];
}

/**
 * Assessment line-item categories with display labels.
 *
 * @return array<string, string>
 */
function assessmentItemCategories(): array
{
    return [
        'tuition'        => 'Tuition',
        'enrollment_fee' => 'Enrollment Fee',
        'miscellaneous'  => 'Miscellaneous Fee',
        'discount'       => 'Discount',
        'scholarship'    => 'Scholarship',
        'other'          => 'Other Fee',
    ];
}

/**
 * Categories that subtract from the assessed total instead of adding to it.
 *
 * @return list<string>
 */
function assessmentDeductionCategories(): array
{
    return ['discount', 'scholarship'];
}

function assessmentItemCategoryLabel(?string $category): string
{
    $labels = assessmentItemCategories();
    return $labels[(string)$category] ?? ucfirst(str_replace('_', ' ', (string)$category));
}

function assessmentStatusLabel(?string $status): string
{
    return match ((string)$status) {
        'draft'           => 'Draft',
        'sent_to_cashier' => 'Sent for Payment',
        'cancelled'       => 'Cancelled',
        default           => 'No Assessment',
    };
}

function assessmentStatusBadgeClass(?string $status): string
{
    return match ((string)$status) {
        'sent_to_cashier' => 'badge-status-paid-for-registrar',
        'draft'           => 'badge-doc-review-pending',
        'cancelled'       => 'badge-status-archived',
        default           => 'badge-doc-review-missing',
    };
}

/**
 * Payment methods allowed by the current Supabase enum.
 *
 * @return array<string, string>
 */
function paymentMethods(): array
{
    return [
        'cash'   => 'Cash',
        'online' => 'Online Payment',
        'bank'   => 'Bank Transfer',
    ];
}

/**
 * Payment statuses allowed by the current Supabase enum.
 *
 * @return array<string, string>
 */
function paymentStatuses(): array
{
    return [
        'pending' => 'Pending',
        'paid'    => 'Paid',
        'failed'  => 'Failed',
    ];
}

function paymentStatusLabel(?string $status): string
{
    $labels = paymentStatuses();
    return $labels[(string)$status] ?? ucfirst(str_replace('_', ' ', (string)$status));
}

function paymentStatusBadgeClass(?string $status): string
{
    return match ((string)$status) {
        'paid'    => 'badge-status-active',
        'failed'  => 'badge-status-returned',
        'pending' => 'badge-status-awaiting-payment',
        default   => 'badge-status-inactive',
    };
}

/**
 * Enrollment statuses that can still receive or update a payment assessment.
 *
 * @return list<string>
 */
function enrollmentAssessmentEditableStatuses(): array
{
    return [
        'submitted',
        'requirements_incomplete',
        'documents_under_review',
        'returned',
        'assessed_for_payment',
    ];
}

function canSendEnrollmentAssessment(?string $status): bool
{
    return in_array((string)$status, enrollmentAssessmentEditableStatuses(), true);
}

/**
 * Enrollment statuses where a guardian can submit or resubmit payment proof.
 *
 * @return list<string>
 */
function guardianPaymentSubmissionStatuses(): array
{
    return ['assessed_for_payment', 'awaiting_payment'];
}

function canGuardianSubmitEnrollmentPayment(?string $status): bool
{
    return in_array((string)$status, guardianPaymentSubmissionStatuses(), true);
}

/**
 * Enrollment statuses where admin/treasurer can verify a payment.
 *
 * @return list<string>
 */
function paymentVerificationStatuses(): array
{
    return ['assessed_for_payment', 'awaiting_payment'];
}

function canVerifyEnrollmentPayment(?string $status): bool
{
    return in_array((string)$status, paymentVerificationStatuses(), true);
}

/**
 * Fetch the latest payment row for one enrollment.
 *
 * @return array<string, mixed>|null
 */
function latestPaymentForEnrollment(PDO $pdo, int $enrollmentId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM payments
        WHERE enrollment_id = :eid
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':eid' => $enrollmentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Per-document review checklist statuses.
 *
 * @return array<string, string>
 */
function documentReviewStatuses(): array
{
    return [
        'pending'           => 'Pending Review',
        'accepted'          => 'Accepted',
        'needs_replacement' => 'Needs Replacement',
        'missing'           => 'Missing',
    ];
}

/**
 * Display label for a document review status. Treats null/empty as 'missing'
 * so "no row" naturally renders as a missing document.
 */
function documentReviewStatusLabel(?string $status): string
{
    $status = $status === null || $status === '' ? 'missing' : $status;
    $labels = documentReviewStatuses();
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function documentReviewStatusBadgeClass(?string $status): string
{
    $status = $status === null || $status === '' ? 'missing' : $status;
    return match ($status) {
        'accepted'          => 'badge-doc-review-accepted',
        'needs_replacement' => 'badge-doc-review-needs-replacement',
        'missing'           => 'badge-doc-review-missing',
        'pending'           => 'badge-doc-review-pending',
        default             => 'badge-doc-review-pending',
    };
}

/**
 * Canonical required enrollment documents.
 *
 * @return array<string, string>
 */
function requiredEnrollmentDocuments(): array
{
    return [
        'psa'             => 'PSA Birth Certificate',
        'medical'         => 'Medical Records',
        'previous_school' => 'Previous School Records',
        'parent_data'     => 'Parent / Guardian Data',
    ];
}

/**
 * Load enrollment documents keyed by document_type.
 *
 * @return array<string, array>
 */
function loadEnrollmentDocumentsByType(PDO $pdo, int $enrollmentId): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM enrollment_documents
        WHERE enrollment_id = :id
        ORDER BY document_type
    ");
    $stmt->execute([':id' => $enrollmentId]);

    $documents = [];
    foreach ($stmt->fetchAll() as $doc) {
        $documents[(string)$doc['document_type']] = $doc;
    }
    return $documents;
}

/**
 * Summarize whether required enrollment documents are uploaded and accepted.
 *
 * @param array<string, array> $documentsByType
 * @param array<string, string>|null $requiredDocuments
 * @return array<string, mixed>
 */
function summarizeEnrollmentDocumentsByType(array $documentsByType, ?array $requiredDocuments = null): array
{
    $requiredDocuments = $requiredDocuments ?? requiredEnrollmentDocuments();
    $counts = [
        'accepted' => 0,
        'needs_replacement' => 0,
        'pending' => 0,
        'missing' => 0,
    ];
    $labelsByStatus = [
        'accepted' => [],
        'needs_replacement' => [],
        'pending' => [],
        'missing' => [],
    ];
    $uploadedCount = 0;

    foreach ($requiredDocuments as $docKey => $docLabel) {
        $doc = $documentsByType[$docKey] ?? null;
        if (!$doc) {
            $counts['missing']++;
            $labelsByStatus['missing'][] = $docLabel;
            continue;
        }

        $uploadedCount++;
        $status = (string)($doc['review_status'] ?? 'pending');
        if (!array_key_exists($status, $counts) || $status === 'missing') {
            $status = 'pending';
        }

        $counts[$status]++;
        $labelsByStatus[$status][] = $docLabel;
    }

    return [
        'required_count' => count($requiredDocuments),
        'uploaded_count' => $uploadedCount,
        'counts' => $counts,
        'labels_by_status' => $labelsByStatus,
        'missing_labels' => $labelsByStatus['missing'],
        'pending_labels' => $labelsByStatus['pending'],
        'needs_replacement_labels' => $labelsByStatus['needs_replacement'],
        'all_uploaded' => $uploadedCount === count($requiredDocuments),
        'all_accepted' => $uploadedCount === count($requiredDocuments)
            && $counts['accepted'] === count($requiredDocuments),
    ];
}

/**
 * Load and summarize required enrollment document review state.
 *
 * @param array<string, string>|null $requiredDocuments
 * @return array<string, mixed>
 */
function loadEnrollmentDocumentReviewSummary(PDO $pdo, int $enrollmentId, ?array $requiredDocuments = null): array
{
    return summarizeEnrollmentDocumentsByType(
        loadEnrollmentDocumentsByType($pdo, $enrollmentId),
        $requiredDocuments
    );
}

/**
 * Human-readable reason an enrollment is not ready for payment assessment.
 */
function enrollmentDocumentReviewBlockerText(array $summary): string
{
    $parts = [];
    if (!empty($summary['missing_labels'])) {
        $parts[] = 'Missing: ' . implode(', ', $summary['missing_labels']);
    }
    if (!empty($summary['pending_labels'])) {
        $parts[] = 'Pending review: ' . implode(', ', $summary['pending_labels']);
    }
    if (!empty($summary['needs_replacement_labels'])) {
        $parts[] = 'Needs replacement: ' . implode(', ', $summary['needs_replacement_labels']);
    }

    return implode('; ', $parts);
}

/**
 * Build the protected URL for an enrollment document.
 */
function enrollmentDocumentUrl(array $document, bool $download = false): string
{
    $id = (int)($document['id'] ?? 0);
    $query = ['id' => $id];
    if ($download) {
        $query['download'] = '1';
    }
    return APP_URL . '/enrollment-document.php?' . http_build_query($query);
}

/**
 * Pick the right pipeline status given the current document upload count
 * (0..4). Used when guardians create or re-upload requirements.
 */
function enrollmentStatusForDocumentCount(int $documentCount, int $required = 4): string
{
    if ($documentCount <= 0) {
        return 'submitted';
    }
    if ($documentCount >= $required) {
        return 'documents_under_review';
    }
    return 'requirements_incomplete';
}

/**
 * Grade levels offered by the system from preschool through elementary.
 *
 * @return array<string, string>
 */
function basicEducationGradeLevels(): array
{
    return [
        'Preschool' => 'Preschool',
        'Kindergarten' => 'Kindergarten',
        '1' => 'Grade 1',
        '2' => 'Grade 2',
        '3' => 'Grade 3',
        '4' => 'Grade 4',
        '5' => 'Grade 5',
        '6' => 'Grade 6',
    ];
}

function formatGradeLevel(?string $gradeLevel): string
{
    $gradeLevel = trim((string)$gradeLevel);
    if ($gradeLevel === '') {
        return 'N/A';
    }

    $levels = basicEducationGradeLevels();
    return $levels[$gradeLevel] ?? $gradeLevel;
}

/**
 * DepEd K-12 grading periods used by basic education report cards.
 *
 * @return array<string, string>
 */
function gradingPeriods(): array
{
    return [
        'quarter1' => '1st Grading Period',
        'quarter2' => '2nd Grading Period',
        'quarter3' => '3rd Grading Period',
        'quarter4' => '4th Grading Period',
    ];
}

function normalizeGradingPeriod(?string $period): string
{
    $period = (string)$period;
    return array_key_exists($period, gradingPeriods()) ? $period : 'quarter1';
}

/**
 * @param array<string, mixed> $grades
 */
function finalRatingFromQuarterGrades(array $grades): ?float
{
    $values = [];
    foreach (array_keys(gradingPeriods()) as $column) {
        if (!isset($grades[$column]) || $grades[$column] === '' || $grades[$column] === null || !is_numeric($grades[$column])) {
            return null;
        }
        $values[] = (float)$grades[$column];
    }

    return round(array_sum($values) / count($values), 2);
}

function depedDescriptor(?float $grade): string
{
    if ($grade === null) {
        return 'Pending';
    }
    if ($grade >= 90) {
        return 'Outstanding';
    }
    if ($grade >= 85) {
        return 'Very Satisfactory';
    }
    if ($grade >= 80) {
        return 'Satisfactory';
    }
    if ($grade >= 75) {
        return 'Fairly Satisfactory';
    }
    return 'Did Not Meet Expectations';
}

function depedRemark(?float $grade): string
{
    if ($grade === null) {
        return 'Pending';
    }
    return $grade >= 75 ? 'Passed' : 'Failed';
}

function depedRemarkBadgeClass(?float $grade): string
{
    if ($grade === null) {
        return 'bg-secondary';
    }
    return $grade >= 75 ? 'bg-success' : 'bg-danger';
}

/**
 * Generate standard DepEd calendar events for a given school year.
 * School year format: "2025-2026"
 */
function generateSchoolYearCalendar(string $schoolYear): array
{
    [$startYear, $endYear] = explode('-', $schoolYear);
    $startYear = (int)$startYear;
    $endYear = (int)$endYear;

    return [
        // School Year Start
        [
            'title' => 'Start of School Year ' . $schoolYear,
            'date_start' => sprintf('%04d-06-09', $startYear),
            'date_end' => sprintf('%04d-06-09', $startYear),
            'type' => 'event',
            'description' => 'Official start of classes for SY ' . $schoolYear
        ],
        // Brigada Eskwela
        [
            'title' => 'Brigada Eskwela (Volunteer Teachers\' Month)',
            'date_start' => sprintf('%04d-06-09', $startYear),
            'date_end' => sprintf('%04d-06-13', $startYear),
            'type' => 'event',
            'description' => 'National Schools Maintenance Week and Volunteer Teachers\' Month'
        ],
        // Nutrition Month
        [
            'title' => 'Nutrition Month',
            'date_start' => sprintf('%04d-07-01', $startYear),
            'date_end' => sprintf('%04d-07-31', $startYear),
            'type' => 'event',
            'description' => 'National Nutrition Month celebration'
        ],
        // Mid-Year Break
        [
            'title' => 'Mid-Year Break',
            'date_start' => sprintf('%04d-07-21', $startYear),
            'date_end' => sprintf('%04d-08-01', $startYear),
            'type' => 'holiday',
            'description' => 'Summer break between semesters'
        ],
        // Resumption of Classes
        [
            'title' => 'Resumption of Classes',
            'date_start' => sprintf('%04d-08-04', $startYear),
            'date_end' => sprintf('%04d-08-04', $startYear),
            'type' => 'event',
            'description' => 'Classes resume after mid-year break'
        ],
        // National Heroes Day (last Monday of August)
        [
            'title' => 'National Heroes Day',
            'date_start' => sprintf('%04d-08-25', $startYear),
            'date_end' => sprintf('%04d-08-25', $startYear),
            'type' => 'holiday',
            'description' => 'National holiday honoring Filipino heroes (last Monday of August)'
        ],
        // Linggo ng Wika / Buwan ng Wika culmination
        [
            'title' => 'Linggo ng Wika / Buwan ng Wika Culmination',
            'date_start' => sprintf('%04d-08-25', $startYear),
            'date_end' => sprintf('%04d-08-31', $startYear),
            'type' => 'event',
            'description' => 'Celebration of Filipino language — Buwan ng Wika culmination week'
        ],
        // All Saints' Day Break
        [
            'title' => 'All Saints\' Day Break',
            'date_start' => sprintf('%04d-10-30', $startYear),
            'date_end' => sprintf('%04d-11-01', $startYear),
            'type' => 'holiday',
            'description' => 'Extended break for All Saints\' Day observance'
        ],
        // Bonifacio Day
        [
            'title' => 'Bonifacio Day',
            'date_start' => sprintf('%04d-11-30', $startYear),
            'date_end' => sprintf('%04d-11-30', $startYear),
            'type' => 'holiday',
            'description' => 'National holiday commemorating Andres Bonifacio'
        ],
        // Christmas/New Year Break
        [
            'title' => 'Christmas/New Year Break',
            'date_start' => sprintf('%04d-12-22', $startYear),
            'date_end' => sprintf('%04d-01-02', $endYear),
            'type' => 'holiday',
            'description' => 'Extended holiday break for Christmas and New Year celebrations'
        ],
        // Rizal Day
        [
            'title' => 'Rizal Day',
            'date_start' => sprintf('%04d-12-30', $startYear),
            'date_end' => sprintf('%04d-12-30', $startYear),
            'type' => 'holiday',
            'description' => 'National holiday commemorating Dr. Jose Rizal'
        ],
        // New Year's Day
        [
            'title' => 'New Year\'s Day',
            'date_start' => sprintf('%04d-01-01', $endYear),
            'date_end' => sprintf('%04d-01-01', $endYear),
            'type' => 'holiday',
            'description' => 'New Year\'s Day national holiday'
        ],
        // First Semester Examinations
        [
            'title' => 'First Semester Final Examinations',
            'date_start' => sprintf('%04d-01-19', $endYear),
            'date_end' => sprintf('%04d-01-21', $endYear),
            'type' => 'exam',
            'description' => 'Final examinations for the first semester'
        ],
        // Valentine's / Friendship Day
        [
            'title' => 'Valentine\'s / Friendship Day',
            'date_start' => sprintf('%04d-02-14', $endYear),
            'date_end' => sprintf('%04d-02-14', $endYear),
            'type' => 'event',
            'description' => 'Valentine\'s Day / National Friendship Day celebration'
        ],
        // EDSA Revolution Anniversary
        [
            'title' => 'EDSA Revolution Anniversary',
            'date_start' => sprintf('%04d-02-25', $endYear),
            'date_end' => sprintf('%04d-02-25', $endYear),
            'type' => 'holiday',
            'description' => 'Commemoration of the 1986 EDSA People Power Revolution'
        ],
        // National Reading Month
        [
            'title' => 'National Reading Month',
            'date_start' => sprintf('%04d-03-01', $endYear),
            'date_end' => sprintf('%04d-03-31', $endYear),
            'type' => 'event',
            'description' => 'Month-long celebration promoting reading and literacy'
        ],
        // Holy Week Break
        [
            'title' => 'Holy Week Break',
            'date_start' => sprintf('%04d-03-28', $endYear),
            'date_end' => sprintf('%04d-03-30', $endYear),
            'type' => 'holiday',
            'description' => 'Holy Week observance - Maundy Thursday, Good Friday, Black Saturday'
        ],
        // Second Semester Examinations
        [
            'title' => 'Second Semester Final Examinations',
            'date_start' => sprintf('%04d-03-30', $endYear),
            'date_end' => sprintf('%04d-04-01', $endYear),
            'type' => 'exam',
            'description' => 'Final examinations for the second semester'
        ],
        // Foundation Day placeholder
        [
            'title' => 'School Foundation Day (TBD)',
            'date_start' => sprintf('%04d-04-01', $endYear),
            'date_end' => sprintf('%04d-04-01', $endYear),
            'type' => 'event',
            'description' => 'School Foundation Day — date to be set by the school'
        ],
        // Araw ng Kagitingan
        [
            'title' => 'Araw ng Kagitingan (Day of Valor)',
            'date_start' => sprintf('%04d-04-09', $endYear),
            'date_end' => sprintf('%04d-04-09', $endYear),
            'type' => 'holiday',
            'description' => 'National holiday commemorating the Battle of Bataan and Corregidor'
        ],
        // Moving Up / Graduation
        [
            'title' => 'Moving Up / Graduation Ceremonies (TBD)',
            'date_start' => sprintf('%04d-04-10', $endYear),
            'date_end' => sprintf('%04d-04-10', $endYear),
            'type' => 'event',
            'description' => 'Graduation and moving up ceremonies — date to be set by the school'
        ],
        // End of School Year / Summer break start
        [
            'title' => 'End of School Year / Summer Break Starts',
            'date_start' => sprintf('%04d-04-11', $endYear),
            'date_end' => sprintf('%04d-04-11', $endYear),
            'type' => 'event',
            'description' => 'Official end of the academic year and start of summer break'
        ],
    ];
}
