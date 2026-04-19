<?php
/**
 * Teacher Calendar — Read-only view of school events
 * Uses the shared calendar widget with $isAdmin = false.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('teacher');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();

// Fetch all events for calendar
$calendarEvents = $pdo->query("SELECT * FROM calendar_events ORDER BY date_start")->fetchAll();
$isAdmin = false;
$calendarFullPage = false;

$pageTitle = 'School Calendar';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-calendar-event-fill me-2"></i>School Calendar</h4>
        <p class="text-muted">View upcoming DepEd events, holidays, and school activities.</p>
    </div>
</div>

<!-- Visual Calendar Widget (read-only) -->
<?php require __DIR__ . '/../includes/calendar-widget.php'; ?>

<!-- Upcoming Events List (expanded) -->
<div class="card mt-4">
    <div class="card-header">
        <span><i class="bi bi-list-ul"></i>All Upcoming Events</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="teacher-calendar-table">
                <thead>
                    <tr><th>Event</th><th>Date Range</th><th>Type</th><th>Source</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <?php
                    $today = date('Y-m-d');
                    $upcomingAll = array_filter($calendarEvents, fn($ev) => $ev['date_end'] >= $today);
                    usort($upcomingAll, fn($a, $b) => strcmp($a['date_start'], $b['date_start']));

                    if (empty($upcomingAll)):
                    ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No upcoming events.</td></tr>
                    <?php else: foreach ($upcomingAll as $ev):
                        $typeColorMap = [
                            'event'   => 'badge-status-enrolled',
                            'holiday' => 'badge-status-rejected',
                            'exam'    => 'badge-status-pending',
                            'other'   => 'badge-status-active',
                        ];
                        $badge = $typeColorMap[$ev['type']] ?? 'badge-status-inactive';
                        $isDeped = ($ev['source'] ?? 'manual') === 'deped';
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= e($ev['title']) ?></td>
                        <td>
                            <small>
                                <i class="bi bi-calendar3 me-1 text-muted"></i>
                                <?= e(date('M d, Y', strtotime($ev['date_start']))) ?>
                                <?php if ($ev['date_start'] !== $ev['date_end']): ?>
                                    — <?= e(date('M d, Y', strtotime($ev['date_end']))) ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td><span class="badge <?= e($badge) ?>"><?= e(ucfirst($ev['type'])) ?></span></td>
                        <td>
                            <?php if ($isDeped): ?>
                                <span class="badge bg-primary"><i class="bi bi-building me-1"></i>DepEd</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">School</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= e(mb_substr($ev['description'] ?? '', 0, 60)) ?><?= strlen($ev['description'] ?? '') > 60 ? '…' : '' ?></small></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
