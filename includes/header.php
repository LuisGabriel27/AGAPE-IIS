<?php
/**
 * Shared Header Template — Sidebar Navigation + Top Header
 * Role-aware sidebar navigation with active state highlighting.
 *
 * Usage: $pageTitle = 'Dashboard'; require_once __DIR__ . '/../includes/header.php';
 */

require_once __DIR__ . '/session-check.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';

$displayTitle = isset($pageTitle) ? (string)$pageTitle : APP_NAME;
$fullTitle    = isset($pageTitle) ? (string)$pageTitle . ' - ' . APP_NAME : APP_NAME;
$currentPage  = basename($_SERVER['PHP_SELF']);
$currentPath  = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
$userRole     = $_SESSION['role'] ?? '';
$userEmail    = $_SESSION['user_email'] ?? 'Account';
$userAvatar   = $_SESSION['google_avatar'] ?? '';
$userInitial  = strtoupper(substr($userEmail, 0, 1));
$isGuardianEnrollmentPage = str_contains($currentPath, '/guardian/enrollment/')
    && !str_contains($currentPath, '/guardian/enrollment/certificate.php');
$isGuardianCertificatePage = str_contains($currentPath, '/guardian/enrollment/certificate.php')
    || $currentPage === 'certificate-enrollment.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Academy Information System — Manage students, grades, enrollments, and schedules.">
    <title><?= e($fullTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/branding/agape-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
    <?php if (($userRole) === 'guardian'): ?>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/guardian.css">
    <?php endif; ?>
</head>
<body>

<?php if (isLoggedIn()): ?>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <a href="<?= getRoleDashboardUrl() ?>" class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <img src="<?= APP_URL ?>/assets/images/branding/agape-logo.png" alt="Agape Logo" class="sidebar-brand-logo">
        </div>
        <div class="sidebar-brand-text">
            AGAPE
            <small>Academy Portal</small>
        </div>
    </a>

    <nav class="sidebar-nav">
        <?php if ($userRole === 'admin' || $userRole === 'clerk'): ?>

            <?php if ($userRole === 'admin'): ?>

            <div class="sidebar-section">Main</div>
            <a class="sidebar-link <?= e($currentPage === 'admin-dashboard.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-dashboard.php">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>

            <div class="sidebar-section">People</div>
            <a class="sidebar-link <?= e($currentPage === 'admin-students.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-students.php">
                <i class="bi bi-people-fill"></i> Students
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-teachers.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-teachers.php">
                <i class="bi bi-person-workspace"></i> Teachers
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-users.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-users.php">
                <i class="bi bi-person-gear"></i> User Accounts
            </a>

            <div class="sidebar-section">Academics</div>
            <a class="sidebar-link <?= e($currentPage === 'admin-enrollments.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-enrollments.php">
                <i class="bi bi-pencil-square"></i> Enrollments
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-subjects.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-subjects.php">
                <i class="bi bi-book-fill"></i> Subjects
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-sections.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-sections.php">
                <i class="bi bi-diagram-3-fill"></i> Sections
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-grades.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-grades.php">
                <i class="bi bi-card-checklist"></i> Grades
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-schedule.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-schedule.php">
                <i class="bi bi-calendar2-week-fill"></i> Schedules
            </a>
            <a class="sidebar-link <?= e(in_array($currentPage, ['admin-calendar.php', 'admin-calendar-import.php']) ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-calendar.php">
                <i class="bi bi-calendar-event-fill"></i> Calendar
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-attendance.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-attendance.php">
                <i class="bi bi-camera-video-fill"></i> Attendance
            </a>

            <div class="sidebar-section">Finance</div>
            <a class="sidebar-link <?= e($currentPage === 'admin-payments.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-payments.php">
                <i class="bi bi-cash-stack"></i> Payment Verification
            </a>

            <div class="sidebar-section">Settings</div>
            <a class="sidebar-link <?= e($currentPage === 'staff-profile.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/staff-profile.php">
                <i class="bi bi-person-circle"></i> My Profile
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-schoolyear.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-schoolyear.php">
                <i class="bi bi-calendar-range"></i> School Year
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-sync.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-sync.php">
                <i class="bi bi-cloud-arrow-up-fill"></i> Manual Sync
            </a>

            <?php else: /* Clerk — enrollment module only */ ?>

            <div class="sidebar-section">Main</div>
            <a class="sidebar-link <?= e($currentPage === 'clerk-dashboard.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/clerk-dashboard.php">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-enrollments.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-enrollments.php">
                <i class="bi bi-pencil-square"></i> Enrollment Queue
            </a>

            <div class="sidebar-section">Records</div>
            <a class="sidebar-link <?= e($currentPage === 'admin-students.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-students.php">
                <i class="bi bi-people-fill"></i> Students
            </a>
            <a class="sidebar-link <?= e($currentPage === 'admin-payments.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-payments.php">
                <i class="bi bi-cash-stack"></i> Payment Verification
            </a>

            <div class="sidebar-section">Sync</div>
            <a class="sidebar-link <?= e($currentPage === 'admin-sync.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/admin-sync.php">
                <i class="bi bi-cloud-arrow-up-fill"></i> Manual Sync
            </a>

            <div class="sidebar-section">Account</div>
            <a class="sidebar-link <?= e($currentPage === 'staff-profile.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/admin/staff-profile.php">
                <i class="bi bi-person-circle"></i> My Profile
            </a>

            <?php endif; ?>

        <?php elseif ($userRole === 'teacher'): ?>


            <div class="sidebar-section">Main</div>
            <a class="sidebar-link <?= e($currentPage === 'teacher-dashboard.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/teacher/teacher-dashboard.php">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>

            <div class="sidebar-section">Teaching</div>
            <a class="sidebar-link <?= e($currentPage === 'teacher-students.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/teacher/teacher-students.php">
                <i class="bi bi-people-fill"></i> My Students
            </a>
            <a class="sidebar-link <?= e($currentPage === 'teacher-grades.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/teacher/teacher-grades.php">
                <i class="bi bi-pencil-square"></i> Grades
            </a>
            <a class="sidebar-link <?= e($currentPage === 'teacher-schedule.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/teacher/teacher-schedule.php">
                <i class="bi bi-calendar2-week-fill"></i> Schedule
            </a>
            <a class="sidebar-link <?= e($currentPage === 'teacher-attendance.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/teacher/teacher-attendance.php">
                <i class="bi bi-clipboard-check"></i> Attendance
            </a>
            <a class="sidebar-link <?= e($currentPage === 'teacher-attendance-report.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/teacher/teacher-attendance-report.php">
                <i class="bi bi-file-earmark-bar-graph"></i> Attendance Report
            </a>
            <a class="sidebar-link <?= e($currentPage === 'teacher-calendar.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/teacher/teacher-calendar.php">
                <i class="bi bi-calendar-event-fill"></i> Calendar
            </a>

        <?php elseif ($userRole === 'guardian'): ?>

            <div class="sidebar-section">Main</div>
            <a class="sidebar-link <?= e($currentPage === 'dashboard.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/guardian/dashboard.php">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>

            <div class="sidebar-section">Academic</div>
            <a class="sidebar-link <?= e($isGuardianEnrollmentPage || $currentPage === 'enrollment.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/guardian/enrollment/">
                <i class="bi bi-pencil-square"></i> Enrollment
            </a>
            <a class="sidebar-link <?= e($currentPage === 'grades.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/guardian/grades.php">
                <i class="bi bi-card-checklist"></i> Grades
            </a>
            <a class="sidebar-link <?= e($currentPage === 'report-card.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/guardian/report-card.php">
                <i class="bi bi-printer-fill"></i> Report Card
            </a>
            <a class="sidebar-link <?= e($isGuardianCertificatePage ? 'active' : '') ?>"
               href="<?= APP_URL ?>/guardian/enrollment/certificate.php">
                <i class="bi bi-patch-check-fill"></i> Certificate
            </a>
            <a class="sidebar-link <?= e($currentPage === 'schedule.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/guardian/schedule.php">
                <i class="bi bi-calendar2-week-fill"></i> Schedule
            </a>

            <div class="sidebar-section">Finance</div>
            <a class="sidebar-link <?= e($currentPage === 'payments.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/guardian/payments.php">
                <i class="bi bi-credit-card-fill"></i> Payments
            </a>

            <div class="sidebar-section">Account</div>
            <a class="sidebar-link <?= e($currentPage === 'profile-view.php' ? 'active' : '') ?>"
               href="<?= APP_URL ?>/guardian/profile-view.php">
                <i class="bi bi-person-circle"></i> My Profile
            </a>

        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <form method="POST" action="<?= APP_URL ?>/auth/logout.php" class="m-0">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button type="submit" class="sidebar-link text-danger w-100" style="color: var(--danger) !important; border: 0; background: transparent; text-align: left;">
                <i class="bi bi-box-arrow-left"></i> Sign Out
            </button>
        </form>
    </div>
</aside>

<!-- Top Header -->
<header class="top-header">
    <div class="top-header-left">
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
        <h1><?= e($displayTitle) ?></h1>
    </div>
    <div class="top-header-right">
        <div class="dropdown">
            <button class="header-user dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="true">
                <div class="header-user-avatar">
                    <?php if (!empty($userAvatar)): ?>
                        <img src="<?= e($userAvatar) ?>" alt="Avatar">
                    <?php else: ?>
                        <?= e($userInitial) ?>
                    <?php endif; ?>
                </div>
                <div class="header-user-info d-none d-sm-block">
                    <div class="header-user-name"><?= e($userEmail) ?></div>
                    <div class="header-user-role"><?= e($userRole === 'clerk' ? 'Enrollment Clerk' : ucfirst($userRole)) ?></div>
                </div>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text text-muted small"><i class="bi bi-shield-check me-2"></i>Active: <?= e(ucfirst($userRole === 'clerk' ? 'Enrollment Clerk' : $userRole)) ?></span></li>

                <?php
                $allRoles = $_SESSION['all_roles'] ?? [$userRole];
                $roleLabels = ['admin' => 'Administrator', 'clerk' => 'Enrollment Clerk', 'teacher' => 'Teacher', 'guardian' => 'Guardian'];
                $otherRoles = array_filter($allRoles, fn($r) => $r !== $userRole);
                if (!empty($otherRoles)):
                ?>
                <li><hr class="dropdown-divider"></li>
                <li><span class="dropdown-item-text text-muted small fw-semibold">Switch Role</span></li>
                <?php foreach ($otherRoles as $r): ?>
                    <li>
                        <form method="POST" action="<?= APP_URL ?>/auth/switch-role.php" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="role" value="<?= e($r) ?>">
                            <button type="submit" class="dropdown-item">
                            <i class="bi bi-arrow-left-right me-2"></i><?= e($roleLabels[$r] ?? ucfirst($r)) ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
                <?php endif; ?>

                <li><hr class="dropdown-divider"></li>
                <?php if ($userRole === 'admin' || $userRole === 'clerk'): ?>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/staff-profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <?php endif; ?>
                <?php if ($userRole === 'guardian'): ?>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/guardian/profile-view.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <?php endif; ?>
                <li>
                    <form method="POST" action="<?= APP_URL ?>/auth/logout.php" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>

<!-- Main Content -->
<div class="main-content">
    <?= displayFlash() ?>

<?php endif; ?>
