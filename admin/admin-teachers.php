<?php
/**
 * Admin Teachers — CRUD + subject assignments
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$search = trim($_GET['search'] ?? '');
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];

if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $pdo->prepare("DELETE FROM teachers WHERE id = :id")->execute([':id' => $id]);
    auditLog('delete_teacher', 'teachers', $id);
    setFlash('success', 'Teacher deleted.');
    redirect(APP_URL . '/admin/admin-teachers.php');
}

if (in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $genderOptions = ['male', 'female', 'other'];
    $civilStatusOptions = ['single', 'married', 'widowed', 'separated', 'others'];
    $teacherData = [
        'first_name'               => trim($_POST['first_name'] ?? ''),
        'middle_name'              => trim($_POST['middle_name'] ?? ''),
        'last_name'                => trim($_POST['last_name'] ?? ''),
        'extension_name'           => trim($_POST['extension_name'] ?? ''),
        'employee_number'          => trim($_POST['employee_number'] ?? ''),
        'gender'                   => trim($_POST['gender'] ?? ''),
        'birthdate'                => trim($_POST['birthdate'] ?? ''),
        'civil_status'             => trim($_POST['civil_status'] ?? ''),
        'contact_number'           => trim($_POST['contact'] ?? ''),
        'address'                  => trim($_POST['address'] ?? ''),
        'department'               => trim($_POST['department'] ?? ''),
        'position_title'           => trim($_POST['position_title'] ?? ''),
        'employment_status'        => trim($_POST['employment_status'] ?? ''),
        'date_hired'               => trim($_POST['date_hired'] ?? ''),
        'prc_license_no'           => trim($_POST['prc_license_no'] ?? ''),
        'prc_license_expiry'       => trim($_POST['prc_license_expiry'] ?? ''),
        'specialization'           => trim($_POST['specialization'] ?? ''),
        'emergency_contact_name'   => trim($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_number' => trim($_POST['emergency_contact_number'] ?? ''),
    ];
    $userEmail = normalizeEmailAddress($_POST['user_email'] ?? '');

    if (empty($teacherData['last_name'])) $errors[] = 'Last name is required.';
    if ($teacherData['gender'] !== '' && !in_array($teacherData['gender'], $genderOptions, true)) $errors[] = 'Invalid gender value.';
    if ($teacherData['civil_status'] !== '' && !in_array($teacherData['civil_status'], $civilStatusOptions, true)) $errors[] = 'Invalid civil status value.';

    if (empty($errors)) {
        $duplicateTeacher = findDuplicateTeacherProfile(
            $pdo,
            $teacherData['first_name'],
            $teacherData['last_name'],
            $teacherData['contact_number'],
            $action === 'create' ? $userEmail : '',
            $action === 'edit' ? $id : 0
        );
        if ($duplicateTeacher) {
            $errors[] = 'A teacher profile with the same name and contact/email already exists: '
                . format_name($duplicateTeacher['first_name'] ?? '', $duplicateTeacher['last_name'] ?? '')
                . ' (' . ($duplicateTeacher['email'] ?? 'no email') . ').';
        }
    }

    foreach (['employee_number' => 'employee number', 'prc_license_no' => 'PRC license number'] as $field => $label) {
        if (empty($errors) && $teacherData[$field] !== '') {
            $duplicateSql = "SELECT id, first_name, last_name FROM teachers WHERE LOWER(TRIM({$field})) = LOWER(TRIM(:value))";
            $params = [':value' => $teacherData[$field]];
            if ($action === 'edit') {
                $duplicateSql .= " AND id <> :id";
                $params[':id'] = $id;
            }
            $duplicateSql .= " LIMIT 1";
            $stmt = $pdo->prepare($duplicateSql);
            $stmt->execute($params);
            $duplicate = $stmt->fetch();
            if ($duplicate) {
                $errors[] = 'A teacher with the same ' . $label . ' already exists: '
                    . format_name($duplicate['first_name'] ?? '', $duplicate['last_name'] ?? '') . '.';
            }
        }
    }

    $teacherDbValue = static function (string $key, mixed $value): mixed {
        $value = trim((string)$value);
        if (in_array($key, ['first_name', 'middle_name', 'last_name'], true)) {
            return $value;
        }
        return $value === '' ? null : $value;
    };

    if (empty($errors)) {
        if ($action === 'create') {
            if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid email is required.'; }
            else {
                $existing = findUserByEmail($pdo, $userEmail);
                if ($existing) {
                    $uId = (int)$existing['id'];
                    $existingTeacher = findTeacherProfileByUserId($pdo, $uId);
                    if ($existingTeacher) {
                        $errors[] = 'This email already belongs to an existing teacher profile: '
                            . format_name($existingTeacher['first_name'] ?? '', $existingTeacher['last_name'] ?? '') . '.';
                    }
                }

                if (empty($errors) && !empty($uId)) {
                    syncPrimaryUserRole($pdo, (int)$uId, 'teacher');
                } elseif (empty($errors)) {
                    $hash = password_hash('Teacher@1234', PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (:e, :h, 'teacher', 1, NOW()) RETURNING id");
                    $stmt->execute([':e' => $userEmail, ':h' => $hash]);
                    $uId = $stmt->fetchColumn();
                    ensureUserRole($pdo, (int)$uId, 'teacher');
                }

                if (empty($errors)) {
                    $insertData = ['user_id' => (int)$uId] + $teacherData;
                    $columns = array_keys($insertData);
                    $placeholders = array_map(static fn(string $col): string => ':' . $col, $columns);
                    $stmt = $pdo->prepare("
                        INSERT INTO teachers (" . implode(', ', $columns) . ")
                        VALUES (" . implode(', ', $placeholders) . ")
                        RETURNING id
                    ");
                    $params = [];
                    foreach ($insertData as $key => $value) {
                        $params[':' . $key] = $key === 'user_id' ? $value : $teacherDbValue($key, $value);
                    }
                    $stmt->execute($params);
                    auditLog('create_teacher', 'teachers', (int)$stmt->fetchColumn());
                    setFlash('success', 'Teacher created. Default password: Teacher@1234');
                    redirect(APP_URL . '/admin/admin-teachers.php');
                }
            }
        } else {
            $setParts = [];
            $params = [':id' => $id];
            foreach ($teacherData as $key => $value) {
                $param = ':' . $key;
                $setParts[] = "{$key} = {$param}";
                $params[$param] = $teacherDbValue($key, $value);
            }
            $pdo->prepare("UPDATE teachers SET " . implode(', ', $setParts) . " WHERE id = :id")
                ->execute($params);
            auditLog('update_teacher', 'teachers', $id);
            setFlash('success', 'Teacher updated.');
            redirect(APP_URL . '/admin/admin-teachers.php');
        }
    }
}

$editTeacher = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT t.*, u.email FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editTeacher = $stmt->fetch();
}

$where = ''; $params = [];
if ($search) {
    $where = "WHERE t.last_name ILIKE :s OR t.first_name ILIKE :s2 OR t.middle_name ILIKE :s3 OR t.department ILIKE :s4 OR t.employee_number ILIKE :s5 OR t.position_title ILIKE :s6 OR t.prc_license_no ILIKE :s7 OR t.specialization ILIKE :s8";
    $params[':s'] = "{$search}%";
    $params[':s2'] = "%{$search}%";
    $params[':s3'] = "%{$search}%";
    $params[':s4'] = "%{$search}%";
    $params[':s5'] = "%{$search}%";
    $params[':s6'] = "%{$search}%";
    $params[':s7'] = "%{$search}%";
    $params[':s8'] = "%{$search}%";
}
$total = $pdo->prepare("SELECT COUNT(*) FROM teachers t {$where}"); $total->execute($params);
[$offset, $limit, $page, $totalPages] = paginate($total->fetchColumn());

$stmt = $pdo->prepare("SELECT t.*, u.email FROM teachers t JOIN users u ON t.user_id = u.id {$where} ORDER BY t.last_name, t.first_name LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$teachers = $stmt->fetchAll();

$pageTitle = 'Manage Teachers';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6"><h4 class="fw-bold"><i class="bi bi-person-workspace me-2"></i>Teachers</h4></div>
    <div class="col-md-6 text-md-end"><a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Teacher</a></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold"><?= e($action === 'create' ? 'Add Teacher' : 'Edit Teacher') ?></div>
    <div class="card-body">
        <form method="POST" id="teacher-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <h6 class="fw-semibold text-primary mb-3">Identity</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="last_name" value="<?= e($editTeacher['last_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="first_name" value="<?= e($editTeacher['first_name'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control" name="middle_name" value="<?= e($editTeacher['middle_name'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Extension</label>
                    <input type="text" class="form-control" name="extension_name" value="<?= e($editTeacher['extension_name'] ?? '') ?>" placeholder="Jr., III">
                </div>
                <?php if ($action === 'create'): ?>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" name="user_email" required>
                    <div class="form-text">Default password: Teacher@1234</div>
                </div>
                <?php else: ?>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" value="<?= e($editTeacher['email'] ?? '') ?>" disabled>
                </div>
                <?php endif; ?>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="contact" value="<?= e($editTeacher['contact_number'] ?? '') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="gender">
                        <option value="">Select...</option>
                        <?php foreach (['male','female','other'] as $g): ?>
                            <option value="<?= e($g) ?>" <?= e(($editTeacher['gender'] ?? '') === $g ? 'selected' : '') ?>><?= e(ucfirst($g)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Birthdate</label>
                    <input type="date" class="form-control" name="birthdate" value="<?= e($editTeacher['birthdate'] ?? '') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Civil Status</label>
                    <select class="form-select" name="civil_status">
                        <option value="">Select...</option>
                        <?php foreach (['single','married','widowed','separated','others'] as $cs): ?>
                            <option value="<?= e($cs) ?>" <?= e(($editTeacher['civil_status'] ?? '') === $cs ? 'selected' : '') ?>><?= e(ucfirst($cs)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 mb-3">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" rows="2"><?= e($editTeacher['address'] ?? '') ?></textarea>
                </div>
            </div>

            <h6 class="fw-semibold text-primary mb-3 mt-2">Employment</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Employee Number</label>
                    <input type="text" class="form-control" name="employee_number" value="<?= e($editTeacher['employee_number'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control" name="department" value="<?= e($editTeacher['department'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Position Title</label>
                    <input type="text" class="form-control" name="position_title" value="<?= e($editTeacher['position_title'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Employment Status</label>
                    <input type="text" class="form-control" name="employment_status" value="<?= e($editTeacher['employment_status'] ?? '') ?>" placeholder="Permanent, Contractual">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Date Hired</label>
                    <input type="date" class="form-control" name="date_hired" value="<?= e($editTeacher['date_hired'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">PRC License No.</label>
                    <input type="text" class="form-control" name="prc_license_no" value="<?= e($editTeacher['prc_license_no'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">PRC Expiry</label>
                    <input type="date" class="form-control" name="prc_license_expiry" value="<?= e($editTeacher['prc_license_expiry'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Specialization</label>
                    <input type="text" class="form-control" name="specialization" value="<?= e($editTeacher['specialization'] ?? '') ?>">
                </div>
            </div>

            <h6 class="fw-semibold text-primary mb-3 mt-2">Emergency Contact</h6>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" class="form-control" name="emergency_contact_name" value="<?= e($editTeacher['emergency_contact_name'] ?? '') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Emergency Contact Number</label>
                    <input type="text" class="form-control" name="emergency_contact_number" value="<?= e($editTeacher['emergency_contact_number'] ?? '') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
            <a href="<?= APP_URL ?>/admin/admin-teachers.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center" id="teacher-search">
        <div class="col-md-8"><input type="text" class="form-control form-control-sm" name="search" placeholder="Search by name, employee number, PRC, department..." value="<?= e($search) ?>"></div>
        <div class="col-md-4 d-flex gap-2"><button type="submit" class="btn btn-sm btn-primary" title="Search teachers" aria-label="Search teachers"><i class="bi bi-search"></i></button><?php if ($search): ?><a href="<?= APP_URL ?>/admin/admin-teachers.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?></div>
    </form>
</div></div>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="teachers-table">
        <thead><tr><th>#</th><th>Full Name</th><th>Employee #</th><th>Email</th><th>Contact</th><th>Department / Position</th><th>PRC License</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($teachers)): ?>
                <?= emptyStateRow(8, 'No teachers match the current view.', 'Add a teacher with the "Add Teacher" button, or clear the search box above to see everyone.', 'bi-person-badge') ?>
            <?php else: foreach ($teachers as $i => $t): ?>
            <tr>
                <td><?= e((string)($offset + $i + 1)) ?></td>
                <td class="fw-bold"><?= e(trim(format_name($t['first_name'], $t['last_name']) . ' ' . ($t['middle_name'] ?? '') . ' ' . ($t['extension_name'] ?? ''))) ?></td>
                <td><?= e($t['employee_number'] ?? 'N/A') ?></td>
                <td><?= e($t['email']) ?></td>
                <td><?= e($t['contact_number'] ?? 'N/A') ?></td>
                <td>
                    <?= e($t['department'] ?? 'N/A') ?>
                    <?php if (!empty($t['position_title'])): ?>
                        <small class="d-block text-muted"><?= e($t['position_title']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= e($t['prc_license_no'] ?? 'N/A') ?></td>
                <td>
                    <a href="?action=edit&id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit teacher" aria-label="Edit teacher"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="?action=delete&id=<?= (int)$t['id'] ?>" class="d-inline" data-confirm="Delete this teacher? This cannot be undone." data-confirm-variant="danger">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Delete teacher" aria-label="Delete teacher"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?search=' . urlencode($search)) ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
