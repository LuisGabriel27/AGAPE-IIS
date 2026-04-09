<?php
/**
 * Shared Calendar Widget
 * Renders a visual monthly calendar grid with event badges.
 * Used on all dashboard pages and the dedicated calendar page.
 *
 * Required variables before including:
 *   $calendarEvents (array) — all calendar_events rows
 *   $isAdmin (bool) — whether to show add/edit/delete controls
 *   $calendarFullPage (bool, optional) — true for full-page calendar with management UI
 *
 * Usage:
 *   $calendarEvents = $pdo->query("SELECT * FROM calendar_events ORDER BY date_start")->fetchAll();
 *   $isAdmin = ($_SESSION['role'] === 'admin');
 *   require __DIR__ . '/../includes/calendar-widget.php';
 */

// Month navigation
$calMonth = (int)($_GET['cal_month'] ?? date('n'));
$calYear  = (int)($_GET['cal_year']  ?? date('Y'));

// Clamp values
if ($calMonth < 1)  { $calMonth = 12; $calYear--; }
if ($calMonth > 12) { $calMonth = 1;  $calYear++; }

$firstDay     = mktime(0, 0, 0, $calMonth, 1, $calYear);
$daysInMonth  = (int)date('t', $firstDay);
$startDow     = (int)date('w', $firstDay); // 0=Sun
$monthName    = date('F', $firstDay);
$today        = date('Y-m-d');
$currentYm    = sprintf('%04d-%02d', $calYear, $calMonth);

// Build base URL for month navigation (preserve existing params)
$baseParams = $_GET;
unset($baseParams['cal_month'], $baseParams['cal_year']);
$baseQuery = http_build_query($baseParams);
$navBase = '?' . ($baseQuery ? $baseQuery . '&' : '');

$prevMonth = $calMonth - 1;
$prevYear  = $calYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $calMonth + 1;
$nextYear  = $calYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// Index events by date for quick lookup
$eventsByDate = [];
foreach ($calendarEvents as $ev) {
    $start = strtotime($ev['date_start']);
    $end   = strtotime($ev['date_end']);
    for ($d = $start; $d <= $end; $d = strtotime('+1 day', $d)) {
        $key = date('Y-m-d', $d);
        $eventsByDate[$key][] = $ev;
    }
}

// Event type colors
$typeColors = [
    'event'   => ['bg' => '#4366F6', 'text' => '#FFFFFF', 'label' => 'Academic Events'],
    'holiday' => ['bg' => '#EF4444', 'text' => '#FFFFFF', 'label' => 'Holidays'],
    'exam'    => ['bg' => '#F59E0B', 'text' => '#FFFFFF', 'label' => 'Exams'],
    'other'   => ['bg' => '#22C55E', 'text' => '#FFFFFF', 'label' => 'Other'],
];

// Upcoming events
$upcomingEvts = [];
foreach ($calendarEvents as $ev) {
    if ($ev['date_end'] >= $today) {
        $upcomingEvts[] = $ev;
    }
}
usort($upcomingEvts, fn($a, $b) => strcmp($a['date_start'], $b['date_start']));
$upcomingEvts = array_slice($upcomingEvts, 0, 5);
?>

