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

$displayTitle = isset($pageTitle) ? e($pageTitle) : APP_NAME;
$fullTitle    = isset($pageTitle) ? e($pageTitle) . ' — ' . APP_NAME : APP_NAME;
$currentPage  = basename($_SERVER['PHP_SELF']);
$userRole     = $_SESSION['role'] ?? '';
$userEmail    = $_SESSION['user_email'] ?? 'Account';
$userAvatar   = $_SESSION['google_avatar'] ?? '';
$userInitial  = strtoupper(substr($userEmail, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Academy Information System — Manage students, grades, enrollments, and schedules.">
    <title><?= $fullTitle ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<?php if (isLoggedIn()): ?>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <a href="<?= getRoleDashboardUrl() ?>" class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>
        <div class="sidebar-brand-text">
            AGAPE
            <small>Academy Portal</small>
        </div>
    </a>

    <nav class="sidebar-nav">
        <?php if ($userRole === 'admin'): ?>

            <div class="sidebar-section">Main</div>
            <a class="sidebar-link <?= $currentPage === 'admin-dashboard.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-dashboard.php">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>

            <div class="sidebar-section">People</div>
            <a class="sidebar-link <?= $currentPage === 'admin-students.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-students.php">
                <i class="bi bi-people-fill"></i> Students
            </a>
            <a class="sidebar-link <?= $currentPage === 'admin-guardians.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-guardians.php">
                <i class="bi bi-person-hearts"></i> Guardians
            </a>
            <a class="sidebar-link <?= $currentPage === 'admin-teachers.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-teachers.php">
                <i class="bi bi-person-workspace"></i> Teachers
            </a>
            <a class="sidebar-link <?= $currentPage === 'admin-users.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-users.php">
                <i class="bi bi-person-gear"></i> User Accounts
            </a>

            <div class="sidebar-section">Academics</div>
            <a class="sidebar-link <?= $currentPage === 'admin-enrollments.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-enrollments.php">
                <i class="bi bi-pencil-square"></i> Enrollments
            </a>
            <a class="sidebar-link <?= $currentPage === 'admin-subjects.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-subjects.php">
                <i class="bi bi-book-fill"></i> Subjects
            </a>
            <a class="sidebar-link <?= $currentPage === 'admin-sections.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-sections.php">
                <i class="bi bi-diagram-3-fill"></i> Sections
            </a>
            <a class="sidebar-link <?= $currentPage === 'admin-grades.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-grades.php">
                <i class="bi bi-card-checklist"></i> Grades
            </a>
            <a class="sidebar-link <?= $currentPage === 'admin-schedule.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-schedule.php">
                <i class="bi bi-calendar2-week-fill"></i> Schedules
            </a>
            <a class="sidebar-link <?= $currentPage === 'admin-calendar.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-calendar.php">
                <i class="bi bi-calendar-event-fill"></i> Calendar
            </a>

            <div class="sidebar-section">Finance</div>
            <a class="sidebar-link <?= $currentPage === 'admin-payments.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/admin/admin-payments.php">
                <i class="bi bi-cash-stack"></i> Financial Ledger
            </a>

        <?php elseif ($userRole === 'teacher'): ?>

            <div class="sidebar-section">Main</div>
            <a class="sidebar-link <?= $currentPage === 'teacher-dashboard.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/teacher/teacher-dashboard.php">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>

            <div class="sidebar-section">Teaching</div>
            <a class="sidebar-link <?= $currentPage === 'teacher-grades.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/teacher/teacher-grades.php">
                <i class="bi bi-pencil-square"></i> Grades
            </a>
            <a class="sidebar-link <?= $currentPage === 'teacher-schedule.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/teacher/teacher-schedule.php">
                <i class="bi bi-calendar2-week-fill"></i> Schedule
            </a>

        <?php elseif ($userRole === 'guardian'): ?>

            <div class="sidebar-section">Main</div>
            <a class="sidebar-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/guardian/dashboard.php">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>

            <div class="sidebar-section">Academic</div>
            <a class="sidebar-link <?= $currentPage === 'enrollment.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/guardian/enrollment.php">
                <i class="bi bi-pencil-square"></i> Enrollment
            </a>
            <a class="sidebar-link <?= $currentPage === 'grades.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/guardian/grades.php">
                <i class="bi bi-card-checklist"></i> Grades
            </a>
            <a class="sidebar-link <?= $currentPage === 'schedule.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/guardian/schedule.php">
                <i class="bi bi-calendar2-week-fill"></i> Schedule
            </a>

            <div class="sidebar-section">Finance</div>
            <a class="sidebar-link <?= $currentPage === 'payments.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/guardian/payments.php">
                <i class="bi bi-credit-card-fill"></i> Payments
            </a>

            <div class="sidebar-section">Account</div>
            <a class="sidebar-link <?= $currentPage === 'profile-view.php' ? 'active' : '' ?>"
               href="<?= APP_URL ?>/guardian/profile-view.php">
                <i class="bi bi-person-circle"></i> My Profile
            </a>

        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a class="sidebar-link text-danger" href="<?= APP_URL ?>/auth/logout.php" style="color: var(--danger) !important;">
            <i class="bi bi-box-arrow-left"></i> Sign Out
        </a>
    </div>
</aside>

<!-- Top Header -->
<header class="top-header">
    <div class="top-header-left">
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
        <h1><?= $displayTitle ?></h1>
    </div>
    <div class="top-header-right">
        <div class="dropdown">
            <button class="header-user dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="true">
                <div class="header-user-avatar">
                    <?php if (!empty($userAvatar)): ?>
                        <img src="<?= e($userAvatar) ?>" alt="Avatar">
                    <?php else: ?>
                        <?= $userInitial ?>
                    <?php endif; ?>
                </div>
                <div class="header-user-info d-none d-sm-block">
                    <div class="header-user-name"><?= e($userEmail) ?></div>
                    <div class="header-user-role"><?= e(ucfirst($userRole)) ?></div>
                </div>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text text-muted small"><i class="bi bi-shield-check me-2"></i>Role: <?= e(ucfirst($userRole)) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <?php if ($userRole === 'guardian'): ?>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/guardian/profile-view.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <?php endif; ?>
                <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</header>

<!-- Main Content -->
<div class="main-content">
    <?= displayFlash() ?>

<?php endif; ?>
