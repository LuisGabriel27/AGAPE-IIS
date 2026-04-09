<?php
/**
 * Admin Users — Manage all user accounts: roles, passwords, activation, login method
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
        if (in_array($newRole, ['admin', 'teacher', 'guardian'])) {
            $stmt = $pdo->prepare("UPDATE users SET role = :r WHERE id = :id");
            $stmt->execute([':r' => $newRole, ':id' => $id]);
            auditLog('change_role', 'users', $id, null, ['role' => $newRole]);
            setFlash('success', 'Role updated to ' . ucfirst($newRole) . '.');
        }
        redirect(APP_URL . '/admin/admin-users.php');
    }

    if ($postAction === 'create_user') {
        $email   = trim($_POST['email'] ?? '');
        $role    = trim($_POST['role'] ?? 'guardian');
        $password = trim($_POST['password'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
            $stmt->execute([':e' => $email]);
            if ($stmt->fetch()) {
                $errors[] = 'Email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (:e, :h, :r, 1, NOW())");
                $stmt->execute([':e' => $email, ':h' => $hash, ':r' => $role]);
                auditLog('create_user', 'users', (int)$pdo->lastInsertId());
                setFlash('success', 'User created.');
                redirect(APP_URL . '/admin/admin-users.php');
            }
        }
    }
}

// ── List with search & pagination ───────────────────────
$where = ''; $params = [];
if ($search) { $where = "WHERE email LIKE :s OR role LIKE :s2"; $params[':s'] = $params[':s2'] = "%{$search}%"; }
$total = $pdo->prepare("SELECT COUNT(*) FROM users {$where}"); $total->execute($params);
[$offset, $limit, $page, $totalPages] = paginate($total->fetchColumn());

$stmt = $pdo->prepare("SELECT * FROM users {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$users = $stmt->fetchAll();

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
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_user">
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
                        <option value="guardian">Guardian</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                    </select>
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
        <div class="col-md-8"><input type="text" class="form-control form-control-sm" name="search" placeholder="Search by email or role..." value="<?= e($search) ?>"></div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <?php if ($search): ?><a href="<?= APP_URL ?>/admin/admin-users.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
    </form>
</div></div>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="users-table">
        <thead><tr><th>ID</th><th>Email</th><th>Role</th><th>Login Method</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No users found.</td></tr>
            <?php else: foreach ($users as $u):
                $hasPass = !empty($u['password_hash']);
                $hasGoog = !empty($u['google_id']);
                if ($hasPass && $hasGoog) $method = '<span class="badge bg-success">Both</span>';
                elseif ($hasGoog) $method = '<span class="badge bg-info">Google</span>';
                else $method = '<span class="badge bg-secondary">Email</span>';
            ?>
            <tr>
                <td><?= $u['id'] ?></td>
                <td class="fw-bold">
                    <?php if (!empty($u['google_avatar'])): ?>
                        <img src="<?= e($u['google_avatar']) ?>" class="rounded-circle me-1" width="20" height="20">
                    <?php endif; ?>
                    <?= e($u['email']) ?>
                </td>
                <td>
                    <form method="POST" action="?id=<?= $u['id'] ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="change_role">
                        <select class="form-select form-select-sm d-inline-block" style="width:auto;" name="new_role" onchange="this.form.submit()">
                            <?php foreach (['admin','teacher','guardian'] as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td><?= $method ?></td>
                <td>
                    <?php if ($u['is_active']): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Inactive</span>
                    <?php endif; ?>
                </td>
                <td><small><?= $u['last_login'] ? e(date('M d, Y g:i A', strtotime($u['last_login']))) : 'Never' ?></small></td>
                <td>
                    <form method="POST" action="?id=<?= $u['id'] ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <button class="btn btn-sm btn-outline-<?= $u['is_active'] ? 'warning' : 'success' ?>" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                            <i class="bi bi-<?= $u['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                        </button>
                    </form>
                    <form method="POST" action="?id=<?= $u['id'] ?>" class="d-inline" onsubmit="return confirm('Reset password?')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <button class="btn btn-sm btn-outline-info" title="Reset Password"><i class="bi bi-key"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?search=' . urlencode($search)) ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
