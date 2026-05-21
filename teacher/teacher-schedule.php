<?php
/**
 * Teacher Schedule Page
 * View own weekly schedule timetable.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('teacher');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];
$activeYear = currentSchoolYear();
$activeTerm = currentAcademicTerm();

$stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$teacher = $stmt->fetch();

$days  = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
$slots = [];

if ($teacher) {
    $stmt = $pdo->prepare("
        SELECT sch.*, sub.name AS subject_name, sub.code AS subject_code,
               sec.name AS section_name, sec.grade_level
        FROM schedules sch
        JOIN subjects sub ON sch.subject_id = sub.id
        JOIN sections sec ON sch.section_id = sec.id
        WHERE sch.teacher_id = :tid
          AND sch.school_year = :sy
          AND sch.term = :term
        ORDER BY CASE sch.day_of_week WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3 WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 END, sch.time_start
    ");
    $stmt->execute([':tid' => $teacher['id'], ':sy' => $activeYear, ':term' => $activeTerm]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $timeKey = date('H:i', strtotime($row['time_start'])) . '-' . date('H:i', strtotime($row['time_end']));
        $slots[$timeKey][$row['day_of_week']] = $row;
    }
    ksort($slots);
}

$pageTitle = 'My Schedule';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-calendar-week me-2"></i>My Weekly Schedule</h4>
        <p class="text-muted mb-1"><?= e(format_name($teacher['first_name'] ?? '', $teacher['last_name'] ?? 'Teacher')) ?> - <?= e($teacher['department'] ?? '') ?></p>
        <div class="small text-muted">Active period: <?= activeAcademicPeriodBadge() ?></div>
    </div>
</div>

<?php if (empty($slots)): ?>
    <?= emptyStateHtml('You have no class schedule for ' . formatAcademicPeriod($activeYear, $activeTerm) . '.', 'Class schedules are created by the school administrator. Please contact the admin or registrar if you expect to be assigned classes this term.', 'bi-calendar-week') ?>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="timetable table mb-0" id="teacher-timetable">
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
                                <?php if (isset($dayCells[$day])): $c = $dayCells[$day]; ?>
                                    <div class="slot-filled p-1">
                                        <strong><?= e($c['subject_name']) ?></strong><br>
                                        <small><?= e($c['section_name']) ?> (<?= e(formatGradeLevel((string)$c['grade_level'])) ?>)</small><br>
                                        <small class="text-muted">Room <?= e($c['room'] ?? 'TBD') ?></small>
                                    </div>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
