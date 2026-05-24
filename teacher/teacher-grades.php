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
$activeYear = currentSchoolYear();
$activeTerm = currentAcademicTerm();

$stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    setFlash('danger', 'Teacher profile not found.');
    redirect(APP_URL . '/teacher/teacher-dashboard.php');
}

$classParams = [
    ':tid' => $teacher['id'],
    ':sy' => $activeYear,
];
$classTermClause = academicTermWhereClause('sch.term', 'class_term', $classParams, $activeTerm);

// Get assigned subject-section combinations
$stmt = $pdo->prepare("
    SELECT DISTINCT sch.subject_id, sch.section_id, sch.school_year, sch.term,
           sub.name AS subject_name, sub.code AS subject_code,
           sec.name AS section_name, sec.grade_level
    FROM schedules sch
    JOIN subjects sub ON sch.subject_id = sub.id
    JOIN sections sec ON sch.section_id = sec.id
    WHERE sch.teacher_id = :tid
      AND sch.school_year = :sy
      AND {$classTermClause}
    ORDER BY sub.name, sec.name
");
$stmt->execute($classParams);
$classes = $stmt->fetchAll();

// Selected class
$selSubject  = (int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$selSection  = (int)($_GET['section_id'] ?? $_POST['section_id'] ?? 0);
$selYear     = $_GET['school_year'] ?? $_POST['school_year'] ?? $activeYear;
$selTerm     = normalizeAcademicTerm($_GET['term'] ?? $_POST['term'] ?? $activeTerm);
$periods     = gradingPeriods();
$selPeriod   = normalizeGradingPeriod($_GET['grading_period'] ?? $_POST['grading_period'] ?? 'quarter1');
$selPeriodLabel = $periods[$selPeriod];

// Find the matching class info
$currentClass = null;
foreach ($classes as $c) {
    if ($c['subject_id'] == $selSubject && $c['section_id'] == $selSection) {
        $currentClass = $c;
        $selYear = $c['school_year'];
        $selTerm = $activeTerm;
        break;
    }
}

// Get students and their grades for the selected class
$students = [];
$publishedStatus = 0;
if ($currentClass) {
    $studentParams = [
        ':subid' => $selSubject,
        ':secid' => $selSection,
        ':sy' => $selYear,
    ];
    $enrollmentTermClause = academicTermWhereClause('e.term', 'student_enrollment_term', $studentParams, $selTerm);
    $gradeTermClause = academicTermWhereClause('g.term', 'student_grade_term', $studentParams, $selTerm, true);
    $stmt = $pdo->prepare("
        SELECT s.id, s.first_name, s.last_name, s.lrn,
               MAX(g.id) AS grade_id,
               MAX(g.quarter1) AS quarter1,
               MAX(g.quarter2) AS quarter2,
               MAX(g.quarter3) AS quarter3,
               MAX(g.quarter4) AS quarter4,
               MAX(COALESCE(g.published, 0)) AS published
        FROM students s
        INNER JOIN enrollments e
            ON e.student_id = s.id
           AND e.status = 'enrolled'
           AND e.school_year = :sy
           AND {$enrollmentTermClause}
        LEFT JOIN grades g ON g.student_id = s.id 
            AND g.subject_id = :subid 
            AND g.school_year = :sy 
            AND {$gradeTermClause}
        WHERE s.section_id = :secid
        GROUP BY s.id, s.first_name, s.last_name, s.lrn
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute($studentParams);
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

        $publishParams = [
            ':pub' => $newPublished,
            ':subid' => $selSubject,
            ':sy' => $selYear,
            ':secid' => $selSection,
        ];
        $publishTermClause = academicTermWhereClause('term', 'publish_term', $publishParams, $selTerm, true);
        $stmt = $pdo->prepare("
            UPDATE grades SET published = :pub
            WHERE subject_id = :subid AND school_year = :sy AND {$publishTermClause}
            AND student_id IN (
                SELECT s.id FROM students s WHERE s.section_id = :secid
            )
        ");
        $stmt->execute($publishParams);

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
    $submittedStudentIds = array_map('intval', $studentIds);
    $allowedStudentIds = array_map('intval', array_column($students, 'id'));
    $studentQuarterSnapshots = [];
    foreach ($students as $studentRow) {
        $studentQuarterSnapshots[(int)$studentRow['id']] = [
            'quarter1' => $studentRow['quarter1'],
            'quarter2' => $studentRow['quarter2'],
            'quarter3' => $studentRow['quarter3'],
            'quarter4' => $studentRow['quarter4'],
        ];
    }

    if (count($submittedStudentIds) !== count(array_unique($submittedStudentIds))) {
        $errors[] = 'Submitted student list contains duplicate rows. Please reload the page and try again.';
    }

    $invalidStudentIds = array_values(array_diff($submittedStudentIds, $allowedStudentIds));
    if (!empty($invalidStudentIds)) {
        appLog('warning', 'Teacher grade student tampering attempt.', [
            'teacher_id' => $teacher['id'],
            'section_id' => $selSection,
            'invalid_student_ids' => $invalidStudentIds,
        ]);
        $errors[] = 'Submitted student list does not match this class. Please reload the page and try again.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            foreach ($submittedStudentIds as $idx => $studentId) {
                $gradeValue = is_numeric($periodGrades[$idx] ?? '') ? (float)$periodGrades[$idx] : null;
                if ($gradeValue !== null && ($gradeValue < 0 || $gradeValue > 100)) {
                    throw new InvalidArgumentException('Grades must be between 0 and 100.');
                }

                $existingParams = [
                    ':sid' => $studentId,
                    ':subid' => $selSubject,
                    ':sy' => $selYear,
                    ':term_exact' => $selTerm,
                ];
                $existingTermClause = academicTermWhereClause('term', 'existing_grade_term', $existingParams, $selTerm);
                $stmt = $pdo->prepare("
                    SELECT id, quarter1, quarter2, quarter3, quarter4
                    FROM grades
                    WHERE student_id = :sid AND subject_id = :subid AND school_year = :sy AND {$existingTermClause}
                    ORDER BY CASE WHEN term = :term_exact THEN 0 ELSE 1 END, id DESC
                    LIMIT 1
                ");
                $stmt->execute($existingParams);
                $existing = $stmt->fetch();

                if ($existing) {
                    $quarterGrades = $studentQuarterSnapshots[$studentId] ?? [
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
                    $quarterGrades = $studentQuarterSnapshots[$studentId] ?? [
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
            auditLog('grades_submitted', 'grades', $selSubject, null, ['section_id' => $selSection, 'period' => $selPeriod, 'count' => count($submittedStudentIds)]);
            setFlash('success', $selPeriodLabel . ' grades saved successfully.');
            redirect(APP_URL . '/teacher/teacher-grades.php?subject_id=' . $selSubject . '&section_id=' . $selSection . '&grading_period=' . urlencode($selPeriod));
        } catch (Throwable $e) {
            $pdo->rollBack();
            logException($e, 'Grade save failed.', [
                'teacher_id' => $teacher['id'],
                'subject_id' => $selSubject,
                'section_id' => $selSection,
                'school_year' => $selYear,
                'term' => $selTerm,
            ]);
            $errors[] = safeErrorMessage('An error occurred while saving grades.');
        }
    }
}

$pageTitle = 'Encode Grades';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-pencil-square me-2"></i>Encode Grades</h4>
        <div class="small text-muted">Active period: <?= activeAcademicPeriodBadge() ?></div>
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
                    <button type="submit" class="btn btn-sm btn-outline-warning" data-confirm="Unpublish these grades? Guardians will no longer be able to see them." data-confirm-variant="warning">
                        <i class="bi bi-unlock me-1"></i>Unpublish
                    </button>
                <?php else: ?>
                    <input type="hidden" name="form_action" value="publish">
                    <button type="submit" class="btn btn-sm btn-success" data-confirm="Publish these grades? Guardians will be able to view them." data-confirm-variant="primary">
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
                            <?php foreach ($periods as $periodKey => $periodLabel): ?>
                                <th class="text-center" style="width:12%;">
                                    <?= e($periodLabel) ?>
                                    <?php if ($periodKey === $selPeriod): ?>
                                        <span class="badge bg-primary ms-1">Editing</span>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center">Descriptor</th>
                            <th class="text-center">Remarks</th>
                            <th class="text-center" style="width:15%;">Final Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $idx => $stu): ?>
                        <?php
                            $periodGrade = $stu[$selPeriod] !== null ? (float)$stu[$selPeriod] : null;
                            $quarterGradesForRow = [
                                'quarter1' => $stu['quarter1'],
                                'quarter2' => $stu['quarter2'],
                                'quarter3' => $stu['quarter3'],
                                'quarter4' => $stu['quarter4'],
                            ];
                            $finalGrade = finalRatingFromQuarterGrades($quarterGradesForRow);
                        ?>
                        <tr>
                            <td><?= e((string)($idx + 1)) ?></td>
                            <td><?= e(format_name($stu['first_name'], $stu['last_name'])) ?></td>
                            <td><small class="text-muted"><?= e($stu['lrn'] ?? 'N/A') ?></small></td>
                            <?php foreach ($periods as $periodKey => $_periodLabel): ?>
                                <?php $quarterValue = $stu[$periodKey] !== null ? (float)$stu[$periodKey] : null; ?>
                                <td class="text-center">
                                    <?php if ($periodKey === $selPeriod): ?>
                                        <input type="hidden" name="student_ids[]" value="<?= (int)$stu['id'] ?>">
                                        <input type="number" class="form-control form-control-sm text-center grade-input"
                                               name="grade[]" step="0.01" min="0" max="100"
                                               value="<?= e($quarterValue !== null ? number_format($quarterValue, 2) : '') ?>"
                                               data-row="<?= (int)$idx ?>">
                                    <?php else: ?>
                                        <?= e($quarterValue !== null ? number_format($quarterValue, 2) : '-') ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
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
    <?= emptyStateHtml('No students are enrolled in this section yet.', 'Students appear here once the registrar enrolls and assigns them to this section. Please coordinate with the registrar if you expect students.', 'bi-people') ?>
<?php else: ?>
    <?= emptyStateHtml('Select a class to start encoding grades.', 'Choose a section, subject, and grading period above. If no classes are listed, ask the administrator to assign your teaching load.', 'bi-pencil-square') ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
