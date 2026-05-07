<?php
/**
 * Teacher Grades Page
 * Select class â†’ student list with grade input fields.
 * Auto-compute Final Grade. Save as draft or submit final.
 * Publish grades to make them visible to guardians.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('teacher');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    setFlash('danger', 'Teacher profile not found.');
    redirect(APP_URL . '/teacher/teacher-dashboard.php');
}

// Get assigned subject-section combinations
$stmt = $pdo->prepare("
    SELECT DISTINCT sch.subject_id, sch.section_id, sch.school_year, sch.term,
           sub.name AS subject_name, sub.code AS subject_code,
           sec.name AS section_name, sec.grade_level
    FROM schedules sch
    JOIN subjects sub ON sch.subject_id = sub.id
    JOIN sections sec ON sch.section_id = sec.id
    WHERE sch.teacher_id = :tid
    ORDER BY sub.name, sec.name
");
$stmt->execute([':tid' => $teacher['id']]);
$classes = $stmt->fetchAll();

// Selected class
$selSubject  = (int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$selSection  = (int)($_GET['section_id'] ?? $_POST['section_id'] ?? 0);
$selYear     = $_GET['school_year'] ?? $_POST['school_year'] ?? currentSchoolYear();
$selTerm     = $_GET['term'] ?? $_POST['term'] ?? '1st Semester';
$periods     = gradingPeriods();
$selPeriod   = normalizeGradingPeriod($_GET['grading_period'] ?? $_POST['grading_period'] ?? 'quarter1');
$selPeriodLabel = $periods[$selPeriod];

// Find the matching class info
$currentClass = null;
foreach ($classes as $c) {
    if ($c['subject_id'] == $selSubject && $c['section_id'] == $selSection) {
        $currentClass = $c;
        $selYear = $c['school_year'];
        $selTerm = $c['term'];
        break;
    }
}

// Get students and their grades for the selected class
$students = [];
$publishedStatus = 0;
if ($currentClass) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.first_name, s.last_name, s.lrn,
               g.id AS grade_id, g.quarter1, g.quarter2, g.quarter3, g.quarter4, g.final_grade, g.published
        FROM students s
        JOIN sections sec ON s.section_id = sec.id
        LEFT JOIN grades g ON g.student_id = s.id 
            AND g.subject_id = :subid 
            AND g.school_year = :sy 
            AND g.term = :term
        WHERE s.section_id = :secid
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([
        ':subid' => $selSubject,
        ':secid' => $selSection,
        ':sy'    => $selYear,
        ':term'  => $selTerm,
    ]);
    $students = $stmt->fetchAll();

    // Check if grades are published (use first student's record as indicator)
    foreach ($students as $stu) {
        if ($stu['grade_id'] !== null) {
            $publishedStatus = (int)($stu['published'] ?? 0);
            break;
        }
    }
}

// â”€â”€ Handle grade submission â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentClass) {
    validateCsrf();

    $postAction = $_POST['form_action'] ?? 'save_grades';

    // â”€â”€ Publish/Unpublish Toggle â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($postAction === 'publish' || $postAction === 'unpublish') {
        $newPublished = ($postAction === 'publish') ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE grades SET published = :pub
            WHERE subject_id = :subid AND school_year = :sy AND term = :term
            AND student_id IN (
                SELECT s.id FROM students s WHERE s.section_id = :secid
            )
        ");
        $stmt->execute([
            ':pub'   => $newPublished,
            ':subid' => $selSubject,
            ':sy'    => $selYear,
            ':term'  => $selTerm,
            ':secid' => $selSection,
        ]);

        auditLog($postAction === 'publish' ? 'grades_published' : 'grades_unpublished', 'grades', $selSubject, null, [
            'section_id' => $selSection,
            'school_year' => $selYear,
            'term' => $selTerm,
        ]);
        setFlash('success', $postAction === 'publish' ? 'Grades published! Guardians can now view them.' : 'Grades unpublished. Guardians can no longer view them.');
        redirect(APP_URL . '/teacher/teacher-grades.php?subject_id=' . $selSubject . '&section_id=' . $selSection . '&grading_period=' . urlencode($selPeriod));
    }

    // â”€â”€ Save Grades â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $periodGrades = $_POST['grade'] ?? [];
    $studentIds  = $_POST['student_ids'] ?? [];

    try {
        $pdo->beginTransaction();

        foreach ($studentIds as $idx => $studentId) {
            $studentId = (int)$studentId;
            $gradeValue = is_numeric($periodGrades[$idx] ?? '') ? (float)$periodGrades[$idx] : null;
            if ($gradeValue !== null && ($gradeValue < 0 || $gradeValue > 100)) {
                throw new InvalidArgumentException('Grades must be between 0 and 100.');
            }

            $stmt = $pdo->prepare("
                SELECT id, quarter1, quarter2, quarter3, quarter4
                FROM grades
                WHERE student_id = :sid AND subject_id = :subid AND school_year = :sy AND term = :term
                LIMIT 1
            ");
            $stmt->execute([':sid' => $studentId, ':subid' => $selSubject, ':sy' => $selYear, ':term' => $selTerm]);
            $existing = $stmt->fetch();

            if ($existing) {
                $quarterGrades = [
                    'quarter1' => $existing['quarter1'],
                    'quarter2' => $existing['quarter2'],
                    'quarter3' => $existing['quarter3'],
                    'quarter4' => $existing['quarter4'],
                ];
                $quarterGrades[$selPeriod] = $gradeValue;
                $finalGrade = finalRatingFromQuarterGrades($quarterGrades);

                $stmt = $pdo->prepare("
                    UPDATE grades SET {$selPeriod} = :period_grade, final_grade = :fg,
                           submitted_by = :tch, submitted_at = NOW()
                    WHERE id = :gid
                ");
                $stmt->execute([
                    ':period_grade' => $gradeValue,
                    ':fg' => $finalGrade,
                    ':tch' => $teacher['id'],
                    ':gid' => $existing['id'],
                ]);
            } else {
                $quarterGrades = [
                    'quarter1' => null,
                    'quarter2' => null,
                    'quarter3' => null,
                    'quarter4' => null,
                ];
                $quarterGrades[$selPeriod] = $gradeValue;
                $finalGrade = finalRatingFromQuarterGrades($quarterGrades);

                $stmt = $pdo->prepare("
                    INSERT INTO grades (student_id, subject_id, school_year, term, quarter1, quarter2, quarter3, quarter4, final_grade, submitted_by, submitted_at, published)
                    VALUES (:sid, :subid, :sy, :term, :q1, :q2, :q3, :q4, :fg, :tch, NOW(), 0)
                ");
                $stmt->execute([
                    ':sid' => $studentId,
                    ':subid' => $selSubject,
                    ':sy' => $selYear,
                    ':term' => $selTerm,
                    ':q1' => $quarterGrades['quarter1'],
                    ':q2' => $quarterGrades['quarter2'],
                    ':q3' => $quarterGrades['quarter3'],
                    ':q4' => $quarterGrades['quarter4'],
                    ':fg' => $finalGrade,
                    ':tch' => $teacher['id'],
                ]);
            }
        }

        $pdo->commit();
        auditLog('grades_submitted', 'grades', $selSubject, null, ['section_id' => $selSection, 'period' => $selPeriod, 'count' => count($studentIds)]);
        setFlash('success', $selPeriodLabel . ' grades saved successfully.');
        redirect(APP_URL . '/teacher/teacher-grades.php?subject_id=' . $selSubject . '&section_id=' . $selSection . '&grading_period=' . urlencode($selPeriod));
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Grade save error: ' . $e->getMessage());
        $errors[] = 'An error occurred while saving grades. Please try again.';
    }
}

$pageTitle = 'Encode Grades';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-pencil-square me-2"></i>Encode Grades</h4>
    </div>
</div>

<!-- Class Selector -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="class-selector">
            <div class="col-md-6">
                <label class="form-label">Select Class</label>
                <select class="form-select" name="class" id="class-select" onchange="
                    var parts = this.value.split('-');
                    var period = document.getElementById('grading-period-select').value;
                    window.location.href='?subject_id='+parts[0]+'&section_id='+parts[1]+'&grading_period='+encodeURIComponent(period);
                ">
                    <option value="">-- Select a class --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= (int)$c['subject_id'] ?>-<?= (int)$c['section_id'] ?>"
                            <?= e(($selSubject == $c['subject_id'] && $selSection == $c['section_id']) ? 'selected' : '') ?>>
                            <?= e($c['subject_name']) ?> (<?= e($c['subject_code']) ?>) - Section <?= e($c['section_name']) ?> (<?= e(formatGradeLevel((string)$c['grade_level'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Grading Period</label>
                <select class="form-select" name="grading_period" id="grading-period-select" <?= $currentClass ? '' : 'disabled' ?> onchange="
                    var selectedClass = document.getElementById('class-select').value;
                    if (selectedClass) {
                        var parts = selectedClass.split('-');
                        window.location.href='?subject_id='+parts[0]+'&section_id='+parts[1]+'&grading_period='+encodeURIComponent(this.value);
                    }
                ">
                    <?php foreach ($periods as $periodKey => $periodLabel): ?>
                        <option value="<?= e($periodKey) ?>" <?= $selPeriod === $periodKey ? 'selected' : '' ?>>
                            <?= e($periodLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <?php if ($currentClass): ?>
                    <span class="badge bg-info"><?= e($selYear) ?></span>
                    <span class="badge bg-primary ms-1"><?= e($selPeriodLabel) ?></span>
                    <?php if ($publishedStatus): ?>
                        <span class="badge bg-success ms-1"><i class="bi bi-lock-fill me-1"></i>Published</span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-1"><i class="bi bi-pencil me-1"></i>Draft</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<!-- Grade Entry -->
<?php if ($currentClass && !empty($students)): ?>
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check me-2"></i>Student List - <?= e($currentClass['subject_name']) ?> | Section <?= e($currentClass['section_name']) ?></span>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary"><?= e((string)count($students)) ?> students</span>
            <!-- Publish/Unpublish Button -->
            <form method="POST" class="d-inline" id="publish-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="subject_id" value="<?= (int)$selSubject ?>">
                <input type="hidden" name="section_id" value="<?= (int)$selSection ?>">
                <input type="hidden" name="school_year" value="<?= e($selYear) ?>">
                <input type="hidden" name="term" value="<?= e($selTerm) ?>">
                <input type="hidden" name="grading_period" value="<?= e($selPeriod) ?>">
                <?php if ($publishedStatus): ?>
                    <input type="hidden" name="form_action" value="unpublish">
                    <button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('Unpublish grades? Guardians will no longer see them.')">
                        <i class="bi bi-unlock me-1"></i>Unpublish
                    </button>
                <?php else: ?>
                    <input type="hidden" name="form_action" value="publish">
                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Publish grades? Guardians will be able to view them.')">
                        <i class="bi bi-lock-fill me-1"></i>Publish Grades
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <form method="POST" action="" id="grades-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="subject_id" value="<?= (int)$selSubject ?>">
            <input type="hidden" name="section_id" value="<?= (int)$selSection ?>">
            <input type="hidden" name="school_year" value="<?= e($selYear) ?>">
            <input type="hidden" name="term" value="<?= e($selTerm) ?>">
            <input type="hidden" name="grading_period" value="<?= e($selPeriod) ?>">
            <input type="hidden" name="form_action" value="save_grades">

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>LRN</th>
                            <th class="text-center" style="width:18%;"><?= e($selPeriodLabel) ?></th>
                            <th class="text-center">Descriptor</th>
                            <th class="text-center">Remarks</th>
                            <th class="text-center" style="width:15%;">Final Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $idx => $stu): ?>
                        <?php
                            $periodGrade = $stu[$selPeriod] !== null ? (float)$stu[$selPeriod] : null;
                            $finalGrade = $stu['final_grade'] !== null ? (float)$stu['final_grade'] : null;
                        ?>
                        <tr>
                            <td><?= e((string)($idx + 1)) ?></td>
                            <td><?= e(format_name($stu['first_name'], $stu['last_name'])) ?></td>
                            <td><small class="text-muted"><?= e($stu['lrn'] ?? 'N/A') ?></small></td>
                            <td>
                                <input type="hidden" name="student_ids[]" value="<?= (int)$stu['id'] ?>">
                                <input type="number" class="form-control form-control-sm text-center grade-input" 
                                       name="grade[]" step="0.01" min="0" max="100"
                                       value="<?= e($periodGrade !== null ? number_format($periodGrade, 2) : '') ?>"
                                       data-row="<?= (int)$idx ?>">
                            </td>
                            <td class="text-center" id="descriptor_<?= (int)$idx ?>">
                                <?= e(depedDescriptor($periodGrade)) ?>
                            </td>
                            <td class="text-center" id="remark_<?= (int)$idx ?>">
                                <span class="badge <?= e(depedRemarkBadgeClass($periodGrade)) ?>"><?= e(depedRemark($periodGrade)) ?></span>
                            </td>
                            <td class="text-center fw-semibold">
                                <?= e($finalGrade !== null ? number_format($finalGrade, 2) : 'Pending') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Grades</button>
            </div>
        </form>
    </div>
</div>

<script>
function depedDescriptor(grade) {
    if (grade === null || Number.isNaN(grade)) return 'Pending';
    if (grade >= 90) return 'Outstanding';
    if (grade >= 85) return 'Very Satisfactory';
    if (grade >= 80) return 'Satisfactory';
    if (grade >= 75) return 'Fairly Satisfactory';
    return 'Did Not Meet Expectations';
}

function depedRemark(grade) {
    if (grade === null || Number.isNaN(grade)) {
        return '<span class="badge bg-secondary">Pending</span>';
    }
    return grade >= 75
        ? '<span class="badge bg-success">Passed</span>'
        : '<span class="badge bg-danger">Failed</span>';
}

document.querySelectorAll('.grade-input').forEach(input => {
    input.addEventListener('input', function() {
        const idx = this.dataset.row;
        const descriptorEl = document.getElementById('descriptor_' + idx);
        const remarkEl = document.getElementById('remark_' + idx);
        const grade = this.value === '' ? null : parseFloat(this.value);

        descriptorEl.textContent = depedDescriptor(grade);
        remarkEl.innerHTML = depedRemark(grade);
    });
});
</script>
<?php elseif ($currentClass): ?>
    <div class="alert alert-info">No students found in this section.</div>
<?php else: ?>
    <div class="alert alert-info">Please select a class above to start encoding grades.</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
