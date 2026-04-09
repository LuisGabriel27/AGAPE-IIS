<?php
/**
 * Root index.php — Redirect to role selection page
 */
require_once __DIR__ . '/config/config.php';
header('Location: ' . APP_URL . '/auth/select-role.php');
exit;
