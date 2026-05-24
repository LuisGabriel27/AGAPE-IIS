<?php
/**
 * Local runner for supabase_upgrade_v9_payment_assessments.sql
 *
 * Usage:
 *   c:/xampp/php/php.exe database/run_v9_migration.php
 *
 * Idempotent. Delete after applying if you don't want it lying around.
 */

require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Connecting to Supabase...\n";

$steps = [
    "create assessment_status enum" => <<<SQL
DO $$
BEGIN
    CREATE TYPE assessment_status AS ENUM ('draft', 'sent_to_cashier', 'cancelled');
EXCEPTION
    WHEN duplicate_object THEN NULL;
END
$$
SQL,

    "create assessment_item_category enum" => <<<SQL
DO $$
BEGIN
    CREATE TYPE assessment_item_category AS ENUM (
        'tuition', 'enrollment_fee', 'miscellaneous',
        'discount', 'scholarship', 'other'
    );
EXCEPTION
    WHEN duplicate_object THEN NULL;
END
$$
SQL,

    "create enrollment_assessments table" =>
        "CREATE TABLE IF NOT EXISTS enrollment_assessments (
            id                  SERIAL PRIMARY KEY,
            enrollment_id       INT NOT NULL REFERENCES enrollments(id) ON DELETE CASCADE,
            status              assessment_status NOT NULL DEFAULT 'draft',
            total_amount        DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            notes               TEXT DEFAULT NULL,
            created_by          INT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
            created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at          TIMESTAMP NOT NULL DEFAULT NOW(),
            sent_to_cashier_at  TIMESTAMP DEFAULT NULL,
            sent_by             INT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL
        )",

    "create idx_enrollment_assessments_enrollment" =>
        "CREATE INDEX IF NOT EXISTS idx_enrollment_assessments_enrollment
         ON enrollment_assessments (enrollment_id)",

    "create idx_enrollment_assessments_status" =>
        "CREATE INDEX IF NOT EXISTS idx_enrollment_assessments_status
         ON enrollment_assessments (status)",

    "create enrollment_assessment_items table" =>
        "CREATE TABLE IF NOT EXISTS enrollment_assessment_items (
            id              SERIAL PRIMARY KEY,
            assessment_id   INT NOT NULL REFERENCES enrollment_assessments(id) ON DELETE CASCADE,
            category        assessment_item_category NOT NULL,
            description     VARCHAR(255) NOT NULL DEFAULT '',
            amount          DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            sort_order      INT NOT NULL DEFAULT 0,
            created_at      TIMESTAMP NOT NULL DEFAULT NOW()
        )",

    "create idx_enrollment_assessment_items_assessment" =>
        "CREATE INDEX IF NOT EXISTS idx_enrollment_assessment_items_assessment
         ON enrollment_assessment_items (assessment_id)",
];

foreach ($steps as $label => $sql) {
    try {
        $pdo->exec($sql);
        echo "  + {$label}\n";
    } catch (PDOException $e) {
        echo "  ! {$label}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone. Clerk can now build payment assessments.\n";
