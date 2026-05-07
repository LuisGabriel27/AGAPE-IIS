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
    return '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show" role="alert">'
         . e($f['message'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
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
        'awaiting_payment'         => 'Awaiting Cashier Payment',
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
 * (already submitted to teachers, archived, or in cashier's hands).
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
        'sent_to_cashier' => 'Sent to Cashier',
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
