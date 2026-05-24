<?php
/**
 * Local runner for supabase_upgrade_v7_enrollment_statuses.sql
 *
 * Usage from a terminal:
 *   c:/xampp/php/php.exe database/run_v7_migration.php
 *
 * Safe to run multiple times — every statement uses IF NOT EXISTS or is
 * naturally idempotent. Delete this file once the migration is applied
 * if you don't want it sitting in production.
 */

require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Connecting to Supabase...\n";

// Each statement runs in its own implicit transaction so that newly-added
// enum values become visible to subsequent statements.
$enumValues = [
    'submitted',
    'requirements_incomplete',
    'documents_under_review',
    'assessed_for_payment',
    'awaiting_payment',
    'paid_for_registrar',
    'returned',
];

echo "Step 1: extending the enrollment_status enum...\n";
foreach ($enumValues as $value) {
    $sql = "ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS '" . $value . "'";
    try {
        $pdo->exec($sql);
        echo "  + added '{$value}'\n";
    } catch (PDOException $e) {
        echo "  ! '{$value}': " . $e->getMessage() . "\n";
    }
}

echo "Step 2: remapping existing rows...\n";

$updates = [
    "rejected -> returned" =>
        "UPDATE enrollments SET status = 'returned' WHERE status = 'rejected'",

    "approved -> paid_for_registrar" =>
        "UPDATE enrollments SET status = 'paid_for_registrar' WHERE status = 'approved'",

    "pending + paid payment -> paid_for_registrar" =>
        "UPDATE enrollments e SET status = 'paid_for_registrar'
         WHERE e.status = 'pending'
           AND EXISTS (SELECT 1 FROM payments p WHERE p.enrollment_id = e.id AND p.status = 'paid')",

    "pending + payment_submitted_at -> awaiting_payment" =>
        "UPDATE enrollments e SET status = 'awaiting_payment'
         WHERE e.status = 'pending' AND e.payment_submitted_at IS NOT NULL",

    "pending + 4 docs -> documents_under_review" =>
        "UPDATE enrollments e SET status = 'documents_under_review'
         WHERE e.status = 'pending'
           AND (
               SELECT COUNT(DISTINCT d.document_type) FROM enrollment_documents d
               WHERE d.enrollment_id = e.id
                 AND d.document_type IN ('psa','medical','previous_school','parent_data')
           ) >= 4",

    "pending + some docs -> requirements_incomplete" =>
        "UPDATE enrollments e SET status = 'requirements_incomplete'
         WHERE e.status = 'pending'
           AND (
               SELECT COUNT(DISTINCT d.document_type) FROM enrollment_documents d
               WHERE d.enrollment_id = e.id
                 AND d.document_type IN ('psa','medical','previous_school','parent_data')
           ) > 0",

    "pending + no docs -> submitted" =>
        "UPDATE enrollments SET status = 'submitted' WHERE status = 'pending'",
];

foreach ($updates as $label => $sql) {
    $affected = $pdo->exec($sql);
    echo "  + {$label}: {$affected} row(s)\n";
}

echo "\nDone. Reload the clerk dashboard.\n";
