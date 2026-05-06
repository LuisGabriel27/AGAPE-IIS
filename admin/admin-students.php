<?php
/**
 * Admin Students — combined student + guardian registration form.
 * Creating a student lets you simultaneously create or link a guardian.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin', 'clerk']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo     = getDB();
$search  = trim($_GET['search'] ?? '');
$action  = $_GET['action'] ?? '';
$id      = (int)($_GET['id'] ?? 0);
$errors  = [];

$isClerk = ($_SESSION['role'] ?? '') === 'clerk';

// ── Handle Delete ────────────────────────────────────────
if (!$isClerk && $action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $pdo->prepare("DELETE FROM students WHERE id = :id")->execute([':id' => $id]);
    auditLog('delete_student', 'students', $id);
    setFlash('success', 'Student deleted.');
    redirect(APP_URL . '/admin/admin-students.php');
}

// ── Handle Create / Update ───────────────────────────────
if (!$isClerk && in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    // Student fields
    $data = [
        'first_name'  => trim($_POST['first_name'] ?? ''),
        'last_name'   => trim($_POST['last_name'] ?? ''),
        'birthdate'   => trim($_POST['birthdate'] ?? ''),
        'gender'      => trim($_POST['gender'] ?? ''),
        'grade_level' => trim($_POST['grade_level'] ?? ''),
        'section_id'  => (int)($_POST['section_id'] ?? 0) ?: null,
        'lrn'         => trim($_POST['lrn'] ?? ''),
    ];

    // Guardian mode: 'existing' | 'new' | 'none'
    $guardianMode    = trim($_POST['guardian_mode'] ?? 'none');
    $existingGid     = (int)($_POST['existing_guardian_id'] ?? 0) ?: null;
    $newGFirstName   = trim($_POST['new_g_first_name'] ?? '');
    $newGLastName    = trim($_POST['new_g_last_name'] ?? '');
    $newGEmail       = trim($_POST['new_g_email'] ?? '');
    $newGContact     = trim($_POST['new_g_contact'] ?? '');
    $newGRel         = trim($_POST['new_g_relationship'] ?? '');

    if (empty($data['last_name'])) $errors[] = 'Student last name is required.';

    if ($guardianMode === 'new') {
        if (empty($newGLastName))  $errors[] = 'Guardian last name is required.';
        if (empty($newGEmail) || !filter_var($newGEmail, FILTER_VALIDATE_EMAIL))
            $errors[] = 'A valid guardian email is required.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Resolve guardian_id
            $resolvedGid = null;
            if ($guardianMode === 'existing' && $existingGid) {
                $resolvedGid = $existingGid;
            } elseif ($guardianMode === 'new') {
                // Reuse existing user account if email already exists
                $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
                $uStmt->execute([':e' => $newGEmail]);
                $existingUser = $uStmt->fetch();

                if ($existingUser) {
                    $uid = (int)$existingUser['id'];
                    // Check if they already have a guardian profile
                    $gStmt = $pdo->prepare("SELECT id FROM guardians WHERE user_id = :uid LIMIT 1");
                    $gStmt->execute([':uid' => $uid]);
                    $existingG = $gStmt->fetch();
                    if ($existingG) {
                        $resolvedGid = (int)$existingG['id'];
                    } else {
                        $gIns = $pdo->prepare("INSERT INTO guardians (user_id, first_name, last_name, contact_number, relationship_to_student) VALUES (:uid, :fn, :ln, :c, :r) RETURNING id");
                        $gIns->execute([':uid' => $uid, ':fn' => $newGFirstName, ':ln' => $newGLastName, ':c' => $newGContact ?: null, ':r' => $newGRel ?: null]);
                        $resolvedGid = (int)$gIns->fetchColumn();
                    }
                } else {
                    // Create new user + guardian
                    $hash = password_hash('Guardian@1234', PASSWORD_BCRYPT);
                    $uIns = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (:e, :h, 'guardian', 1, NOW()) RETURNING id");
                    $uIns->execute([':e' => $newGEmail, ':h' => $hash]);
                    $uid = (int)$uIns->fetchColumn();

                    // Seed user_roles
                    $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (:uid, 'guardian') ON CONFLICT DO NOTHING")
                        ->execute([':uid' => $uid]);

                    $gIns = $pdo->prepare("INSERT INTO guardians (user_id, first_name, last_name, contact_number, relationship_to_student) VALUES (:uid, :fn, :ln, :c, :r) RETURNING id");
                    $gIns->execute([':uid' => $uid, ':fn' => $newGFirstName, ':ln' => $newGLastName, ':c' => $newGContact ?: null, ':r' => $newGRel ?: null]);
                    $resolvedGid = (int)$gIns->fetchColumn();

                    auditLog('create_guardian', 'guardians', $resolvedGid);
                }
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO students (guardian_id, first_name, last_name, birthdate, gender, grade_level, section_id, lrn)
                    VALUES (:gid, :fname, :lname, :birth, :gender, :grade, :sec, :lrn) RETURNING id
                ");
                $stmt->execute([
                    ':gid'    => $resolvedGid,
                    ':fname'  => $data['first_name'],
                    ':lname'  => $data['last_name'],
                    ':birth'  => $data['birthdate'] ?: null,
                    ':gender' => $data['gender'] ?: null,
                    ':grade'  => $data['grade_level'],
                    ':sec'    => $data['section_id'],
                    ':lrn'    => $data['lrn'] ?: null,
                ]);
                $newStudentId = (int)$stmt->fetchColumn();
                auditLog('create_student', 'students', $newStudentId);

                $msg = 'Student created.';
                if ($guardianMode === 'new') {
                    $msg .= ' Guardian account created with default password: <strong>Guardian@1234</strong>';
                }
                $pdo->commit();
                setFlash('success', $msg);
            } else {
                // edit: update student, optionally re-link guardian
                $stmt = $pdo->prepare("
                    UPDATE students
                    SET first_name=:fname, last_name=:lname, birthdate=:birth, gender=:gender,
                        grade_level=:grade, section_id=:sec, lrn=:lrn,
                        guardian_id=COALESCE(:gid, guardian_id)
                    WHERE id=:id
                ");
                $stmt->execute([
                    ':fname'  => $data['first_name'],
                    ':lname'  => $data['last_name'],
                    ':birth'  => $data['birthdate'] ?: null,
                    ':gender' => $data['gender'] ?: null,
                    ':grade'  => $data['grade_level'],
                    ':sec'    => $data['section_id'],
                    ':lrn'    => $data['lrn'] ?: null,
                    ':gid'    => $resolvedGid,
                    ':id'     => $id,
                ]);
                auditLog('update_student', 'students', $id);
                $pdo->commit();
                setFlash('success', 'Student updated.');
            }

            redirect(APP_URL . '/admin/admin-students.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Student save error: ' . $e->getMessage());
            $errors[] = 'An error occurred while saving. Please try again.';
        }
    }
}

// ── Fetch student for edit ───────────────────────────────
$editStudent  = null;
$editGuardian = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT s.*, g.id AS g_id, g.first_name AS g_first_name, g.last_name AS g_last_name, g.contact_number AS g_contact, g.relationship_to_student AS g_relationship, u.email AS g_email FROM students s LEFT JOIN guardians g ON s.guardian_id = g.id LEFT JOIN users u ON g.user_id = u.id WHERE s.id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editStudent = $stmt->fetch();
}

// ── List with search & pagination ────────────────────────
$where  = '';
$params = [];
if ($search !== '') {
    $where = "WHERE s.last_name ILIKE :search OR s.first_name ILIKE :search2 OR s.lrn ILIKE :search3";
    $params[':search']  = "{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':search3'] = "%{$search}%";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s {$where}");
$countStmt->execute($params);
[$offset, $limit, $page, $totalPages] = paginate((int)$countStmt->fetchColumn());

$stmt = $pdo->prepare("
    SELECT s.*,
           CASE WHEN g.first_name = '' THEN g.last_name ELSE g.last_name || ', ' || g.first_name END AS guardian_name,
           g.contact_number AS guardian_contact,
           sec.name AS section_name
    FROM students s
    LEFT JOIN guardians g   ON s.guardian_id = g.id
    LEFT JOIN sections  sec ON s.section_id  = sec.id
    {$where}
    ORDER BY s.last_name ASC, s.first_name ASC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// Dropdowns
$sections  = $pdo->query("SELECT id, name, grade_level FROM sections ORDER BY grade_level, name")->fetchAll();
$guardians = $pdo->query("SELECT g.id, g.first_name, g.last_name, g.contact_number, g.relationship_to_student, u.email FROM guardians g JOIN users u ON g.user_id = u.id ORDER BY g.last_name, g.first_name")->fetchAll();

$pageTitle = 'Manage Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h4 class="fw-bold"><i class="bi bi-people me-2"></i>Students</h4>
    </div>
    <div class="col-md-6 text-md-end">
        <?php if (!$isClerk): ?>
        <a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Student</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (!$isClerk && in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold">
        <i class="bi bi-person-plus me-2"></i><?= e($action === 'create' ? 'Add New Student' : 'Edit Student') ?>
    </div>
    <div class="card-body">
        <form method="POST" id="student-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <!-- ── Student Information ─────────────────── -->
            <h6 class="fw-semibold text-primary mb-3">Student Information</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="last_name"
                           value="<?= e($editStudent['last_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="first_name"
                           value="<?= e($editStudent['first_name'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Birthdate</label>
                    <input type="date" class="form-control" name="birthdate"
                           value="<?= e($editStudent['birthdate'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="gender">
                        <option value="">Select...</option>
                        <?php foreach (['male','female','other'] as $g): ?>
                            <option value="<?= e($g) ?>" <?= e(($editStudent['gender'] ?? '') === $g ? 'selected' : '') ?>><?= e(ucfirst($g)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Grade Level</label>
                    <select class="form-select" name="grade_level">
                        <option value="">Select...</option>
                        <?php foreach (['Kindergarten','1','2','3','4','5','6'] as $gl): ?>
                            <option value="<?= e($gl) ?>" <?= e(($editStudent['grade_level'] ?? '') == $gl ? 'selected' : '') ?>><?= $gl === 'Kindergarten' ? 'Kindergarten' : 'Grade ' . e($gl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5 mb-3">
                    <label class="form-label">Section</label>
                    <select class="form-select" name="section_id">
                        <option value="0">None / To be assigned</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?= (int)$sec['id'] ?>" <?= e(($editStudent['section_id'] ?? 0) == $sec['id'] ? 'selected' : '') ?>><?= e($sec['name']) ?> (Grade <?= e((string)$sec['grade_level']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">LRN</label>
                    <input type="text" class="form-control" name="lrn"
                           value="<?= e($editStudent['lrn'] ?? '') ?>"
                           maxlength="12" placeholder="12-digit LRN">
                </div>
            </div>

            <hr class="my-3">

            <!-- ── Guardian / Parent ──────────────────── -->
            <h6 class="fw-semibold text-primary mb-3"><i class="bi bi-person-heart me-1"></i>Guardian / Parent</h6>

            <?php
            $hasLinkedGuardian = !empty($editStudent['g_id']);
            $defaultMode = $hasLinkedGuardian ? 'existing' : 'new';
            ?>

            <!-- Mode toggle -->
            <div class="d-flex gap-2 mb-3" id="guardian-mode-btns">
                <button type="button" class="btn btn-sm mode-btn <?= $defaultMode === 'existing' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                        data-mode="existing" onclick="setGuardianMode('existing')">
                    <i class="bi bi-person-check me-1"></i>Link Existing Guardian
                </button>
                <button type="button" class="btn btn-sm mode-btn <?= $defaultMode === 'new' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                        data-mode="new" onclick="setGuardianMode('new')">
                    <i class="bi bi-person-plus me-1"></i>Create New Guardian
                </button>
                <button type="button" class="btn btn-sm mode-btn <?= $defaultMode === 'none' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                        data-mode="none" onclick="setGuardianMode('none')">
                    <i class="bi bi-dash-circle me-1"></i>No Guardian
                </button>
            </div>
            <input type="hidden" name="guardian_mode" id="guardian_mode_input" value="<?= e($defaultMode) ?>">

            <!-- Panel: Link Existing -->
            <div id="panel-existing" class="guardian-panel <?= $defaultMode !== 'existing' ? 'd-none' : '' ?>">
                <div class="mb-3">
                    <label class="form-label">Search &amp; Select Guardian</label>
                    <input type="text" class="form-control mb-2" id="existing-guardian-search"
                           placeholder="Type name or email to filter..."
                           autocomplete="off">
                    <div id="existing-guardian-list" class="border rounded" style="max-height:220px;overflow-y:auto;">
                        <?php foreach ($guardians as $g): ?>
                        <label class="guardian-option d-flex align-items-center gap-3 px-3 py-2 border-bottom"
                               for="gopt_<?= (int)$g['id'] ?>"
                               data-name="<?= e(strtolower($g['last_name'] . ' ' . $g['first_name'])) ?>"
                               data-email="<?= e(strtolower($g['email'])) ?>"
                               style="cursor:pointer;">
                            <input class="form-check-input mt-0" type="radio" name="existing_guardian_id"
                                   id="gopt_<?= (int)$g['id'] ?>" value="<?= (int)$g['id'] ?>"
                                   <?= ($editStudent['g_id'] ?? 0) == $g['id'] ? 'checked' : '' ?>
                                   style="width:18px;height:18px;">
                            <div>
                                <div class="fw-semibold"><?= e(format_name($g['first_name'], $g['last_name'])) ?></div>
                                <small class="text-muted"><?= e($g['email']) ?><?= $g['contact_number'] ? ' · ' . e($g['contact_number']) : '' ?></small>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($guardians)): ?>
                            <div class="text-muted small text-center py-3">No guardians in the system yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Panel: Create New -->
            <div id="panel-new" class="guardian-panel <?= $defaultMode !== 'new' ? 'd-none' : '' ?>">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Guardian Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="new_g_last_name"
                               value="<?= e($editStudent['g_last_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Guardian First Name</label>
                        <input type="text" class="form-control" name="new_g_first_name"
                               value="<?= e($editStudent['g_first_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="new_g_email"
                               value="<?= e($editStudent['g_email'] ?? '') ?>"
                               placeholder="Used for portal login">
                        <div class="form-text">Default password will be <strong>Guardian@1234</strong></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Contact Number</label>
                        <input type="text" class="form-control" name="new_g_contact"
                               value="<?= e($editStudent['g_contact'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Relationship</label>
                        <select class="form-select" name="new_g_relationship">
                            <option value="">Select...</option>
                            <?php foreach (['Parent','Guardian','Sibling','Grandparent','Aunt/Uncle','Other'] as $r): ?>
                                <option value="<?= e($r) ?>" <?= e(($editStudent['g_relationship'] ?? '') === $r ? 'selected' : '') ?>><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Panel: No Guardian -->
            <div id="panel-none" class="guardian-panel <?= $defaultMode !== 'none' ? 'd-none' : '' ?>">
                <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No guardian will be linked to this student. You can assign one later by editing the student record.</p>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                <a href="<?= APP_URL ?>/admin/admin-students.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style>
.guardian-option { transition: background 0.12s; }
.guardian-option:hover { background: #f0f4ff; }
.guardian-option:has(input:checked) { background: #e8f0fe; }
.guardian-option:last-child { border-bottom: none !important; }
</style>
<script>
function setGuardianMode(mode) {
    document.getElementById('guardian_mode_input').value = mode;
    document.querySelectorAll('.guardian-panel').forEach(p => p.classList.add('d-none'));
    document.getElementById('panel-' + mode).classList.remove('d-none');
    document.querySelectorAll('.mode-btn').forEach(btn => {
        const active = btn.dataset.mode === mode;
        btn.classList.toggle('btn-primary', active);
        btn.classList.toggle('btn-outline-secondary', !active);
    });
}

// Filter existing guardian list
document.getElementById('existing-guardian-search')?.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.guardian-option').forEach(opt => {
        const match = !q || opt.dataset.name.includes(q) || opt.dataset.email.includes(q);
        opt.style.display = match ? 'flex' : 'none';
    });
});
</script>
<?php endif; ?>

<!-- Search bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center" id="student-search">
            <div class="col-md-8">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Search by name or LRN..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
                <?php if ($search): ?>
                    <a href="<?= APP_URL ?>/admin/admin-students.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="table-container">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="students-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student Name</th>
                    <th>Guardian</th>
                    <th>Grade</th>
                    <th>Section</th>
                    <th>LRN</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No students found.</td></tr>
                <?php else: ?>
                    <?php foreach ($students as $i => $s): ?>
                    <tr>
                        <td><?= e((string)($offset + $i + 1)) ?></td>
                        <td class="fw-bold"><?= e(format_name($s['first_name'], $s['last_name'])) ?></td>
                        <td>
                            <?php if ($s['guardian_name']): ?>
                                <span><?= e($s['guardian_name']) ?></span>
                                <?php if ($s['guardian_contact']): ?>
                                    <br><small class="text-muted"><?= e($s['guardian_contact']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $s['grade_level'] ? 'Grade ' . e($s['grade_level']) : '<span class="text-muted">N/A</span>' ?></td>
                        <td><?= e($s['section_name'] ?? '—') ?></td>
                        <td><small><?= e($s['lrn'] ?? '—') ?></small></td>
                        <td>
                            <?php if (!$isClerk): ?>
                            <a href="?action=edit&id=<?= (int)$s['id'] ?>"
                               class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="?action=delete&id=<?= (int)$s['id'] ?>"
                                  class="d-inline" onsubmit="return confirm('Delete this student?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php else: ?>
                                <span class="text-muted small">View only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= paginationLinks($page, $totalPages, '?search=' . urlencode($search)) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
