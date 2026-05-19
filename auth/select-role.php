<?php
/**
 * Role Selection Page
 * Lets users choose which portal they want to access before signing in.
 */

require_once __DIR__ . '/../includes/session-check.php';
if (isLoggedIn()) {
    redirect(getRoleDashboardUrl());
}

require_once __DIR__ . '/../includes/helpers.php';

$errorMessages = [
    'unauthenticated' => 'Please choose your portal and sign in to continue.',
    'select_role'     => 'Please select a role before signing in.',
    'oauth_failed'    => 'Sign-in failed. Please try again.',
    'oauth_disabled'  => 'Google sign-in has been disabled. Please use your email and password.',
    'account_inactive'=> 'Your account has been deactivated. Contact an administrator.',
    'csrf_expired'    => 'Your form session expired. Please choose a portal and try again.',
];

$baseUrl = rtrim(APP_URL, '/');

$roleCards = [
    'admin' => [
        'label'       => 'Administrator',
        'description' => 'Manage users, schedules, sections, payments, and academic records.',
        'href'        => $baseUrl . '/auth/login.php?role=admin',
        'accent'      => '#4366F6',
        'accentBg'    => '#EEF1FE',
        'iconBg'      => '#E0EAFF',
        'iconColor'   => '#1D4ED8',
    ],
    'clerk' => [
        'label'       => 'Enrollment Clerk',
        'description' => 'Process enrollment applications, manage student records, and assist guardians.',
        'href'        => $baseUrl . '/auth/login.php?role=clerk',
        'accent'      => '#8B5CF6',
        'accentBg'    => '#F5F3FF',
        'iconBg'      => '#EDE9FE',
        'iconColor'   => '#6D28D9',
    ],
    'teacher' => [
        'label'       => 'Teacher',
        'description' => 'Open your teaching dashboard, class schedule, and grading tools.',
        'href'        => $baseUrl . '/auth/login.php?role=teacher',
        'accent'      => '#22C55E',
        'accentBg'    => '#F0FDF4',
        'iconBg'      => '#DCFCE7',
        'iconColor'   => '#15803D',
    ],
    'guardian' => [
        'label'       => 'Guardian',
        'description' => 'Track enrollment, grades, payments, and student updates in one place.',
        'href'        => $baseUrl . '/auth/login.php?role=guardian',
        'accent'      => '#F59E0B',
        'accentBg'    => '#FFFBEB',
        'iconBg'      => '#FEF3C7',
        'iconColor'   => '#B45309',
    ],
];

$urlError  = $_GET['error'] ?? '';
$pageError = $errorMessages[$urlError] ?? '';

