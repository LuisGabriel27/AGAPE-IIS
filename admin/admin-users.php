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

// ── Handle actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'toggle_active' && $id) {
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
        $profileData = [
            'first_name'               => trim($_POST['profile_first_name'] ?? ''),
            'middle_name'              => trim($_POST['profile_middle_name'] ?? ''),
            'last_name'                => trim($_POST['profile_last_name'] ?? ''),
            'extension_name'           => trim($_POST['profile_extension_name'] ?? ''),
            'employee_number'          => trim($_POST['profile_employee_number'] ?? ''),
            'contact_number'           => trim($_POST['profile_contact_number'] ?? ''),
            'office'                   => trim($_POST['profile_office'] ?? ''),
            'position_title'           => trim($_POST['profile_position_title'] ?? ''),
            'address'                  => trim($_POST['profile_address'] ?? ''),
            'employment_status'        => trim($_POST['profile_employment_status'] ?? ''),
            'date_hired'               => trim($_POST['profile_date_hired'] ?? ''),
            'emergency_contact_name'   => trim($_POST['profile_emergency_contact_name'] ?? ''),
            'emergency_contact_number' => trim($_POST['profile_emergency_contact_number'] ?? ''),
        ];

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!isValidUserRole($role)) $errors[] = 'Valid role is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

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
            auditLog('create_user', 'users', $newId);
            setFlash('success', 'User created.');
            redirect(APP_URL . '/admin/admin-users.php');
        }
    }
}

// ── List with search & pagination ───────────────────────
$where = ''; $params = [];
if ($search) {
    $where = "WHERE u.email ILIKE :s OR u.role::text ILIKE :s2 OR up.last_name ILIKE :s3 OR up.first_name ILIKE :s4 OR up.employee_number ILIKE :s5 OR up.position_title ILIKE :s6 OR up.office ILIKE :s7";
    $params[':s'] = "%{$search}%";
    $params[':s2'] = "%{$search}%";
    $params[':s3'] = "{$search}%";
    $params[':s4'] = "%{$search}%";
    $params[':s5'] = "%{$search}%";
    $params[':s6'] = "%{$search}%";
    $params[':s7'] = "%{$search}%";
}
$total = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id {$where}"); $total->execute($params);
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
           up.contact_number AS profile_contact_number
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
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
        <form method="POST" id="user-create-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_user">
            <h6 class="fw-semibold text-primary mb-3">Account</h6>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" name="email" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="password" required minlength="8">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role">
                        <?php foreach (['guardian', 'clerk', 'teacher', 'admin'] as $r): ?>
                            <option value="<?= e($r) ?>"><?= e($r === 'clerk' ? 'Enrollment Clerk' : ucfirst($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <h6 class="fw-semibold text-primary mb-3 mt-2">Profile</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control" name="profile_last_name">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="profile_first_name">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control" name="profile_middle_name">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Extension</label>
                    <input type="text" class="form-control" name="profile_extension_name" placeholder="Jr., III">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Employee Number</label>
                    <input type="text" class="form-control" name="profile_employee_number">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="profile_contact_number">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Office</label>
                    <input type="text" class="form-control" name="profile_office">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Position Title</label>
                    <input type="text" class="form-control" name="profile_position_title">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Employment Status</label>
                    <input type="text" class="form-control" name="profile_employment_status">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Date Hired</label>
                    <input type="date" class="form-control" name="profile_date_hired">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" class="form-control" name="profile_emergency_contact_name">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Emergency Contact Number</label>
                    <input type="text" class="form-control" name="profile_emergency_contact_number">
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="profile_address" rows="2"></textarea>
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
                $profileName = trim(format_name($u['profile_first_name'] ?? '', $u['profile_last_name'] ?? '') . ' ' . ($u['profile_middle_name'] ?? '') . ' ' . ($u['profile_extension_name'] ?? ''));
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
                    <?php if (!empty($u['profile_position_title']) || !empty($u['profile_office'])): ?>
                        <small class="d-block text-muted"><?= e(trim(($u['profile_position_title'] ?? '') . ' ' . ($u['profile_office'] ? '(' . $u['profile_office'] . ')' : ''))) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= e($u['profile_employee_number'] ?: 'N/A') ?></td>
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
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?search=' . urlencode($search)) ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
