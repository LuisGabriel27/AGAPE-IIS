<?php
/**
 * Standalone guardian management has been retired.
 * Guardians are now created or linked from the student management workflow.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin', 'clerk']);
require_once __DIR__ . '/../includes/helpers.php';

setFlash('info', 'Guardian management is now handled from the Students page.');
redirect(APP_URL . '/admin/admin-students.php');
