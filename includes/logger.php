<?php
/**
 * Application logging helpers.
 *
 * Logs are written through PHP's configured error_log, but every entry includes
 * enough request/user context to diagnose production failures without showing
 * sensitive details to end users.
 */

require_once __DIR__ . '/../config/config.php';

function appRequestId(): string
{
    static $requestId = null;
    if ($requestId === null) {
        try {
            $requestId = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $requestId = str_replace('.', '', uniqid('', true));
        }
    }

    return $requestId;
}

function sanitizeLogContext(array $context): array
{
    $blocked = ['password', 'password_hash', 'token', 'csrf', 'secret', 'key', 'pass', 'authorization'];
    $safe = [];

    foreach ($context as $key => $value) {
        $keyString = strtolower((string)$key);
        foreach ($blocked as $needle) {
            if (str_contains($keyString, $needle)) {
                $safe[$key] = '[redacted]';
                continue 2;
            }
        }

        if (is_array($value)) {
            $safe[$key] = sanitizeLogContext($value);
        } elseif (is_scalar($value) || $value === null) {
            $safe[$key] = $value;
        } else {
            $safe[$key] = get_debug_type($value);
        }
    }

    return $safe;
}

function requestLogContext(array $context = []): array
{
    $base = [
        'request_id' => appRequestId(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'uri' => $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'CLI'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_id' => $_SESSION['user_id'] ?? null,
        'role' => $_SESSION['role'] ?? null,
    ];

    return sanitizeLogContext($base + $context);
}

function appLog(string $level, string $message, array $context = []): void
{
    $payload = requestLogContext($context);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    error_log('[AGAPE][' . strtoupper($level) . '] ' . $message . ' ' . ($encoded ?: '{}'));
}

function logException(Throwable $exception, string $message = 'Application exception', array $context = []): void
{
    appLog('error', $message, $context + [
        'exception_class' => get_class($exception),
        'exception_code' => $exception->getCode(),
        'exception_message' => $exception->getMessage(),
        'exception_file' => $exception->getFile(),
        'exception_line' => $exception->getLine(),
    ]);
}

function safeErrorMessage(string $message = 'An error occurred. Please try again later.'): string
{
    return $message . ' Reference: ' . appRequestId();
}

set_exception_handler(static function (Throwable $exception): void {
    logException($exception, 'Uncaught exception.');

    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<h1>Something went wrong</h1><p>'
            . htmlspecialchars(safeErrorMessage(), ENT_QUOTES, 'UTF-8')
            . '</p>';
    }
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    appLog('critical', 'Fatal PHP error.', [
        'error_type' => $error['type'],
        'error_message' => $error['message'],
        'error_file' => $error['file'],
        'error_line' => $error['line'],
    ]);
});