<div class="card" id="calendar-widget">
    <div class="card-body p-0">
        <!-- Calendar Header -->
        <div class="d-flex justify-content-between align-items-center p-3 pb-2">
            <div>
                <h5 class="fw-bold mb-0">Academic Year Calendar</h5>
                <small class="text-muted"><?= currentSchoolYear() ?> Academic Schedule</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <!-- Legend -->
                <div class="d-none d-lg-flex align-items-center gap-3 me-3">
                    <?php foreach ($typeColors as $type => $color): ?>
                    <div class="d-flex align-items-center gap-1">
                        <span style="width:10px;height:10px;border-radius:3px;background:<?= $color['bg'] ?>;display:inline-block;"></span>
                        <small class="text-muted" style="font-size:0.72rem;"><?= $color['label'] ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($isAdmin && !empty($calendarFullPage)): ?>
                    <a href="?action=create" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Event</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Month Navigation -->
        <div class="d-flex justify-content-between align-items-center px-3 pb-3">
            <div class="d-flex align-items-center gap-2">
                <a href="<?= $navBase ?>cal_month=<?= $prevMonth ?>&cal_year=<?= $prevYear ?>" class="btn btn-sm btn-outline-secondary btn-icon" style="width:30px;height:30px;">
                    <i class="bi bi-chevron-left" style="font-size:0.75rem;"></i>
                </a>
                <h6 class="fw-bold mb-0"><?= $monthName ?> <?= $calYear ?></h6>
                <a href="<?= $navBase ?>cal_month=<?= $nextMonth ?>&cal_year=<?= $nextYear ?>" class="btn btn-sm btn-outline-secondary btn-icon" style="width:30px;height:30px;">
                    <i class="bi bi-chevron-right" style="font-size:0.75rem;"></i>
                </a>
            </div>
            <a href="<?= $navBase ?>cal_month=<?= date('n') ?>&cal_year=<?= date('Y') ?>" class="btn btn-sm btn-outline-primary" style="font-size:0.78rem;">Today</a>
        </div>

        <!-- Calendar Grid -->
        <div class="calendar-grid" style="border-top:1px solid var(--border-color);">
            <?php
            $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            foreach ($dayNames as $dn):
            ?>
                <div class="day-header"><?= $dn ?></div>
            <?php endforeach; ?>

            <?php
            // Empty cells before first day
            for ($i = 0; $i < $startDow; $i++):
                $prevMonthDays = (int)date('t', strtotime("-1 month", $firstDay));
                $showDay = $prevMonthDays - $startDow + $i + 1;
            ?>
                <div class="day-cell inactive">
                    <div class="day-number" style="opacity:0.4;"><?= $showDay ?></div>
                </div>
            <?php endfor; ?>

            <?php
            // Actual days
            for ($day = 1; $day <= $daysInMonth; $day++):
                $dateStr = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
                $isToday = ($dateStr === $today);
                $dayEvents = $eventsByDate[$dateStr] ?? [];
                // Deduplicate events by ID for this day
                $seen = [];
                $uniqueDayEvents = [];
                foreach ($dayEvents as $de) {
                    if (!in_array($de['id'], $seen)) {
                        $seen[] = $de['id'];
                        $uniqueDayEvents[] = $de;
                    }
                }
            ?>
                <div class="day-cell <?= $isToday ? 'today' : '' ?>">
                    <div class="day-number"><?= $isToday ? "<span>{$day}</span>" : $day ?></div>
                    <?php foreach (array_slice($uniqueDayEvents, 0, 2) as $de):
                        $tc = $typeColors[$de['type']] ?? $typeColors['other'];
                    ?>
                        <div class="event-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['text'] ?>;" title="<?= e($de['title']) ?>">
                            <?= e(mb_substr($de['title'], 0, 14)) ?><?= mb_strlen($de['title']) > 14 ? '…' : '' ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($uniqueDayEvents) > 2): ?>
                        <div class="event-badge" style="background:var(--secondary-light);color:var(--text-secondary);font-size:0.6rem;">+<?= count($uniqueDayEvents) - 2 ?> more</div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>

            <?php
            // Fill remaining cells
            $totalCells = $startDow + $daysInMonth;
            $remaining = (7 - ($totalCells % 7)) % 7;
            for ($i = 1; $i <= $remaining; $i++):
            ?>
                <div class="day-cell inactive">
                    <div class="day-number" style="opacity:0.4;"><?= $i ?></div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Upcoming Events Below Calendar -->
<div class="row g-4 mt-0">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-calendar-event-fill"></i>Upcoming Events</span>
            </div>
            <div class="card-body">
                <?php if (empty($upcomingEvts)): ?>
                    <div class="empty-state py-3">
                        <i class="bi bi-calendar d-block"></i>
                        <p>No upcoming events.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingEvts as $ue):
                        $tc = $typeColors[$ue['type']] ?? $typeColors['other'];
                    ?>
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div style="width:6px;height:6px;border-radius:50%;background:<?= $tc['bg'] ?>;margin-top:7px;flex-shrink:0;"></div>
                        <div>
                            <div class="fw-semibold" style="font-size:0.9rem;"><?= e($ue['title']) ?></div>
                            <small class="text-muted">
                                <?= e(date('F d, Y', strtotime($ue['date_start']))) ?>
                                <?php if ($ue['date_start'] !== $ue['date_end']): ?>
                                    – <?= e(date('F d, Y', strtotime($ue['date_end']))) ?>
                                <?php endif; ?>
                            </small>
                            <?php if (!empty($ue['description'])): ?>
                                <div class="text-muted" style="font-size:0.78rem;margin-top:2px;"><?= e(mb_substr($ue['description'], 0, 80)) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-info-circle-fill"></i>School Information</span>
            </div>
            <div class="card-body">
                <div class="p-3 rounded" style="background:var(--danger-light);border-left:4px solid var(--danger);">
                    <div class="fw-bold mb-2"><i class="bi bi-megaphone-fill me-1"></i> Academic Reminders</div>
                    <ul class="mb-0" style="font-size:0.85rem;padding-left:18px;">
                        <li class="mb-1">School Year: <strong><?= currentSchoolYear() ?></strong></li>
                        <li class="mb-1">Check the calendar regularly for schedule updates</li>
                        <li>Contact the admin office for event inquiries</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
