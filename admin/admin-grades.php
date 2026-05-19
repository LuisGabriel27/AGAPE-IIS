<?php
/**
 * Admin Grades - View and override DepEd periodic grades with audit trail.
 * Admin can also toggle published status.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$filterStudent = (int)($_GET['student_id'] ?? 0);
$studentSearch = trim($_GET['student_search'] ?? '');
$filterYear    = $_GET['school_year'] ?? '';
$errors        = [];

$gradesFilterUrl = static function (int $studentId, string $search, string $schoolYear): string {
    $params = [];
    if ($studentId > 0) {
        $params['student_id'] = $studentId;
    }
    if ($search !== '') {
        $params['student_search'] = $search;
    }
    if ($schoolYear !== '') {
        $params['school_year'] = $schoolYear;
    }

    return APP_URL . '/admin/admin-grades.php' . (!empty($params) ? '?' . http_build_query($params) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $postAction = $_POST['form_action'] ?? 'override';

    if ($postAction === 'toggle_publish') {
        $gradeId = (int)($_POST['grade_id'] ?? 0);
        if ($gradeId) {
            $stmt = $pdo->prepare("SELECT published FROM grades WHERE id = :id");
            $stmt->execute([':id' => $gradeId]);
            $currentPub = (int)$stmt->fetchColumn();
            $newPub = $currentPub ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE grades SET published = :pub WHERE id = :id");
            $stmt->execute([':pub' => $newPub, ':id' => $gradeId]);

            auditLog($newPub ? 'grade_published' : 'grade_unpublished', 'grades', $gradeId);
            setFlash('success', $newPub ? 'Grade published.' : 'Grade unpublished.');
            redirect($gradesFilterUrl($filterStudent, $studentSearch, $filterYear));
        }
    }

    if ($postAction === 'override') {
        $gradeId = (int)($_POST['grade_id'] ?? 0);
        $quarterGrades = [];
        foreach (array_keys(gradingPeriods()) as $column) {
            $value = $_POST[$column] ?? '';
            $quarterGrades[$column] = is_numeric($value) ? (float)$value : null;
            if ($quarterGrades[$column] !== null && ($quarterGrades[$column] < 0 || $quarterGrades[$column] > 100)) {
                $errors[] = 'Grades must be between 0 and 100.';
            }
        }

        if ($gradeId && empty($errors)) {
            $stmt = $pdo->prepare("SELECT quarter1, quarter2, quarter3, quarter4, final_grade FROM grades WHERE id = :id");
            $stmt->execute([':id' => $gradeId]);
            $oldData = $stmt->fetch();

            $finalGrade = finalRatingFromQuarterGrades($quarterGrades);
            $stmt = $pdo->prepare("
                UPDATE grades
                SET quarter1 = :q1,
                    quarter2 = :q2,
                    quarter3 = :q3,
                    quarter4 = :q4,
                    final_grade = :fg,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':q1' => $quarterGrades['quarter1'],
                ':q2' => $quarterGrades['quarter2'],
                ':q3' => $quarterGrades['quarter3'],
                ':q4' => $quarterGrades['quarter4'],
                ':fg' => $finalGrade,
                ':id' => $gradeId,
            ]);

            auditLog('grade_override', 'grades', $gradeId, $oldData, $quarterGrades + ['final_grade' => $finalGrade]);
            setFlash('success', 'Grade overridden successfully.');
            redirect($gradesFilterUrl($filterStudent, $studentSearch, $filterYear));
        }
    }
}

$allStudents = $pdo->query("
    SELECT s.id, s.first_name, s.last_name, s.lrn, s.grade_level, sec.name AS section_name
    FROM students s
    LEFT JOIN sections sec ON sec.id = s.section_id
    ORDER BY s.last_name, s.first_name, s.id
")->fetchAll();
$years = $pdo->query("SELECT DISTINCT school_year FROM grades ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) {
    $years = [currentSchoolYear()];
}

$studentSearchLabel = static function (array $student): string {
    $name = format_name($student['first_name'] ?? '', $student['last_name'] ?? '');
    $details = [];
    if (!empty($student['lrn'])) {
        $details[] = 'LRN ' . $student['lrn'];
    }
    if (!empty($student['grade_level'])) {
        $grade = formatGradeLevel((string)$student['grade_level']);
        if (!empty($student['section_name'])) {
            $grade .= ' - ' . $student['section_name'];
        }
        $details[] = $grade;
    }

    return $name . (!empty($details) ? ' (' . implode(' | ', $details) . ')' : '');
};

$studentSearchItems = array_map(static function (array $student) use ($studentSearchLabel): array {
    $name = format_name($student['first_name'] ?? '', $student['last_name'] ?? '');
    $searchText = strtolower(implode(' ', [
        $student['lrn'] ?? '',
        $student['last_name'] ?? '',
        $student['first_name'] ?? '',
        $name,
        $studentSearchLabel($student),
    ]));

    return [
        'id' => (int)$student['id'],
        'label' => $studentSearchLabel($student),
        'name' => $name,
        'lrn' => (string)($student['lrn'] ?? ''),
        'grade' => formatGradeLevel((string)($student['grade_level'] ?? '')),
        'section' => (string)($student['section_name'] ?? ''),
        'search' => $searchText,
    ];
}, $allStudents);

$selectedStudent = null;
foreach ($allStudents as $student) {
    if ((int)$student['id'] === $filterStudent) {
        $selectedStudent = $student;
        break;
    }
}

$searchMatches = [];
if (!$selectedStudent && $studentSearch !== '') {
    $needle = strtolower($studentSearch);
    foreach ($studentSearchItems as $item) {
        if (str_contains($item['search'], $needle)) {
            $searchMatches[] = $item;
        }
    }
    $searchMatches = array_slice($searchMatches, 0, 10);

    if (count($searchMatches) === 1) {
        $filterStudent = (int)$searchMatches[0]['id'];
        foreach ($allStudents as $student) {
            if ((int)$student['id'] === $filterStudent) {
                $selectedStudent = $student;
                break;
            }
        }
    }
}

$studentSearchValue = $selectedStudent ? $studentSearchLabel($selectedStudent) : $studentSearch;

$grades = [];
if ($filterStudent) {
    $where = "WHERE g.student_id = :sid";
    $params = [':sid' => $filterStudent];
    if ($filterYear) {
        $where .= " AND g.school_year = :sy";
        $params[':sy'] = $filterYear;
    }

    $stmt = $pdo->prepare("
        SELECT g.*, sub.name AS subject_name, sub.code AS subject_code,
               CASE WHEN t.first_name = '' THEN t.last_name ELSE t.last_name || ', ' || t.first_name END AS teacher_name
        FROM grades g
        JOIN subjects sub ON g.subject_id = sub.id
        LEFT JOIN teachers t ON g.submitted_by = t.id
        {$where}
        ORDER BY g.school_year DESC, sub.name
    ");
    $stmt->execute($params);
    $grades = $stmt->fetchAll();
}

$pageTitle = 'Manage Grades';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12"><h4 class="fw-bold"><i class="bi bi-card-checklist me-2"></i>Grades Management</h4></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <form method="GET" class="row g-3 align-items-start grades-filter-form" id="grades-filter">
        <div class="col-md-6">
            <label class="form-label" for="student-search">Student</label>
            <input type="hidden" name="student_id" id="student-id" value="<?= e($filterStudent ? (string)$filterStudent : '') ?>">
            <div class="student-search-picker">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="search"
                           class="form-control"
                           name="student_search"
                           id="student-search"
                           value="<?= e($studentSearchValue) ?>"
                           placeholder="Search LRN or last name..."
                           autocomplete="off"
                           required>
                </div>
                <div class="student-search-results d-none" id="student-search-results"></div>
            </div>
            <?php if ($studentSearch !== '' && !$filterStudent && !empty($searchMatches)): ?>
                <div class="student-server-matches mt-2">
                    <div class="text-muted small mb-1">Multiple students matched. Choose one:</div>
                    <?php foreach ($searchMatches as $match): ?>
                        <a class="student-server-match"
                           href="<?= e($gradesFilterUrl((int)$match['id'], $match['label'], $filterYear)) ?>">
                            <span><?= e($match['name']) ?></span>
                            <small><?= e(trim(($match['lrn'] ? 'LRN ' . $match['lrn'] : '') . ' ' . ($match['grade'] !== 'N/A' ? '| ' . $match['grade'] : ''))) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($studentSearch !== '' && !$filterStudent): ?>
                <span class="field-error text-danger small d-block mt-1">No student matched that LRN or name.</span>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="school-year">School Year</label>
            <select class="form-select" name="school_year" id="school-year">
                <option value="">All</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= e($y) ?>" <?= e($filterYear === $y ? 'selected' : '') ?>><?= e($y) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <label class="form-label d-none d-md-block">&nbsp;</label>
            <button type="submit" class="btn btn-primary"><i class="bi bi-filter me-1"></i>View</button>
        </div>
    </form>
</div></div>

<?php if ($filterStudent && !empty($grades)): ?>
<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="admin-grades-table">
        <thead>
            <tr>
                <th>Subject</th>
                <th>School Year</th>
                <th class="text-center">1st</th>
                <th class="text-center">2nd</th>
                <th class="text-center">3rd</th>
                <th class="text-center">4th</th>
                <th class="text-center">Final Rating</th>
                <th class="text-center">Descriptor</th>
                <th>Submitted By</th>
                <th class="text-center">Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grades as $g): ?>
            <?php $final = $g['final_grade'] !== null ? (float)$g['final_grade'] : null; ?>
            <tr>
                <td><span class="badge bg-secondary"><?= e($g['subject_code']) ?></span> <?= e($g['subject_name']) ?></td>
                <td><?= e($g['school_year']) ?></td>
                <td class="text-center"><?= e($g['quarter1'] !== null ? number_format((float)$g['quarter1'], 2) : '-') ?></td>
                <td class="text-center"><?= e($g['quarter2'] !== null ? number_format((float)$g['quarter2'], 2) : '-') ?></td>
                <td class="text-center"><?= e($g['quarter3'] !== null ? number_format((float)$g['quarter3'], 2) : '-') ?></td>
                <td class="text-center"><?= e($g['quarter4'] !== null ? number_format((float)$g['quarter4'], 2) : '-') ?></td>
                <td class="text-center fw-bold"><?= e($final !== null ? number_format($final, 2) : 'Pending') ?></td>
                <td class="text-center"><?= e(depedDescriptor($final)) ?></td>
                <td><small><?= e($g['teacher_name'] ?? 'N/A') ?></small></td>
                <td class="text-center">
                    <?php if ((int)($g['published'] ?? 0)): ?>
                        <span class="badge bg-success"><i class="bi bi-lock-fill me-1"></i>Published</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><i class="bi bi-pencil me-1"></i>Draft</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editGrade<?= (int)$g['id'] ?>">
                            <i class="bi bi-pencil"></i> Override
                        </button>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="form_action" value="toggle_publish">
                            <input type="hidden" name="grade_id" value="<?= (int)$g['id'] ?>">
                            <?php if ((int)($g['published'] ?? 0)): ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Unpublish" aria-label="Unpublish grade"><i class="bi bi-unlock"></i></button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Publish" aria-label="Publish grade"><i class="bi bi-lock"></i></button>
                            <?php endif; ?>
                        </form>
                    </div>
                </td>
            </tr>

            <div class="modal fade" id="editGrade<?= (int)$g['id'] ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h6 class="modal-title">Override: <?= e($g['subject_name']) ?></h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="form_action" value="override">
                                <input type="hidden" name="grade_id" value="<?= (int)$g['id'] ?>">
                                <div class="row">
                                    <?php foreach (gradingPeriods() as $column => $label): ?>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label small"><?= e($label) ?></label>
                                            <input type="number" class="form-control form-control-sm" name="<?= e($column) ?>" step="0.01" min="0" max="100" value="<?= e((string)($g[$column] ?? '')) ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="alert alert-info small mb-0">Final Rating is computed only after all four grading periods are encoded.</div>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" class="btn btn-sm btn-warning">Override</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php elseif ($filterStudent): ?>
    <?= emptyStateHtml('No grades recorded for this student yet.', 'Grades are encoded by class teachers per subject and grading period. They will appear here once teachers submit them for this school year.', 'bi-card-checklist') ?>
<?php endif; ?>

<style>
.grades-filter-form .form-control,
.grades-filter-form .form-select,
.grades-filter-form .input-group-text,
.grades-filter-form .btn {
    min-height: 50px;
}
.student-search-picker {
    position: relative;
}
.student-search-results {
    position: absolute;
    z-index: 20;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    max-height: 280px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    box-shadow: var(--shadow-lg);
}
.student-search-result,
.student-server-match {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    width: 100%;
    padding: 0.7rem 0.85rem;
    border: 0;
    border-bottom: 1px solid var(--border-color);
    background: #fff;
    color: var(--text-primary);
    text-align: left;
    text-decoration: none;
}
.student-search-result:hover,
.student-search-result:focus,
.student-server-match:hover,
.student-server-match:focus {
    background: var(--primary-light);
    color: var(--primary);
}
.student-search-result:last-child,
.student-server-match:last-child {
    border-bottom: 0;
}
.student-search-result small,
.student-server-match small {
    color: var(--text-secondary);
    white-space: nowrap;
}
.student-server-matches {
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.5rem;
    background: #fff;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const students = <?= json_encode($studentSearchItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const form = document.getElementById('grades-filter');
    const input = document.getElementById('student-search');
    const hiddenId = document.getElementById('student-id');
    const results = document.getElementById('student-search-results');

    if (!form || !input || !hiddenId || !results) {
        return;
    }

    function hideResults() {
        results.classList.add('d-none');
        results.innerHTML = '';
    }

    function setStudent(student) {
        hiddenId.value = String(student.id);
        input.value = student.label;
        hideResults();
    }

    function studentMeta(student) {
        const parts = [];
        if (student.lrn) {
            parts.push('LRN ' + student.lrn);
        }
        if (student.grade && student.grade !== 'N/A') {
            parts.push(student.grade + (student.section ? ' - ' + student.section : ''));
        }
        return parts.join(' | ');
    }

    function renderResults() {
        const term = input.value.trim().toLowerCase();
        hiddenId.value = '';
        results.innerHTML = '';

        if (term.length < 2) {
            hideResults();
            return;
        }

        const matches = students
            .filter((student) => student.search.includes(term))
            .slice(0, 12);

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'student-search-result text-muted';
            empty.textContent = 'No matching student found.';
            results.appendChild(empty);
            results.classList.remove('d-none');
            return;
        }

        matches.forEach((student) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'student-search-result';

            const name = document.createElement('span');
            name.textContent = student.name;
            button.appendChild(name);

            const meta = document.createElement('small');
            meta.textContent = studentMeta(student);
            button.appendChild(meta);

            button.addEventListener('click', () => setStudent(student));
            results.appendChild(button);
        });

        results.classList.remove('d-none');
    }

    input.addEventListener('input', renderResults);
    input.addEventListener('focus', function () {
        if (input.value.trim().length >= 2 && hiddenId.value === '') {
            renderResults();
        }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.student-search-picker')) {
            hideResults();
        }
    });

    form.addEventListener('submit', function (event) {
        if (hiddenId.value !== '') {
            return;
        }

        const term = input.value.trim().toLowerCase();
        const exact = students.find((student) =>
            student.label.toLowerCase() === term ||
            student.lrn.toLowerCase() === term ||
            student.name.toLowerCase() === term
        );

        if (exact) {
            setStudent(exact);
            return;
        }

        event.preventDefault();
        renderResults();
        input.focus();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
