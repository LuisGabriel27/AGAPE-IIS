<?php
/**
 * OAuth Callback (Disabled)
 * Google OAuth sign-in has been removed from this application.
 */

require_once __DIR__ . '/../includes/session-check.php';
require_once __DIR__ . '/../includes/helpers.php';

unset(
    $_SESSION['oauth_state'],
    $_SESSION['oauth_intended_role'],
    $_SESSION['google_access_token'],
    $_SESSION['needs_profile_completion']
);

redirect(APP_URL . '/auth/select-role.php?error=oauth_disabled');