$roleSvgIcons = [
    'admin'    => '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>',
    'clerk'    => '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
    'teacher'  => '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
    'guardian' => '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Portal &mdash; <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/branding/agape-logo.png">
    <meta name="description" content="Select your role to access the <?= e(APP_NAME) ?> portal. Sign in as Administrator, Teacher, or Guardian.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Reset & Base */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html {
            font-size: 16px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(circle at top left, rgba(67, 102, 246, 0.12), transparent 40%),
                radial-gradient(circle at bottom right, rgba(14, 165, 233, 0.08), transparent 35%),
                linear-gradient(180deg, #F5F7FA 0%, #EDF3FF 100%);
            color: #1E293B;
            padding: 2.5rem 1rem;
        }

        /* Page Shell */
        .portal-shell {
            width: 100%;
            max-width: 1200px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Error Alert */
        .portal-alert {
            width: 100%;
            max-width: 640px;
            padding: 0.875rem 1.25rem;
            margin-bottom: 1.5rem;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            border-left: 4px solid #EF4444;
            border-radius: 12px;
            color: #DC2626;
            font-size: 0.875rem;
            font-weight: 500;
            line-height: 1.5;
            text-align: center;
        }

        /* Header Banner */
        .portal-banner {
            position: relative;
            overflow: hidden;
            width: 100%;
            display: flex;
            align-items: center;
            gap: 2rem;
            background: linear-gradient(135deg, #1D4ED8 0%, #4366F6 45%, #6366F1 100%);
            color: white;
            border-radius: 24px;
            padding: 2.25rem 2.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 20px 50px rgba(29, 78, 216, 0.18);
        }

        .portal-banner-logo {
            width: clamp(128px, 14vw, 190px);
            aspect-ratio: 1 / 1;
            object-fit: contain;
            flex-shrink: 0;
            display: block;
            border-radius: 0;
            background: transparent;
            border: 0;
            padding: 0;
            filter: drop-shadow(0 8px 20px rgba(15, 23, 42, 0.18));
            position: relative;
            z-index: 1;
        }

        .portal-banner-content {
            position: relative;
            z-index: 1;
            max-width: 560px;
        }

        .portal-banner::before,
        .portal-banner::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
        }

        .portal-banner::before {
            width: 220px;
            height: 220px;
            top: -90px;
            right: -70px;
        }

        .portal-banner::after {
            width: 150px;
            height: 150px;
            bottom: -55px;
            left: 18%;
        }

        .portal-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            margin-bottom: 1rem;
            background: rgba(255,255,255,0.14);
            border-radius: 100px;
            font-size: 0.8125rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .portal-badge svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .portal-banner h1 {
            font-size: clamp(1.75rem, 4vw, 2.5rem);
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 0.625rem;
            letter-spacing: -0.03em;
            position: relative;
            z-index: 1;
        }

        .portal-banner p {
            font-size: 0.9375rem;
            opacity: 0.92;
            line-height: 1.6;
            max-width: 520px;
            margin: 0;
            position: relative;
            z-index: 1;
        }

        /* Card Grid */
        .portal-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            width: 100%;
            margin-bottom: 1.75rem;
        }

        /* Role Card */
        .role-card {
            position: relative;
            display: flex;
            flex-direction: column;
            padding: 1.75rem 1.5rem 1.5rem;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.7);
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.06);
            text-decoration: none;
            color: #1E293B;
            cursor: pointer;
            outline: none;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }

        .role-card:hover,
        .role-card:focus-visible {
            transform: translateY(-6px);
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
            border-color: var(--card-accent);
        }

        .role-card:active {
            transform: translateY(-2px);
            transition-duration: 0.08s;
        }

        .role-card.card-pressed {
            transform: scale(0.97);
            opacity: 0.85;
            transition-duration: 0.1s;
        }

        /* Focus ring for keyboard */
        .role-card:focus-visible {
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12), 0 0 0 3px var(--card-accent);
        }

        /* Card Icon */
        .card-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: var(--card-icon-bg);
            color: var(--card-icon-color);
            margin-bottom: 1.25rem;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .role-card:hover .card-icon,
        .role-card:focus-visible .card-icon {
            transform: scale(1.05);
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
        }

        .card-icon svg {
            width: 28px;
            height: 28px;
        }

        /* Card Text */
        .card-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1E293B;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }

        .card-desc {
            font-size: 0.875rem;
            color: #64748B;
            line-height: 1.65;
            flex-grow: 1;
            margin-bottom: 1.25rem;
        }

        /* Card CTA */
        .card-cta {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--card-accent);
            transition: gap 0.2s ease;
        }

        .role-card:hover .card-cta,
        .role-card:focus-visible .card-cta {
            gap: 0.75rem;
        }

        .card-cta svg {
            width: 16px;
            height: 16px;
            transition: transform 0.2s ease;
        }

        .role-card:hover .card-cta svg,
        .role-card:focus-visible .card-cta svg {
            transform: translateX(3px);
        }

        /* Footer */
        .portal-footer {
            text-align: center;
            font-size: 0.78rem;
            color: #94A3B8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
        }

        .portal-footer svg {
            width: 14px;
            height: 14px;
            opacity: 0.6;
        }

        /* Responsive */

        /* Tablet: 2 columns */
        @media (max-width: 900px) {
            .portal-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .portal-grid .role-card:last-child {
                grid-column: unset;
                max-width: unset;
                justify-self: unset;
            }
            .portal-banner {
                padding: 2rem;
                gap: 1.25rem;
            }
            .portal-banner-logo {
                width: 118px;
            }
        }

        /* Mobile: single column */
        @media (max-width: 540px) {
            body {
                padding: 1.5rem 1rem;
                align-items: flex-start;
            }
            .portal-banner {
                padding: 1.5rem;
                border-radius: 18px;
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .portal-banner-logo {
                width: 90px;
            }
            .portal-banner h1 {
                font-size: 1.5rem;
            }
            .portal-banner-content {
                max-width: 100%;
            }
            .portal-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .portal-grid .role-card:last-child {
                max-width: 100%;
            }
            .role-card {
                padding: 1.25rem;
                border-radius: 16px;
            }
            .card-icon {
                width: 52px;
                height: 52px;
                border-radius: 14px;
            }
            .card-icon svg {
                width: 24px;
                height: 24px;
            }
        }
    </style>
</head>
<body>

<div class="portal-shell">

    <?php if ($pageError !== ''): ?>
        <div class="portal-alert" role="alert"><?= e($pageError) ?></div>
    <?php endif; ?>

    <div class="portal-banner">
        <img src="<?= APP_URL ?>/assets/images/branding/agape-logo.png" alt="Agape Logo" class="portal-banner-logo">
        <div class="portal-banner-content">
            <div class="portal-badge">
                <?= e(APP_NAME) ?>
            </div>
            <h1>Choose your portal</h1>
            <p>Select the role that matches your account, then continue to a dedicated sign&#8209;in page.</p>
        </div>
    </div>

    <section class="portal-grid" aria-label="Role selection">
        <?php foreach ($roleCards as $roleKey => $card): ?>
            <?php
            // SVG icons are trusted static markup defined in this file.
            $cardIcon = $roleSvgIcons[$roleKey] ?? '';
            ?>
            <div
                id="role-card-<?= e($roleKey) ?>"
                class="role-card"
                data-href="<?= e($card['href']) ?>"
                role="button"
                tabindex="0"
                aria-label="Continue as <?= e($card['label']) ?>"
                style="--card-accent:<?= e($card['accent']) ?>;--card-icon-bg:<?= e($card['iconBg']) ?>;--card-icon-color:<?= e($card['iconColor']) ?>;"
            >
                <div class="card-icon" aria-hidden="true">
                    <?php echo $cardIcon; ?>
                </div>
                <div class="card-title"><?= e($card['label']) ?></div>
                <p class="card-desc"><?= e($card['description']) ?></p>
                <span class="card-cta" aria-hidden="true">
                    Continue
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </span>

            </div>
        <?php endforeach; ?>
    </section>

    <div class="portal-footer" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Secured portal access
    </div>
</div>

<script>
(function () {
    'use strict';

    document.querySelectorAll('.role-card').forEach(function (card) {
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activateCard(card);
            }
        });

        card.addEventListener('click', function () {
            activateCard(card);
        });
    });

    function activateCard(card) {
        card.classList.add('card-pressed');
        var href = card.getAttribute('data-href');
        setTimeout(function () {
            window.location.href = href;
        }, 140);
    }
})();
</script>

</body>
</html>
