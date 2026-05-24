<?php
/**
 * CSRF Token Generation & Validation
 *
 * Usage:
 *   In forms:  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
 *   On POST:   validateCsrf();  // redirects with a friendly error if invalid
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
 * Return a same-origin URL that can safely receive the user after a failed
 * CSRF check. This avoids open redirects through the Referer header.
 */
function csrfSafeReturnUrl(): string
{
    $fallback = defined('APP_URL') ? rtrim(APP_URL, '/') . '/auth/select-role.php' : '/';
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer === '') {
        return $fallback;
    }

    $refererHost = parse_url($referer, PHP_URL_HOST);
    if ($refererHost === null || $refererHost === false) {
        return $fallback;
    }

    $refererPort = parse_url($referer, PHP_URL_PORT);
    $refererHostPort = strtolower($refererHost . ($refererPort ? ':' . $refererPort : ''));
    $currentHostPort = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

    return $refererHostPort === $currentHostPort ? $referer : $fallback;
}

/**
 * Add or replace one query string parameter on a URL.
 */
function csrfUrlWithQuery(string $url, string $key, string $value): string
{
    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query[$key] = $value;

    $rebuilt = '';
    if (!empty($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (!empty($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (!empty($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (!empty($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (!empty($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= $parts['path'] ?? '';
    $rebuilt .= '?' . http_build_query($query);
    if (!empty($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

/**
 * Validate the CSRF token from the POST request.
 * Redirects normal browser requests with a friendly error if invalid.
 */
function validateCsrf(): void
{
    $valid = !empty($_POST['csrf_token'])
        && !empty($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);

    if ($valid) {
        return;
    }

    unset($_SESSION['csrf_token']);
    $_SESSION['flash'] = [
        'type' => 'warning',
        'message' => 'Your form session expired. Please review the page and try again.',
    ];
    http_response_code(403);

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if (str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Your form session expired. Please refresh the page and try again.',
        ]);
        exit;
    }

    if (!headers_sent()) {
        header('Location: ' . csrfUrlWithQuery(csrfSafeReturnUrl(), 'error', 'csrf_expired'));
        exit;
    }

    die('Your form session expired. Please go back, refresh the page, and try again.');
}
