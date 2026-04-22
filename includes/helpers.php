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
