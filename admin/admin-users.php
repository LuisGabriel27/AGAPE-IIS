<?php
/**
 * Admin Users — Manage all user accounts: roles, passwords, activation, login method
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$search = trim($_GET['search'] ?? '');
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];
$roleOptions = validUserRoles();
$createForm = [
    'email' => '',
    'role' => 'guardian',
    'profile_first_name' => '',
    'profile_middle_name' => '',
    'profile_last_name' => '',
    'profile_extension_name' => '',
    'profile_employee_number' => '',
    'profile_contact_number' => '',
    'profile_office' => '',
    'profile_position_title' => '',
    'profile_address' => '',
    'profile_employment_status' => '',
    'profile_date_hired' => '',
    'profile_emergency_contact_name' => '',
    'profile_emergency_contact_number' => '',
];

// ── Handle actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'toggle_active' && $id) {
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            setFlash('warning', 'You cannot deactivate your own active admin account.');
            redirect(APP_URL . '/admin/admin-users.php');
        }

        $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = :id");
        $stmt->execute([':id' => $id]);
        auditLog('toggle_user_active', 'users', $id);
        setFlash('success', 'User status updated.');
        redirect(APP_URL . '/admin/admin-users.php');
    }

    if ($postAction === 'reset_password' && $id) {
        $newPass = 'Reset@' . rand(1000, 9999);
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = :h, failed_attempts = 0, lockout_until = NULL WHERE id = :id");
        $stmt->execute([':h' => $hash, ':id' => $id]);
        auditLog('reset_password', 'users', $id);
        setFlash('success', 'Password reset. New password: ' . $newPass);
        redirect(APP_URL . '/admin/admin-users.php');
    }

    if ($postAction === 'delete_user' && $id) {
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            setFlash('warning', 'You cannot delete your own admin account.');
            redirect(APP_URL . '/admin/admin-users.php');
        }

        $adminPassword = $_POST['admin_password'] ?? '';
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)($_SESSION['user_id'] ?? 0)]);
        $adminHash = (string)$stmt->fetchColumn();
        if ($adminHash === '' || !password_verify($adminPassword, $adminHash)) {
            setFlash('danger', 'Admin password confirmation failed. User was not deleted.');
            redirect(APP_URL . '/admin/admin-users.php');
        }

        $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            setFlash('warning', 'User account was not found.');
            redirect(APP_URL . '/admin/admin-users.php');
        }

        auditLog('delete_user', 'users', $id, $targetUser, null);
        $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        setFlash('success', 'User account deleted.');
        redirect(APP_URL . '/admin/admin-users.php');
    }

    if ($postAction === 'change_role' && $id) {
        $newRole = $_POST['new_role'] ?? '';
        if (isValidUserRole($newRole)) {
            syncPrimaryUserRole($pdo, $id, $newRole);
            auditLog('change_role', 'users', $id, null, ['role' => $newRole]);
            setFlash('success', 'Role updated to ' . ucfirst($newRole === 'clerk' ? 'Enrollment Clerk' : $newRole) . '.');
        }
        redirect(APP_URL . '/admin/admin-users.php');
    }

    if ($postAction === 'toggle_secondary_role' && $id) {
        $secRole = $_POST['secondary_role'] ?? '';
        if (isValidUserRole($secRole)) {
            // Check if it already exists
            $stmt = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id = :uid AND role = :r LIMIT 1");
            $stmt->execute([':uid' => $id, ':r' => $secRole]);
            if ($stmt->fetch()) {
                // Remove secondary role (can't remove primary)
                if (removeSecondaryUserRole($pdo, $id, $secRole)) {
                    setFlash('success', 'Secondary role removed.');
                } else {
                    setFlash('warning', 'Cannot remove primary role. Change primary role first.');
                }
            } else {
                ensureUserRole($pdo, $id, $secRole);
                setFlash('success', 'Secondary role added.');
            }
        }
        redirect(APP_URL . '/admin/admin-users.php');
    }

    if ($postAction === 'create_user') {
        $email   = normalizeEmailAddress($_POST['email'] ?? '');
        $role    = trim($_POST['role'] ?? 'guardian');
        $password = trim($_POST['password'] ?? '');
        $extensionInput = trim($_POST['profile_extension_name'] ?? '');
        $profileData = [
            'first_name'               => trim($_POST['profile_first_name'] ?? ''),
            'middle_name'              => trim($_POST['profile_middle_name'] ?? ''),
            'last_name'                => trim($_POST['profile_last_name'] ?? ''),
            'extension_name'           => normalizeNameExtension($extensionInput),
            'employee_number'          => trim($_POST['profile_employee_number'] ?? ''),
            'contact_number'           => normalizePhoneNumber11($_POST['profile_contact_number'] ?? ''),
            'office'                   => trim($_POST['profile_office'] ?? ''),
            'position_title'           => trim($_POST['profile_position_title'] ?? ''),
            'address'                  => trim($_POST['profile_address'] ?? ''),
            'employment_status'        => trim($_POST['profile_employment_status'] ?? ''),
            'date_hired'               => trim($_POST['profile_date_hired'] ?? ''),
            'emergency_contact_name'   => trim($_POST['profile_emergency_contact_name'] ?? ''),
            'emergency_contact_number' => normalizePhoneNumber11($_POST['profile_emergency_contact_number'] ?? ''),
        ];
        $createForm = [
            'email' => $email,
            'role' => $role,
        ];
        foreach ($profileData as $key => $value) {
            $createForm['profile_' . $key] = (string)$value;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!isValidUserRole($role)) $errors[] = 'Valid role is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (!isValidNameExtension($extensionInput)) $errors[] = nameExtensionErrorMessage();
        if (!isValidPhoneNumber11($profileData['contact_number'])) $errors[] = phoneNumberErrorMessage('Contact number');
        if (!isValidPhoneNumber11($profileData['emergency_contact_number'])) $errors[] = phoneNumberErrorMessage('Emergency contact number');

        if (empty($errors)) {
            if (findUserByEmail($pdo, $email)) {
                $errors[] = 'Email already exists.';
            }
        }

        if (empty($errors) && $profileData['employee_number'] !== '') {
            $stmt = $pdo->prepare("SELECT user_id, first_name, last_name FROM user_profiles WHERE LOWER(TRIM(employee_number)) = LOWER(TRIM(:employee_number)) LIMIT 1");
            $stmt->execute([':employee_number' => $profileData['employee_number']]);
            $duplicateProfile = $stmt->fetch();
            if ($duplicateProfile) {
                $errors[] = 'A staff profile with the same employee number already exists: '
                    . format_name($duplicateProfile['first_name'] ?? '', $duplicateProfile['last_name'] ?? '') . '.';
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (:e, :h, :r, 1, NOW()) RETURNING id");
                $stmt->execute([':e' => $email, ':h' => $hash, ':r' => $role]);
                $newId = (int)$stmt->fetchColumn();
                // Seed user_roles with the primary role
                ensureUserRole($pdo, $newId, $role);

                $profileColumns = array_keys($profileData);
                $profilePlaceholders = array_map(static fn(string $col): string => ':' . $col, $profileColumns);
                $profileStmt = $pdo->prepare("
                    INSERT INTO user_profiles (user_id, " . implode(', ', $profileColumns) . ")
                    VALUES (:user_id, " . implode(', ', $profilePlaceholders) . ")
                ");
                $profileParams = [':user_id' => $newId];
                foreach ($profileData as $key => $value) {
                    if (in_array($key, ['first_name', 'middle_name', 'last_name'], true)) {
                        $profileParams[':' . $key] = $value;
                    } else {
                        $profileParams[':' . $key] = nullIfBlank($value);
                    }
                }
                $profileStmt->execute($profileParams);

                if ($role === 'guardian') {
                    $emailName = trim((string)strtok($email, '@'));
                    $fallbackLastName = $emailName !== ''
                        ? ucwords(str_replace(['.', '_', '-'], ' ', $emailName))
                        : 'Guardian';
                    $guardianStmt = $pdo->prepare("
                        INSERT INTO guardians
                            (user_id, first_name, middle_name, last_name, extension_name, contact_number, address,
                             relationship_to_student, occupation, civil_status, nationality, religion,
                             emergency_contact_name, emergency_contact_number, data_privacy_consent, data_privacy_consented_at)
                        VALUES
                            (:user_id, :first_name, :middle_name, :last_name, :extension_name, :contact_number, :address,
                             NULL, NULL, NULL, 'Filipino', NULL, :emergency_contact_name, :emergency_contact_number, FALSE, NULL)
                    ");
                    $guardianStmt->execute([
                        ':user_id' => $newId,
                        ':first_name' => $profileData['first_name'],
                        ':middle_name' => $profileData['middle_name'],
                        ':last_name' => $profileData['last_name'] !== '' ? $profileData['last_name'] : $fallbackLastName,
                        ':extension_name' => nullIfBlank($profileData['extension_name']),
                        ':contact_number' => nullIfBlank($profileData['contact_number']),
                        ':address' => nullIfBlank($profileData['address']),
                        ':emergency_contact_name' => nullIfBlank($profileData['emergency_contact_name']),
                        ':emergency_contact_number' => nullIfBlank($profileData['emergency_contact_number']),
                    ]);
                }

                auditLog('create_user', 'users', $newId);
                $pdo->commit();
                setFlash('success', 'User created.');
                redirect(APP_URL . '/admin/admin-users.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Create user failed: ' . $e->getMessage());
                $errors[] = 'User could not be created. Please review the details and try again.';
            }
        }
    }
}

// ── List with search & pagination ───────────────────────
$where = ''; $params = [];
if ($search) {
    $roleSearchSql = "
        u.role::text ILIKE :role_search
        OR CASE
            WHEN u.role::text = 'clerk' THEN 'Enrollment Clerk'
            ELSE initcap(u.role::text)
        END ILIKE :role_search
        OR EXISTS (
            SELECT 1
            FROM user_roles ur_search
            WHERE ur_search.user_id = u.id
              AND (
                ur_search.role::text ILIKE :role_search
                OR CASE
                    WHEN ur_search.role::text = 'clerk' THEN 'Enrollment Clerk'
                    ELSE initcap(ur_search.role::text)
                END ILIKE :role_search
              )
        )
    ";
    $where = "WHERE u.email ILIKE :s
        OR {$roleSearchSql}
        OR up.last_name ILIKE :s3 OR up.first_name ILIKE :s4
        OR g.last_name ILIKE :s3 OR g.first_name ILIKE :s4
        OR t.last_name ILIKE :s3 OR t.first_name ILIKE :s4
        OR up.employee_number ILIKE :s5 OR t.employee_number ILIKE :s5
        OR up.position_title ILIKE :s6 OR t.position_title ILIKE :s6
        OR up.office ILIKE :s7 OR t.department ILIKE :s7";
    $params[':s'] = "%{$search}%";
    $params[':role_search'] = "%{$search}%";
    $params[':s3'] = "{$search}%";
    $params[':s4'] = "%{$search}%";
    $params[':s5'] = "%{$search}%";
    $params[':s6'] = "%{$search}%";
    $params[':s7'] = "%{$search}%";
}
$userListJoins = "
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN guardians g ON g.user_id = u.id
    LEFT JOIN teachers t ON t.user_id = u.id
";
$total = $pdo->prepare("SELECT COUNT(*) FROM users u {$userListJoins} {$where}"); $total->execute($params);
[$offset, $limit, $page, $totalPages] = paginate($total->fetchColumn());

$stmt = $pdo->prepare("
    SELECT u.*,
           up.first_name AS profile_first_name,
           up.middle_name AS profile_middle_name,
           up.last_name AS profile_last_name,
           up.extension_name AS profile_extension_name,
           up.employee_number AS profile_employee_number,
           up.office AS profile_office,
           up.position_title AS profile_position_title,
           up.contact_number AS profile_contact_number,
           g.first_name AS guardian_first_name,
           g.middle_name AS guardian_middle_name,
           g.last_name AS guardian_last_name,
           g.extension_name AS guardian_extension_name,
           t.first_name AS teacher_first_name,
           t.middle_name AS teacher_middle_name,
           t.last_name AS teacher_last_name,
           t.extension_name AS teacher_extension_name,
           t.employee_number AS teacher_employee_number,
           t.department AS teacher_department,
           t.position_title AS teacher_position_title
    FROM users u
    {$userListJoins}
    {$where}
    ORDER BY u.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Fetch all secondary roles indexed by user_id
$allSecRoles = [];
if (!empty($users)) {
    $uids = array_column($users, 'id');
    $placeholders = implode(',', array_fill(0, count($uids), '?'));
    $secStmt = $pdo->prepare("SELECT user_id, role FROM user_roles WHERE user_id IN ({$placeholders}) ORDER BY user_id, role");
    $secStmt->execute($uids);
    foreach ($secStmt->fetchAll() as $r) {
        $allSecRoles[$r['user_id']][] = $r['role'];
    }
}

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6"><h4 class="fw-bold"><i class="bi bi-person-gear me-2"></i>User Accounts</h4></div>
    <div class="col-md-6 text-md-end"><a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add User</a></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($action === 'create'): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold">Create User Account</div>
    <div class="card-body">
        <form method="POST" id="user-create-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_user">
            <div class="visually-hidden" aria-hidden="true">
                <input type="text" name="username_autofill_trap" autocomplete="username" tabindex="-1">
                <input type="password" name="password_autofill_trap" autocomplete="current-password" tabindex="-1">
            </div>
            <h6 class="fw-semibold text-primary mb-3">Account</h6>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" name="email" value="<?= e($createForm['email']) ?>" autocomplete="new-username" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="password" autocomplete="new-password" required minlength="8">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role">
                        <?php foreach (['guardian', 'clerk', 'teacher', 'admin'] as $r): ?>
                            <option value="<?= e($r) ?>" <?= e($createForm['role'] === $r ? 'selected' : '') ?>><?= e($r === 'clerk' ? 'Enrollment Clerk' : ucfirst($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <h6 class="fw-semibold text-primary mb-3 mt-2">Profile</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control" name="profile_last_name" value="<?= e($createForm['profile_last_name']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="profile_first_name" value="<?= e($createForm['profile_first_name']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control" name="profile_middle_name" value="<?= e($createForm['profile_middle_name']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Extension</label>
                    <select class="form-select" name="profile_extension_name">
                        <?php foreach (nameExtensionOptions() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= e(normalizeNameExtension($createForm['profile_extension_name']) === $value ? 'selected' : '') ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Employee Number</label>
                    <input type="text" class="form-control" name="profile_employee_number" value="<?= e($createForm['profile_employee_number']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Contact Number</label>
                    <input class="form-control" name="profile_contact_number" value="<?= e($createForm['profile_contact_number']) ?>" <?= phoneInputAttributes() ?>>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Office</label>
                    <input type="text" class="form-control" name="profile_office" value="<?= e($createForm['profile_office']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Position Title</label>
                    <input type="text" class="form-control" name="profile_position_title" value="<?= e($createForm['profile_position_title']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Employment Status</label>
                    <input type="text" class="form-control" name="profile_employment_status" value="<?= e($createForm['profile_employment_status']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Date Hired</label>
                    <input type="date" class="form-control" name="profile_date_hired" value="<?= e($createForm['profile_date_hired']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" class="form-control" name="profile_emergency_contact_name" value="<?= e($createForm['profile_emergency_contact_name']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Emergency Contact Number</label>
                    <input class="form-control" name="profile_emergency_contact_number" value="<?= e($createForm['profile_emergency_contact_number']) ?>" <?= phoneInputAttributes() ?>>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="profile_address" rows="2"><?= e($createForm['profile_address']) ?></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Create</button>
            <a href="<?= APP_URL ?>/admin/admin-users.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center" id="user-search">
        <div class="col-md-8"><input type="text" class="form-control form-control-sm" name="search" placeholder="Search by email, role, name, employee number..." value="<?= e($search) ?>"></div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" title="Search users" aria-label="Search users"><i class="bi bi-search"></i></button>
            <?php if ($search): ?><a href="<?= APP_URL ?>/admin/admin-users.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
    </form>
</div></div>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="users-table">
        <thead><tr><th>ID</th><th>Email</th><th>Name</th><th>Employee #</th><th>Role</th><th>Password Login</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($users)): ?>
                <?= emptyStateRow(9, 'No user accounts match the current view.', 'User accounts are created through the Teachers, Guardians, and admin tools. Clear the search box above to see all accounts.', 'bi-people') ?>
            <?php else: foreach ($users as $u):
                $hasPass = !empty($u['password_hash']);
                $methodClass = $hasPass ? 'bg-secondary' : 'bg-warning text-dark';
                $methodLabel = $hasPass ? 'Enabled' : 'Not Set';
                $displayFirst = $u['profile_first_name'] ?: ($u['guardian_first_name'] ?: ($u['teacher_first_name'] ?? ''));
                $displayMiddle = $u['profile_middle_name'] ?: ($u['guardian_middle_name'] ?: ($u['teacher_middle_name'] ?? ''));
                $displayLast = $u['profile_last_name'] ?: ($u['guardian_last_name'] ?: ($u['teacher_last_name'] ?? ''));
                $displayExtension = $u['profile_extension_name'] ?: ($u['guardian_extension_name'] ?: ($u['teacher_extension_name'] ?? ''));
                $displayEmployee = $u['profile_employee_number'] ?: ($u['teacher_employee_number'] ?? '');
                $displayPosition = $u['profile_position_title'] ?: ($u['teacher_position_title'] ?? '');
                $displayOffice = $u['profile_office'] ?: ($u['teacher_department'] ?? '');
                $profileName = trim(format_name($displayFirst, $displayLast) . ' ' . $displayMiddle . ' ' . $displayExtension);
            ?>
            <tr>
                <td><?= e((string)(int)$u['id']) ?></td>
                <td class="fw-bold">
                    <?php if (!empty($u['google_avatar'])): ?>
                        <img src="<?= e($u['google_avatar']) ?>" class="rounded-circle me-1" width="20" height="20">
                    <?php endif; ?>
                    <?= e($u['email']) ?>
                </td>
                <td>
                    <?= e($profileName !== '' ? $profileName : 'N/A') ?>
                    <?php if (!empty($displayPosition) || !empty($displayOffice)): ?>
                        <small class="d-block text-muted"><?= e(trim($displayPosition . ' ' . ($displayOffice ? '(' . $displayOffice . ')' : ''))) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= e($displayEmployee ?: 'N/A') ?></td>
                <td>
                    <form method="POST" action="?id=<?= (int)$u['id'] ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="change_role">
                        <select class="form-select form-select-sm d-inline-block" style="width:auto;" name="new_role" onchange="this.form.submit()">
                            <?php foreach ($roleOptions as $r): ?>
                                <option value="<?= e($r) ?>" <?= e($u['role'] === $r ? 'selected' : '') ?>><?= e($r === 'clerk' ? 'Enrollment Clerk' : ucfirst($r)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php
                    $secRoles = normalizeUserRoles($allSecRoles[$u['id']] ?? [$u['role']]);
                    $extras = array_filter($secRoles, fn($r) => $r !== $u['role']);
                    foreach ($extras as $er):
                    ?>
                        <span class="badge bg-secondary ms-1"><?= e($er === 'clerk' ? 'Clerk' : ucfirst($er)) ?></span>
                    <?php endforeach; ?>
                    <!-- Secondary role toggle -->
                    <div class="dropdown d-inline-block ms-1">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="Manage secondary roles">+</button>
                        <ul class="dropdown-menu dropdown-menu-end" style="min-width:180px;">
                            <li><span class="dropdown-item-text small text-muted">Add/Remove Secondary Role</span></li>
                            <?php foreach ($roleOptions as $r):
                                if ($r === $u['role']) continue;
                                $hasIt = in_array($r, $secRoles, true);
                            ?>
                            <li>
                                <form method="POST" action="?id=<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="toggle_secondary_role">
                                    <input type="hidden" name="secondary_role" value="<?= e($r) ?>">
                                    <button type="submit" class="dropdown-item <?= $hasIt ? 'text-danger' : '' ?>">
                                        <i class="bi bi-<?= $hasIt ? 'dash-circle' : 'plus-circle' ?> me-1"></i>
                                        <?= e($r === 'clerk' ? 'Enrollment Clerk' : ucfirst($r)) ?>
                                    </button>
                                </form>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </td>
                <td><span class="badge <?= e($methodClass) ?>"><?= e($methodLabel) ?></span></td>
                <td>
                    <?php if ($u['is_active']): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Inactive</span>
                    <?php endif; ?>
                </td>
                <td><small><?= e($u['last_login'] ? date('M d, Y g:i A', strtotime($u['last_login'])) : 'Never') ?></small></td>
                <td>
                    <form method="POST" action="?id=<?= (int)$u['id'] ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <button class="btn btn-sm btn-outline-<?= e($u['is_active'] ? 'warning' : 'success') ?>" title="<?= e($u['is_active'] ? 'Deactivate' : 'Activate') ?>">
                            <i class="bi bi-<?= e($u['is_active'] ? 'pause-circle' : 'play-circle') ?>"></i>
                        </button>
                    </form>
                    <form method="POST" action="?id=<?= (int)$u['id'] ?>" class="d-inline" data-confirm="Reset this user's password? They will need the new password to sign in." data-confirm-variant="warning">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <button class="btn btn-sm btn-outline-info" title="Reset Password" aria-label="Reset password"><i class="bi bi-key"></i></button>
                    </form>
                    <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Delete user"
                                aria-label="Delete user"
                                data-user-delete-button
                                data-user-id="<?= (int)$u['id'] ?>"
                                data-user-email="<?= e($u['email']) ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="You cannot delete your own account" disabled>
                            <i class="bi bi-trash"></i>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>

<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="delete-user-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_user">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteUserModalLabel">Delete User Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">This will permanently delete <strong id="delete-user-email">this user</strong>.</p>
                <p class="text-muted small">Linked guardian or teacher profile rows will also be removed by database rules. Student records stay, but guardian links may be cleared.</p>
                <div class="mb-3">
                    <label class="form-label" for="delete-admin-password">Enter your admin password to confirm</label>
                    <input type="password" class="form-control" name="admin_password" id="delete-admin-password" required autocomplete="current-password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete User</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('deleteUserModal');
        const form = document.getElementById('delete-user-form');
        const emailEl = document.getElementById('delete-user-email');
        const password = document.getElementById('delete-admin-password');
        if (!modalEl || !form || !emailEl || !password || typeof bootstrap === 'undefined') return;

        const modal = new bootstrap.Modal(modalEl);
        document.querySelectorAll('[data-user-delete-button]').forEach(function (button) {
            button.addEventListener('click', function () {
                const id = button.getAttribute('data-user-id');
                const email = button.getAttribute('data-user-email') || 'this user';
                form.action = '?id=' + encodeURIComponent(id);
                emailEl.textContent = email;
                password.value = '';
                modal.show();
                setTimeout(function () { password.focus(); }, 180);
            });
        });
    });
})();
</script>

<?= paginationLinks($page, $totalPages, '?search=' . urlencode($search)) ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
