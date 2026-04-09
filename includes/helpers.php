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
 * Get current school year string, e.g. "2025-2026".
 */
function currentSchoolYear(): string
{
    $year = (int)date('Y');
    $month = (int)date('m');
    if ($month >= 6) {
        return $year . '-' . ($year + 1);
    }
    return ($year - 1) . '-' . $year;
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
        // National Teachers' Institute Founding
        [
            'title' => 'Founding of the National Teachers\' Institute',
            'date_start' => sprintf('%04d-08-21', $startYear),
            'date_end' => sprintf('%04d-08-21', $startYear),
            'type' => 'holiday',
            'description' => 'Special non-working day'
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
            'date_start' => sprintf('%04d-12-08', $startYear),
            'date_end' => sprintf('%04d-01-02', $endYear),
            'type' => 'holiday',
            'description' => 'Extended holiday break for Christmas and New Year celebrations'
        ],
        // First Semester Examinations
        [
            'title' => 'First Semester Final Examinations',
            'date_start' => sprintf('%04d-01-19', $endYear),
            'date_end' => sprintf('%04d-01-21', $endYear),
            'type' => 'exam',
            'description' => 'Final examinations for the first semester'
        ],
        // EDSA Revolution Anniversary
        [
            'title' => 'EDSA Revolution Anniversary',
            'date_start' => sprintf('%04d-02-10', $endYear),
            'date_end' => sprintf('%04d-02-10', $endYear),
            'type' => 'holiday',
            'description' => 'Commemoration of the 1986 EDSA People Power Revolution'
        ],
        // EDSA Revolution Day (observed)
        [
            'title' => 'EDSA Revolution Day (observed)',
            'date_start' => sprintf('%04d-02-25', $endYear),
            'date_end' => sprintf('%04d-02-25', $endYear),
            'type' => 'holiday',
            'description' => 'Observed holiday for EDSA Revolution'
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
        // Araw ng Kagitingan
        [
            'title' => 'Araw ng Kagitingan (Day of Valor)',
            'date_start' => sprintf('%04d-04-09', $endYear),
            'date_end' => sprintf('%04d-04-09', $endYear),
            'type' => 'holiday',
            'description' => 'National holiday commemorating the Battle of Bataan and Corregidor'
        ],
        // End of School Year
        [
            'title' => 'End of School Year ' . $schoolYear,
            'date_start' => sprintf('%04d-04-09', $endYear),
            'date_end' => sprintf('%04d-04-09', $endYear),
            'type' => 'event',
            'description' => 'Official end of the academic year'
        ]
    ];
}
