<?php
/**
 * Guardian Schedule Page
 * Tab 1: Weekly class timetable  |  Tab 2: School calendar
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$userId = (int)($_SESSION['user_id'] ?? 0);

// Get guardian and linked students
$stmt = $pdo->prepare('SELECT id FROM guardians WHERE user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

$students = [];
if ($guardian) {
    $stmt = $pdo->prepare('
        SELECT s.id, s.full_name, s.section_id, sec.name AS section_name
        FROM students s
        LEFT JOIN sections sec ON sec.id = s.section_id
        WHERE s.guardian_id = :gid
        ORDER BY s.full_name
    ');
    $stmt->execute([':gid' => $guardian['id']]);
    $students = $stmt->fetchAll();
}

$studentMap = [];
foreach ($students as $stu) {
    $studentMap[(int)$stu['id']] = $stu;
}

$selectedStudent = (int)($_GET['student_id'] ?? 0);
if ($selectedStudent <= 0 || !isset($studentMap[$selectedStudent])) {
    $selectedStudent = isset($students[0]['id']) ? (int)$students[0]['id'] : 0;
}

$selectedStudentRow = $selectedStudent > 0 && isset($studentMap[$selectedStudent]) ? $studentMap[$selectedStudent] : null;
$sectionId = $selectedStudentRow ? (int)($selectedStudentRow['section_id'] ?? 0) : 0;

// Build available School Year options from selected student's section schedules
$years = [];
if ($sectionId > 0) {
    $stmt = $pdo->prepare('
        SELECT DISTINCT school_year
        FROM schedules
        WHERE section_id = :secid
        ORDER BY school_year DESC
    ');
    $stmt->execute([':secid' => $sectionId]);
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
if (empty($years)) {
    $years = [currentSchoolYear()];
}

$requestedYear = trim((string)($_GET['school_year'] ?? ''));
if ($requestedYear !== '' && in_array($requestedYear, $years, true)) {
    $selectedYear = $requestedYear;
} else {
    $selectedYear = in_array(currentSchoolYear(), $years, true) ? currentSchoolYear() : (string)$years[0];
}

// Build available Term options based on selected section + school year
$terms = [];
if ($sectionId > 0) {
    $stmt = $pdo->prepare('
        SELECT DISTINCT term
        FROM schedules
        WHERE section_id = :secid AND school_year = :sy
        ORDER BY term
    ');
    $stmt->execute([
        ':secid' => $sectionId,
        ':sy' => $selectedYear,
    ]);
    $terms = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
if (empty($terms)) {
    $terms = ['1st Semester', '2nd Semester'];
}

$requestedTerm = trim((string)($_GET['term'] ?? ''));
if ($requestedTerm !== '' && in_array($requestedTerm, $terms, true)) {
    $selectedTerm = $requestedTerm;
} else {
    $selectedTerm = in_array('1st Semester', $terms, true) ? '1st Semester' : (string)$terms[0];
}

// Fetch weekly schedule
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$slots = [];

if ($sectionId > 0) {
    $stmt = $pdo->prepare("
        SELECT sch.*, sub.name AS subject_name,
               CASE WHEN t.first_name = '' THEN t.last_name ELSE t.last_name || ', ' || t.first_name END AS teacher_name
        FROM schedules sch
        JOIN subjects sub ON sch.subject_id = sub.id
        LEFT JOIN teachers t ON sch.teacher_id = t.id
        WHERE sch.section_id = :secid
          AND sch.school_year = :sy
          AND sch.term = :term
        ORDER BY CASE sch.day_of_week
            WHEN 'Monday' THEN 1
            WHEN 'Tuesday' THEN 2
            WHEN 'Wednesday' THEN 3
            WHEN 'Thursday' THEN 4
            WHEN 'Friday' THEN 5
        END, sch.time_start
    ");
    $stmt->execute([
        ':secid' => $sectionId,
        ':sy' => $selectedYear,
        ':term' => $selectedTerm,
    ]);
    $scheduleRows = $stmt->fetchAll();

    foreach ($scheduleRows as $row) {
        $timeKey = date('H:i', strtotime($row['time_start'])) . '-' . date('H:i', strtotime($row['time_end']));
        $slots[$timeKey][$row['day_of_week']] = $row;
    }
    ksort($slots);
}

// Fetch calendar events
$calMonth = (int)($_GET['month'] ?? date('n'));
if ($calMonth < 1 || $calMonth > 12) {
    $calMonth = (int)date('n');
}
$calYear = (int)($_GET['year'] ?? date('Y'));
if ($calYear < 1970 || $calYear > 2100) {
    $calYear = (int)date('Y');
}

$firstDay = mktime(0, 0, 0, $calMonth, 1, $calYear);
$daysInMonth = (int)date('t', $firstDay);
$startDow = (int)date('N', $firstDay); // 1=Mon

$stmt = $pdo->prepare('
    SELECT * FROM calendar_events
    WHERE (date_start BETWEEN :start AND :end)
       OR (date_end BETWEEN :start2 AND :end2)
    ORDER BY date_start
');
$monthStart = date('Y-m-01', $firstDay);
$monthEnd = date('Y-m-t', $firstDay);
$stmt->execute([
    ':start' => $monthStart,
    ':end' => $monthEnd,
    ':start2' => $monthStart,
    ':end2' => $monthEnd,
]);
$events = $stmt->fetchAll();

$eventsByDay = [];
foreach ($events as $ev) {
    $evStart = max(1, (int)date('j', strtotime($ev['date_start'])));
    $evEnd = min($daysInMonth, (int)date('j', strtotime($ev['date_end'])));

    if (date('Y-m', strtotime($ev['date_start'])) !== date('Y-m', $firstDay)) {
        $evStart = 1;
    }
    if (date('Y-m', strtotime($ev['date_end'])) !== date('Y-m', $firstDay)) {
        $evEnd = $daysInMonth;
    }

    for ($d = $evStart; $d <= $evEnd; $d++) {
        $eventsByDay[$d][] = $ev;
    }
}

$prevMonth = $calMonth - 1;
$prevYear = $calYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $calMonth + 1;
$nextYear = $calYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$calendarBaseQuery = [
    'student_id' => $selectedStudent,
    'school_year' => $selectedYear,
    'term' => $selectedTerm,
];
$prevLink = '?' . http_build_query($calendarBaseQuery + ['month' => $prevMonth, 'year' => $prevYear]);
$nextLink = '?' . http_build_query($calendarBaseQuery + ['month' => $nextMonth, 'year' => $nextYear]);

$pageTitle = 'Schedule & Calendar';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="page-header-guardian">
            <h4><i class="bi bi-calendar-week me-2"></i>Schedule & Calendar</h4>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="schedule-filter">
            <input type="hidden" name="month" value="<?= (int)$calMonth ?>">
            <input type="hidden" name="year" value="<?= (int)$calYear ?>">

            <div class="col-md-4">
                <label for="student_id" class="form-label">Student</label>
                <select class="form-select" name="student_id" id="student_id" <?= empty($students) ? 'disabled' : '' ?>>
                    <?php if (empty($students)): ?>
                        <option value="0">No linked students</option>
                    <?php else: ?>
                        <?php foreach ($students as $stu): ?>
                            <option value="<?= (int)$stu['id'] ?>" <?= $selectedStudent === (int)$stu['id'] ? 'selected' : '' ?>>
                                <?= e($stu['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label for="school_year" class="form-label">School Year</label>
                <select class="form-select" name="school_year" id="school_year">
                    <?php foreach ($years as $year): ?>
                        <option value="<?= e((string)$year) ?>" <?= $selectedYear === (string)$year ? 'selected' : '' ?>>
                            <?= e((string)$year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label for="term" class="form-label">Term</label>
                <select class="form-select" name="term" id="term">
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= e((string)$term) ?>" <?= $selectedTerm === (string)$term ? 'selected' : '' ?>>
                            <?= e((string)$term) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-filter me-1"></i>Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="scheduleTab">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#weeklySchedule" id="tab-weekly">
            <i class="bi bi-table me-1"></i>Weekly Schedule
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#schoolCalendar" id="tab-calendar">
            <i class="bi bi-calendar-event me-1"></i>School Calendar
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- Tab 1: Weekly Timetable -->
    <div class="tab-pane fade show active" id="weeklySchedule">
        <?php if (empty($students)): ?>
            <div class="alert alert-info mb-0">No students are linked to your account yet.</div>
        <?php elseif ($sectionId <= 0): ?>
            <div class="alert alert-info mb-0">The selected student has no assigned section yet.</div>
        <?php elseif (empty($slots)): ?>
            <div class="alert alert-info mb-0">
                No class schedule found for the selected filters (<?= e($selectedYear) ?>, <?= e($selectedTerm) ?>).
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="timetable table" id="timetable-grid">
                    <thead>
                        <tr>
                            <th style="width:12%;">Time</th>
                            <?php foreach ($days as $day): ?>
                                <th><?= e($day) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slots as $timeKey => $dayCells): ?>
                            <tr>
                                <td class="fw-bold small"><?= e($timeKey) ?></td>
                                <?php foreach ($days as $day): ?>
                                    <td>
                                        <?php if (isset($dayCells[$day])): ?>
                                            <div class="slot-filled p-1">
                                                <strong><?= e($dayCells[$day]['subject_name']) ?></strong><br>
                                                <small class="text-muted"><?= e($dayCells[$day]['teacher_name'] ?? 'TBA') ?></small><br>
                                                <small>Room <?= e($dayCells[$day]['room'] ?? 'TBD') ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab 2: School Calendar -->
    <div class="tab-pane fade" id="schoolCalendar">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="<?= e($prevLink) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
            <h5 class="fw-bold mb-0"><?= e(date('F Y', $firstDay)) ?></h5>
            <a href="<?= e($nextLink) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
        </div>

        <div class="calendar-grid">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dh): ?>
                <div class="day-header"><?= e($dh) ?></div>
            <?php endforeach; ?>

            <?php for ($i = 1; $i < $startDow; $i++): ?>
                <div class="day-cell inactive"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <div class="day-cell">
                    <div class="day-number"><?= e((string)$d) ?></div>
                    <?php if (isset($eventsByDay[$d])): ?>
                        <?php foreach ($eventsByDay[$d] as $ev):
                            $badgeClass = match ($ev['type']) {
                                'holiday' => 'bg-danger text-white',
                                'exam'    => 'bg-warning text-dark',
                                'event'   => 'bg-info text-white',
                                default   => 'bg-secondary text-white',
                            };
                        ?>
                            <div class="event-badge <?= e($badgeClass) ?>" title="<?= e($ev['title']) ?>"><?= e($ev['title']) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>

            <?php
            $endDow = (int)date('N', mktime(0, 0, 0, $calMonth, $daysInMonth, $calYear));
            for ($i = $endDow + 1; $i <= 7; $i++):
            ?>
                <div class="day-cell inactive"></div>
            <?php endfor; ?>
        </div>

        <div class="mt-3">
            <span class="badge bg-danger me-1">Holiday</span>
            <span class="badge bg-warning text-dark me-1">Exam</span>
            <span class="badge bg-info me-1">Event</span>
            <span class="badge bg-secondary me-1">Other</span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
