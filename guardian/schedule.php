<?php
/**
 * Guardian Schedule Page
 * Tab 1: Weekly class timetable  |  Tab 2: School calendar
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

// Get guardian → student → section
$stmt = $pdo->prepare("SELECT id FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

$students = [];
$sectionId = 0;
if ($guardian) {
    $stmt = $pdo->prepare("SELECT id, full_name, section_id FROM students WHERE guardian_id = :gid");
    $stmt->execute([':gid' => $guardian['id']]);
    $students = $stmt->fetchAll();
    $sectionId = $students[0]['section_id'] ?? 0;
}

$selectedStudent = (int)($_GET['student_id'] ?? ($students[0]['id'] ?? 0));
foreach ($students as $s) {
    if ($s['id'] == $selectedStudent) {
        $sectionId = $s['section_id'] ?? 0;
        break;
    }
}

// Fetch weekly schedule
$days  = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
$slots = [];

if ($sectionId) {
    $stmt = $pdo->prepare("
        SELECT sch.*, sub.name AS subject_name, t.full_name AS teacher_name
        FROM schedules sch
        JOIN subjects sub ON sch.subject_id = sub.id
        JOIN teachers t ON sch.teacher_id = t.id
        WHERE sch.section_id = :secid
        ORDER BY FIELD(sch.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday'), sch.time_start
    ");
    $stmt->execute([':secid' => $sectionId]);
    $scheduleRows = $stmt->fetchAll();

    foreach ($scheduleRows as $row) {
        $timeKey = date('H:i', strtotime($row['time_start'])) . '-' . date('H:i', strtotime($row['time_end']));
        $slots[$timeKey][$row['day_of_week']] = $row;
    }
    ksort($slots);
}

// Fetch calendar events
$calMonth = (int)($_GET['month'] ?? date('n'));
$calYear  = (int)($_GET['year'] ?? date('Y'));
$firstDay = mktime(0,0,0,$calMonth,1,$calYear);
$daysInMonth = (int)date('t', $firstDay);
$startDow    = (int)date('N', $firstDay); // 1=Mon

$stmt = $pdo->prepare("
    SELECT * FROM calendar_events 
    WHERE (date_start BETWEEN :start AND :end) OR (date_end BETWEEN :start2 AND :end2)
    ORDER BY date_start
");
$monthStart = date('Y-m-01', $firstDay);
$monthEnd   = date('Y-m-t', $firstDay);
$stmt->execute([':start' => $monthStart, ':end' => $monthEnd, ':start2' => $monthStart, ':end2' => $monthEnd]);
$events = $stmt->fetchAll();

$eventsByDay = [];
foreach ($events as $ev) {
    $evStart = max(1, (int)date('j', strtotime($ev['date_start'])));
    $evEnd   = min($daysInMonth, (int)date('j', strtotime($ev['date_end'])));
    if (date('Y-m', strtotime($ev['date_start'])) !== date('Y-m', $firstDay)) $evStart = 1;
    if (date('Y-m', strtotime($ev['date_end'])) !== date('Y-m', $firstDay)) $evEnd = $daysInMonth;
    for ($d = $evStart; $d <= $evEnd; $d++) {
        $eventsByDay[$d][] = $ev;
    }
}

$prevMonth = $calMonth - 1; $prevYear = $calYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $calMonth + 1; $nextYear = $calYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

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
        <?php if (empty($slots)): ?>
            <div class="alert alert-info">No class schedule found for this student's section.</div>
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
                                        <small class="text-muted"><?= e($dayCells[$day]['teacher_name']) ?></small><br>
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
            <a href="?month=<?= (int)$prevMonth ?>&year=<?= (int)$prevYear ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
            <h5 class="fw-bold mb-0"><?= e(date('F Y', $firstDay)) ?></h5>
            <a href="?month=<?= (int)$nextMonth ?>&year=<?= (int)$nextYear ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
        </div>

        <div class="calendar-grid">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dh): ?>
                <div class="day-header"><?= e($dh) ?></div>
            <?php endforeach; ?>

            <?php
            // Empty cells before first day
            for ($i = 1; $i < $startDow; $i++):
            ?>
                <div class="day-cell inactive"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <div class="day-cell">
                    <div class="day-number"><?= e((string)$d) ?></div>
                    <?php if (isset($eventsByDay[$d])): ?>
                        <?php foreach ($eventsByDay[$d] as $ev):
                            $badgeClass = match($ev['type']) {
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
            // Empty cells after last day
            $endDow = (int)date('N', mktime(0,0,0,$calMonth,$daysInMonth,$calYear));
            for ($i = $endDow + 1; $i <= 7; $i++):
            ?>
                <div class="day-cell inactive"></div>
            <?php endfor; ?>
        </div>

        <!-- Event Legend -->
        <div class="mt-3">
            <span class="badge bg-danger me-1">Holiday</span>
            <span class="badge bg-warning text-dark me-1">Exam</span>
            <span class="badge bg-info me-1">Event</span>
            <span class="badge bg-secondary me-1">Other</span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
