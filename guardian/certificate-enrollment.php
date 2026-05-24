<?php
require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');

$target = APP_URL . '/guardian/enrollment/certificate.php';
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}

header('Location: ' . $target);
exit;
