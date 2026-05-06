<?php
/**
 * Guardian Profile View — Read-only display of personal and student info.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

// Get user record
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$user = $stmt->fetch();

// Get guardian profile
$stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

// Get students
$students = [];
if ($guardian) {
    $stmt = $pdo->prepare("
        SELECT s.*, sec.name AS section_name 
        FROM students s LEFT JOIN sections sec ON s.section_id = sec.id 
        WHERE s.guardian_id = :gid
    ");
    $stmt->execute([':gid' => $guardian['id']]);
    $students = $stmt->fetchAll();
}

$hasPassword = !empty($user['password_hash']);

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h4 class="fw-bold mb-0"><i class="bi bi-person me-2"></i>My Profile</h4>
        <a href="<?= APP_URL ?>/guardian/profile-edit.php" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit Profile</a>
    </div>
</div>

<div class="row g-4">
    <!-- Account Information -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white"><i class="bi bi-shield-lock me-2"></i>Account Information</div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <?php if (!empty($user['google_avatar'])): ?>
                        <img src="<?= e($user['google_avatar']) ?>" class="rounded-circle mb-2" width="80" height="80" alt="Avatar">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width:80px;height:80px;font-size:2rem;">
                            <?= strtoupper(substr($guardian['last_name'] ?? 'G', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <table class="table table-sm">
                    <tr><th width="40%">Email</th><td><?= e($user['email']) ?></td></tr>
                    <tr><th>Role</th><td><span class="badge bg-primary"><?= e(ucfirst($user['role'])) ?></span></td></tr>
                    <tr><th>Member Since</th><td><?= e(date('M d, Y', strtotime($user['created_at']))) ?></td></tr>
                    <tr><th>Last Login</th><td><?= $user['last_login'] ? e(date('M d, Y g:i A', strtotime($user['last_login']))) : 'Never' ?></td></tr>
                    <tr>
                        <th>Password Login</th>
                        <td>
                            <?php if ($hasPassword): ?>
                                <span class="badge bg-secondary">Enabled</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Not Set</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Personal Information -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white"><i class="bi bi-person-vcard me-2"></i>Personal Information</div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><th width="40%">Full Name</th><td><?= e(format_name($guardian['first_name'] ?? '', $guardian['last_name'] ?? '')) ?></td></tr>
                    <tr><th>Contact Number</th><td><?= e($guardian['contact_number'] ?? 'N/A') ?></td></tr>
                    <tr><th>Address</th><td><?= e($guardian['address'] ?? 'N/A') ?></td></tr>
                    <tr><th>Relationship</th><td><?= e($guardian['relationship_to_student'] ?? 'N/A') ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Student Information -->
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white"><i class="bi bi-mortarboard me-2"></i>Student Information</div>
            <div class="card-body">
                <?php if (empty($students)): ?>
                    <p class="text-muted">No students linked to your account.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Full Name</th><th>Grade Level</th><th>Section</th><th>LRN</th><th>Birthdate</th><th>Gender</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $stu): ?>
                            <tr>
                                <td><?= e(format_name($stu['first_name'], $stu['last_name'])) ?></td>
                                <td>Grade <?= e($stu['grade_level'] ?? 'N/A') ?></td>
                                <td><?= e($stu['section_name'] ?? 'N/A') ?></td>
                                <td><?= e($stu['lrn'] ?? 'N/A') ?></td>
                                <td><?= $stu['birthdate'] ? e(date('M d, Y', strtotime($stu['birthdate']))) : 'N/A' ?></td>
                                <td><?= e(ucfirst($stu['gender'] ?? 'N/A')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
