<?php
/**
 * Admin Guardians — CRUD, link guardian to student, show login method
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

// ── Delete ──────────────────────────────────────────────
if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $stmt = $pdo->prepare("DELETE FROM guardians WHERE id = :id");
    $stmt->execute([':id' => $id]);
    auditLog('delete_guardian', 'guardians', $id);
    setFlash('success', 'Guardian deleted.');
    redirect(APP_URL . '/admin/admin-guardians.php');
}

// ── Create / Update ─────────────────────────────────────
if (in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $fullName    = trim($_POST['full_name'] ?? '');
    $contact     = trim($_POST['contact'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $relationship = trim($_POST['relationship'] ?? '');
    $userEmail   = trim($_POST['user_email'] ?? '');

    if (empty($fullName)) $errors[] = 'Full name is required.';

    if (empty($errors)) {
        if ($action === 'create') {
            // Create user account for guardian if email provided
            $uId = null;
            if (!empty($userEmail)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
                $stmt->execute([':e' => $userEmail]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $uId = $existing['id'];
                } else {
                    $hash = password_hash('Guardian@1234', PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (:e, :h, 'guardian', 1, NOW())");
                    $stmt->execute([':e' => $userEmail, ':h' => $hash]);
                    $uId = $pdo->lastInsertId();
                }
            }
            if ($uId) {
                $stmt = $pdo->prepare("INSERT INTO guardians (user_id, full_name, contact_number, address, relationship_to_student) VALUES (:uid, :n, :c, :a, :r)");
                $stmt->execute([':uid' => $uId, ':n' => $fullName, ':c' => $contact, ':a' => $address, ':r' => $relationship]);
                auditLog('create_guardian', 'guardians', (int)$pdo->lastInsertId());
                setFlash('success', 'Guardian created. Default password: Guardian@1234');
            } else {
                $errors[] = 'A user email is required to create a guardian account.';
            }
        } else {
            $stmt = $pdo->prepare("UPDATE guardians SET full_name=:n, contact_number=:c, address=:a, relationship_to_student=:r WHERE id=:id");
            $stmt->execute([':n' => $fullName, ':c' => $contact, ':a' => $address, ':r' => $relationship, ':id' => $id]);
            auditLog('update_guardian', 'guardians', $id);
            setFlash('success', 'Guardian updated.');
        }
        if (empty($errors)) redirect(APP_URL . '/admin/admin-guardians.php');
    }
}

// Fetch for edit
$editGuardian = null;
$editUser = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT g.*, u.email, u.password_hash FROM guardians g JOIN users u ON g.user_id = u.id WHERE g.id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editGuardian = $stmt->fetch();
}

// List
$where = ''; $params = [];
if ($search) { $where = "WHERE g.full_name LIKE :s"; $params[':s'] = "%{$search}%"; }

$total = $pdo->prepare("SELECT COUNT(*) FROM guardians g {$where}");
$total->execute($params);
[$offset, $limit, $page, $totalPages] = paginate($total->fetchColumn());

$stmt = $pdo->prepare("
    SELECT g.*, u.email, u.password_hash
    FROM guardians g JOIN users u ON g.user_id = u.id
    {$where} ORDER BY g.full_name LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$guardians = $stmt->fetchAll();

$pageTitle = 'Manage Guardians';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6"><h4 class="fw-bold"><i class="bi bi-people me-2"></i>Guardians</h4></div>
    <div class="col-md-6 text-md-end"><a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Guardian</a></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold"><?= e($action === 'create' ? 'Add Guardian' : 'Edit Guardian') ?></div>
    <div class="card-body">
        <form method="POST" id="guardian-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="full_name" value="<?= e($editGuardian['full_name'] ?? '') ?>" required>
                </div>
                <?php if ($action === 'create'): ?>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" name="user_email" required>
                    <div class="form-text">A user account will be created with default password: Guardian@1234</div>
                </div>
                <?php endif; ?>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="contact" value="<?= e($editGuardian['contact_number'] ?? '') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Relationship</label>
                    <select class="form-select" name="relationship">
                        <option value="">Select...</option>
                        <?php foreach (['Parent','Guardian','Sibling','Other'] as $r): ?>
                            <option value="<?= e($r) ?>" <?= e(($editGuardian['relationship_to_student'] ?? '') === $r ? 'selected' : '') ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" rows="2"><?= e($editGuardian['address'] ?? '') ?></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
            <a href="<?= APP_URL ?>/admin/admin-guardians.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center" id="guardian-search">
        <div class="col-md-8"><input type="text" class="form-control form-control-sm" name="search" placeholder="Search by name..." value="<?= e($search) ?>"></div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <?php if ($search): ?><a href="<?= APP_URL ?>/admin/admin-guardians.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
    </form>
</div></div>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="guardians-table">
        <thead><tr><th>#</th><th>Full Name</th><th>Email</th><th>Contact</th><th>Relationship</th><th>Password Login</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($guardians)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No guardians found.</td></tr>
            <?php else: foreach ($guardians as $i => $g):
                $hasPass = !empty($g['password_hash']);
                $methodClass = $hasPass ? 'bg-secondary' : 'bg-warning text-dark';
                $methodLabel = $hasPass ? 'Enabled' : 'Not Set';
            ?>
            <tr>
                <td><?= e((string)($offset + $i + 1)) ?></td>
                <td class="fw-bold"><?= e($g['full_name']) ?></td>
                <td><?= e($g['email']) ?></td>
                <td><?= e($g['contact_number'] ?? 'N/A') ?></td>
                <td><?= e($g['relationship_to_student'] ?? 'N/A') ?></td>
                <td><span class="badge <?= e($methodClass) ?>"><?= e($methodLabel) ?></span></td>
                <td>
                    <a href="?action=edit&id=<?= (int)$g['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="?action=delete&id=<?= (int)$g['id'] ?>" class="d-inline" onsubmit="return confirm('Delete?')">
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
