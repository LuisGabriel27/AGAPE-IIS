<?php
/**
 * CSRF Token Generation & Validation
 *
 * Usage:
 *   In forms:  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
 *   On POST:   validateCsrf();  // dies with 403 if invalid
 */

/**
 * Generate or retrieve the current session's CSRF token.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the CSRF token from the POST request.
 * Halts execution with 403 if the token is missing or invalid.
 */
function validateCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
    ) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }
}
