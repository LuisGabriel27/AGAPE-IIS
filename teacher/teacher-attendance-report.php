<?php
/**
 * Teacher Monthly Attendance Report
 * Generates a printable monthly attendance summary per section.
 * Columns: student names (sorted by last name) + one column per calendar day.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('teacher');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!attendanceModuleEnabled()) {
    setFlash('warning', 'Attendance module is currently disabled by admin.');
    redirect(APP_URL . '/teacher/teacher-dashboard.php');
}

$pdo    = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    setFlash('danger', 'Teacher profile not found.');
    redirect(APP_URL . '/teacher/teacher-dashboard.php');
}

// Get assigned sections
$stmt = $pdo->prepare("
    SELECT DISTINCT sec.id, sec.name, sec.grade_level
    FROM schedules sch
    JOIN sections sec ON sch.section_id = sec.id
    WHERE sch.teacher_id = :tid
    ORDER BY sec.grade_level, sec.name
");
$stmt->execute([':tid' => $teacher['id']]);
$sections = $stmt->fetchAll();

// Filters
$selSection = (int)($_GET['section_id'] ?? ($sections[0]['id'] ?? 0));
$selYear    = (int)($_GET['year'] ?? date('Y'));
$selMonth   = (int)($_GET['month'] ?? date('m'));

if ($selMonth < 1 || $selMonth > 12) $selMonth = (int)date('m');

// Verify access
$currentSection = null;
foreach ($sections as $sec) {
    if ($sec['id'] == $selSection) { $currentSection = $sec; break; }
}

$students       = [];
$attendanceGrid = [];
$daysInMonth    = 0;
$monthStart     = '';
$monthEnd       = '';

if ($currentSection) {
    $monthStart  = sprintf('%04d-%02d-01', $selYear, $selMonth);
    $daysInMonth = (int)date('t', strtotime($monthStart));
    $monthEnd    = sprintf('%04d-%02d-%02d', $selYear, $selMonth, $daysInMonth);

    // Students in section, sorted by last name
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, lrn
        FROM students
        WHERE section_id = :secid
        ORDER BY last_name, first_name
    ");
    $stmt->execute([':secid' => $selSection]);
    $students = $stmt->fetchAll();

    // Attendance logs for the month
    if (!empty($students)) {
        $sids = array_column($students, 'id');
        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id, attendance_date, attendance_status
            FROM attendance_logs
            WHERE student_id IN ({$placeholders})
              AND attendance_date BETWEEN ? AND ?
            ORDER BY attendance_date
        ");
        $params = array_merge($sids, [$monthStart, $monthEnd]);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $day = (int)date('j', strtotime($row['attendance_date']));
            $attendanceGrid[$row['student_id']][$day] = $row['attendance_status'];
        }
    }
}

// Compute per-student totals
function countStatus(array $days, string $status): int {
    return count(array_filter($days, fn($s) => $s === $status));
}

$isPrint    = isset($_GET['print']);
$monthLabel = date('F Y', mktime(0, 0, 0, $selMonth, 1, $selYear));

$pageTitle = 'Monthly Attendance Report';
if (!$isPrint) {
    require_once __DIR__ . '/../includes/header.php';
}
?>

<?php if ($isPrint): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Report — <?= e($monthLabel) ?> — <?= e($currentSection['name'] ?? '') ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; margin: 15mm; }
        h2, h3 { margin: 0 0 4px; }
        p { margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #555; padding: 3px 4px; text-align: center; }
        th { background: #e8e8e8; font-weight: bold; }
        td.name { text-align: left; min-width: 130px; }
        .present { color: #166534; font-weight: bold; }
        .absent  { color: #991b1b; font-weight: bold; }
        .late    { color: #92400e; font-weight: bold; }
        .total-col { background: #f5f5f5; font-weight: bold; }
        .weekend { background: #f0f0f0; color: #aaa; }
        .footer-note { margin-top: 20px; font-size: 9px; color: #555; }
        @media print {
            .no-print { display: none; }
            body { margin: 10mm; }
        }
    </style>
</head>
<body>
<div class="no-print" style="margin-bottom:12px;">
    <button onclick="window.print()" style="padding:6px 14px;cursor:pointer;">Print / Save PDF</button>
    <a href="?section_id=<?= (int)$selSection ?>&year=<?= (int)$selYear ?>&month=<?= (int)$selMonth ?>" style="margin-left:8px;">Back</a>
</div>
<?php else: ?>
<div class="row mb-4">
    <div class="col-md-8">
        <h4 class="fw-bold"><i class="bi bi-calendar-check me-2"></i>Monthly Attendance Report</h4>
    </div>
    <div class="col-md-4 text-md-end">
        <?php if ($currentSection && !empty($students)): ?>
        <a href="?section_id=<?= (int)$selSection ?>&year=<?= (int)$selYear ?>&month=<?= (int)$selMonth ?>&print=1"
           target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-printer me-1"></i>Print / Download
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold small">Section</label>
                <select class="form-select form-select-sm" name="section_id">
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= (int)$sec['id'] ?>" <?= $selSection == $sec['id'] ? 'selected' : '' ?>>
                            <?= e($sec['name']) ?> (<?= e(formatGradeLevel((string)$sec['grade_level'])) ?>)
                        </option>
                    <?php endforeach; ?>
                    <?php if (empty($sections)): ?>
                        <option value="0">No sections assigned</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Month</label>
                <select class="form-select form-select-sm" name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $selMonth == $m ? 'selected' : '' ?>>
                            <?= date('F', mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small">Year</label>
                <select class="form-select form-select-sm" name="year">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $selYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1"></i>Generate Report
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($currentSection && !empty($students)): ?>

<?php if ($isPrint): ?>
<h2><?= e(APP_NAME) ?></h2>
<h3>Monthly Attendance Report</h3>
<p>
    <strong>Section:</strong> <?= e($currentSection['name']) ?> — <?= e(formatGradeLevel((string)$currentSection['grade_level'])) ?> &nbsp;|&nbsp;
    <strong>Month:</strong> <?= e($monthLabel) ?> &nbsp;|&nbsp;
    <strong>Teacher:</strong> <?= e(format_name($teacher['first_name'], $teacher['last_name'])) ?>
</p>
<?php else: ?>
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><strong><?= e($currentSection['name']) ?></strong> — <?= e(formatGradeLevel((string)$currentSection['grade_level'])) ?> | <?= e($monthLabel) ?></span>
        <span class="badge bg-secondary"><?= e((string)count($students)) ?> students</span>
    </div>
    <div class="card-body p-0">
<?php endif; ?>

<div style="overflow-x:auto;">
<table <?= !$isPrint ? 'class="table table-bordered table-sm mb-0" style="font-size:0.78rem;min-width:900px;"' : '' ?>>
    <thead>
        <tr>
            <th <?= !$isPrint ? 'style="min-width:160px;"' : 'class="name"' ?>>Student Name</th>
            <th>LRN</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $dow = date('N', mktime(0,0,0,$selMonth,$d,$selYear)); // 1=Mon,7=Sun
                $isWeekend = ($dow >= 6);
            ?>
            <th <?= $isWeekend ? ($isPrint ? 'class="weekend"' : 'style="background:#f8f9fa;color:#adb5bd;"') : '' ?> title="<?= date('D', mktime(0,0,0,$selMonth,$d,$selYear)) ?>">
                <?= $d ?>
            </th>
            <?php endfor; ?>
            <th <?= $isPrint ? 'class="total-col"' : 'class="table-light fw-bold"' ?>>P</th>
            <th <?= $isPrint ? 'class="total-col"' : 'class="table-light fw-bold"' ?>>L</th>
            <th <?= $isPrint ? 'class="total-col"' : 'class="table-light fw-bold"' ?>>A</th>
        </tr>
        <tr>
            <th colspan="2" <?= !$isPrint ? 'class="text-muted small"' : '' ?>>Legend: P=Present L=Late A=Absent</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $dayLabel = date('D', mktime(0,0,0,$selMonth,$d,$selYear));
            ?>
            <th style="font-size:0.65rem;font-weight:normal;" title="<?= $dayLabel ?>"><?= substr($dayLabel,0,2) ?></th>
            <?php endfor; ?>
            <th colspan="3"></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($students as $idx => $stu):
            $stuDays = $attendanceGrid[$stu['id']] ?? [];
            $cntP = countStatus($stuDays, 'present');
            $cntL = countStatus($stuDays, 'late');
            $cntA = countStatus($stuDays, 'absent');
        ?>
        <tr>
            <td <?= $isPrint ? 'class="name"' : 'class="fw-semibold"' ?>><?= e(format_name($stu['first_name'], $stu['last_name'])) ?></td>
            <td><small><?= e($stu['lrn'] ?? '') ?></small></td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $dow  = date('N', mktime(0,0,0,$selMonth,$d,$selYear));
                $isWkd = ($dow >= 6);
                $status = $stuDays[$d] ?? null;
                $symbol = match($status) {
                    'present' => 'P',
                    'late'    => 'L',
                    'absent'  => 'A',
                    default   => '',
                };
                $cls = match($status) {
                    'present' => 'present',
                    'late'    => 'late',
                    'absent'  => 'absent',
                    default   => '',
                };
            ?>
            <td class="<?= $isPrint ? ($isWkd ? 'weekend ' : '') . $cls : '' ?>"
                <?= !$isPrint && $isWkd ? 'style="background:#f8f9fa;"' : '' ?>>
                <?php if ($status && !$isPrint): ?>
                    <span class="badge <?= $status === 'present' ? 'bg-success' : ($status === 'late' ? 'bg-warning text-dark' : 'bg-danger') ?> badge-sm p-1" title="<?= e(ucfirst($cls)) ?>" aria-label="<?= e(ucfirst($cls)) ?>"><?= $symbol ?></span>
                <?php else: ?>
                    <?= $symbol ?>
                <?php endif; ?>
            </td>
            <?php endfor; ?>
            <td <?= $isPrint ? 'class="total-col present"' : 'class="table-light fw-bold text-success"' ?>><?= $cntP ?></td>
            <td <?= $isPrint ? 'class="total-col late"' : 'class="table-light fw-bold text-warning"' ?>><?= $cntL ?></td>
            <td <?= $isPrint ? 'class="total-col absent"' : 'class="table-light fw-bold text-danger"' ?>><?= $cntA ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($isPrint): ?>
<p class="footer-note">
    Generated: <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp; <?= e(APP_NAME) ?> &nbsp;|&nbsp;
    Teacher: <?= e(format_name($teacher['first_name'], $teacher['last_name'])) ?>
</p>
</body>
</html>
<?php else: ?>
    </div><!-- card-body -->
</div><!-- card -->
<?php endif; ?>

<?php elseif ($currentSection): ?>
    <?= emptyStateHtml('No students are enrolled in this section yet.', 'Students appear here once the registrar enrolls and assigns them to this section. Please coordinate with the registrar if you expect students.', 'bi-people') ?>
<?php elseif (empty($sections)): ?>
    <div class="alert alert-warning">No sections have been assigned to you yet. Contact an administrator.</div>
<?php else: ?>
    <div class="alert alert-info">Select a section and month above, then click Generate Report.</div>
<?php endif; ?>

<?php if (!$isPrint): ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
