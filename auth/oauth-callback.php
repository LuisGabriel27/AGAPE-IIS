<?php
/**
 * Google OAuth 2.0 Callback Handler
 *
 * Flow:
 * 1. Validate state parameter (CSRF protection)
 * 2. Exchange authorization code for access token
 * 3. Fetch Google user profile
 * 4. Lookup / create / link user account
 * 5. Set session and redirect
 */

require_once __DIR__ . '/../includes/session-check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/google.php';

$selectRoleUrl = APP_URL . '/auth/select-role.php';
$baseLoginUrl = APP_URL . '/auth/login.php';
$intendedRole = $_SESSION['oauth_intended_role'] ?? '';
unset($_SESSION['oauth_intended_role']);

$buildLoginUrl = static function (string $role = '', string $error = '') use ($selectRoleUrl, $baseLoginUrl): string {
    if ($role !== '') {
        $query = ['role' => $role];

        if ($error !== '') {
            $query['error'] = $error;
        }

        return $baseLoginUrl . '?' . http_build_query($query);
    }

    if ($error !== '') {
        return $selectRoleUrl . '?' . http_build_query(['error' => $error]);
    }

    return $selectRoleUrl;
};

$returnedState = $_GET['state'] ?? '';
$expectedState = $_SESSION['oauth_state'] ?? '';

if (empty($returnedState) || !hash_equals($expectedState, $returnedState)) {
    error_log('OAuth state mismatch.');
    redirect($buildLoginUrl($intendedRole, 'oauth_failed'));
}
unset($_SESSION['oauth_state']);

if (!empty($_GET['error'])) {
    error_log('Google OAuth error: ' . $_GET['error']);
    redirect($buildLoginUrl($intendedRole, 'oauth_failed'));
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    redirect($buildLoginUrl($intendedRole, 'oauth_failed'));
}

try {
    $client = getGoogleClient();
    $token = $client->fetchAccessTokenWithAuthCode($code);

    if (isset($token['error'])) {
        error_log('OAuth token error: ' . ($token['error_description'] ?? $token['error']));
        redirect($buildLoginUrl($intendedRole, 'oauth_failed'));
    }

    $client->setAccessToken($token);

    $oauth = new Google\Service\Oauth2($client);
    $profile = $oauth->userinfo->get();

    $googleId = $profile->getId();
    $googleEmail = $profile->getEmail();
    $googleName = $profile->getName();
    $googleAvatar = $profile->getPicture();
} catch (Exception $e) {
    error_log('OAuth exception: ' . $e->getMessage());
    redirect($buildLoginUrl($intendedRole, 'oauth_failed'));
}

$pdo = getDB();

$stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = :gid LIMIT 1');
$stmt->execute([':gid' => $googleId]);
$user = $stmt->fetch();

if ($user) {
    if (!$user['is_active']) {
        redirect($buildLoginUrl($intendedRole, 'account_inactive'));
    }

    $stmt = $pdo->prepare('UPDATE users SET last_login = NOW(), google_avatar = :avatar WHERE id = :id');
    $stmt->execute([':avatar' => $googleAvatar, ':id' => $user['id']]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $googleEmail]);
    $user = $stmt->fetch();

    if ($user) {
        if (!$user['is_active']) {
            redirect($buildLoginUrl($intendedRole, 'account_inactive'));
        }

        $stmt = $pdo->prepare('UPDATE users SET google_id = :gid, google_avatar = :avatar, last_login = NOW() WHERE id = :id');
        $stmt->execute([':gid' => $googleId, ':avatar' => $googleAvatar, ':id' => $user['id']]);
        auditLog('google_link', 'users', $user['id']);
    } else {
        if ($intendedRole !== '' && $intendedRole !== 'guardian') {
            redirect($buildLoginUrl($intendedRole, 'google_role_unavailable'));
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO users (email, password_hash, google_id, google_avatar, role, is_active, created_at, last_login)
                 VALUES (:email, NULL, :gid, :avatar, 'guardian', 1, NOW(), NOW())"
            );
            $stmt->execute([
                ':email' => $googleEmail,
                ':gid' => $googleId,
                ':avatar' => $googleAvatar,
            ]);
            $userId = $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO guardians (user_id, full_name) VALUES (:uid, :name)');
            $stmt->execute([':uid' => $userId, ':name' => $googleName]);

            $pdo->commit();

            $user = [
                'id' => $userId,
                'email' => $googleEmail,
                'role' => 'guardian',
                'google_avatar' => $googleAvatar,
            ];

            $_SESSION['needs_profile_completion'] = true;
            auditLog('signup_google', 'users', (int) $userId);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('OAuth user creation failed: ' . $e->getMessage());
            redirect($buildLoginUrl($intendedRole, 'oauth_failed'));
        }
    }
}

if ($intendedRole !== '' && ($user['role'] ?? '') !== $intendedRole) {
    redirect($buildLoginUrl($intendedRole, 'role_mismatch'));
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_email'] = $user['email'] ?? $googleEmail;
$_SESSION['role'] = $user['role'];
$_SESSION['google_avatar'] = $googleAvatar;
$_SESSION['google_access_token'] = $token;

auditLog('login_google', 'users', (int) $user['id']);

if (!empty($_SESSION['needs_profile_completion'])) {
    redirect(APP_URL . '/auth/complete-profile.php');
}

redirect(getRoleDashboardUrl());
