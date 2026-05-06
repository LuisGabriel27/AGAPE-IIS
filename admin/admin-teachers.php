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
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $contact    = trim($_POST['contact'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $userEmail  = trim($_POST['user_email'] ?? '');

    if (empty($lastName)) $errors[] = 'Last name is required.';

    if (empty($errors)) {
        if ($action === 'create') {
            if (empty($userEmail)) { $errors[] = 'Email is required.'; }
            else {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
                $stmt->execute([':e' => $userEmail]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $uId = $existing['id'];
                    $pdo->prepare("UPDATE users SET role = 'teacher' WHERE id = :id")->execute([':id' => $uId]);
                } else {
                    $hash = password_hash('Teacher@1234', PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (:e, :h, 'teacher', 1, NOW()) RETURNING id");
                    $stmt->execute([':e' => $userEmail, ':h' => $hash]);
                    $uId = $stmt->fetchColumn();
                }
                $stmt = $pdo->prepare("INSERT INTO teachers (user_id, first_name, last_name, contact_number, department) VALUES (:uid, :fn, :ln, :c, :d) RETURNING id");
                $stmt->execute([':uid' => $uId, ':fn' => $firstName, ':ln' => $lastName, ':c' => $contact, ':d' => $department]);
                auditLog('create_teacher', 'teachers', (int)$stmt->fetchColumn());
                setFlash('success', 'Teacher created. Default password: Teacher@1234');
                redirect(APP_URL . '/admin/admin-teachers.php');
            }
        } else {
            $pdo->prepare("UPDATE teachers SET first_name=:fn, last_name=:ln, contact_number=:c, department=:d WHERE id=:id")
                ->execute([':fn' => $firstName, ':ln' => $lastName, ':c' => $contact, ':d' => $department, ':id' => $id]);
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
if ($search) { $where = "WHERE t.last_name ILIKE :s OR t.first_name ILIKE :s2 OR t.department ILIKE :s3"; $params[':s'] = "{$search}%"; $params[':s2'] = "%{$search}%"; $params[':s3'] = "%{$search}%"; }
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
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="last_name" value="<?= e($editTeacher['last_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="first_name" value="<?= e($editTeacher['first_name'] ?? '') ?>">
                </div>
                <?php if ($action === 'create'): ?>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" name="user_email" required>
                    <div class="form-text">Default password: Teacher@1234</div>
                </div>
                <?php else: ?>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" value="<?= e($editTeacher['email'] ?? '') ?>" disabled>
                </div>
                <?php endif; ?>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="contact" value="<?= e($editTeacher['contact_number'] ?? '') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control" name="department" value="<?= e($editTeacher['department'] ?? '') ?>">
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
        <div class="col-md-8"><input type="text" class="form-control form-control-sm" name="search" placeholder="Search by name or department..." value="<?= e($search) ?>"></div>
        <div class="col-md-4 d-flex gap-2"><button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button><?php if ($search): ?><a href="<?= APP_URL ?>/admin/admin-teachers.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?></div>
    </form>
</div></div>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="teachers-table">
        <thead><tr><th>#</th><th>Full Name</th><th>Email</th><th>Contact</th><th>Department</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($teachers)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No teachers found.</td></tr>
            <?php else: foreach ($teachers as $i => $t): ?>
            <tr>
                <td><?= e((string)($offset + $i + 1)) ?></td>
                <td class="fw-bold"><?= e(format_name($t['first_name'], $t['last_name'])) ?></td>
                <td><?= e($t['email']) ?></td>
                <td><?= e($t['contact_number'] ?? 'N/A') ?></td>
                <td><?= e($t['department'] ?? 'N/A') ?></td>
                <td>
                    <a href="?action=edit&id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="?action=delete&id=<?= (int)$t['id'] ?>" class="d-inline" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>
<?= paginationLinks($page, $totalPages, '?search=' . urlencode($search)) ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
