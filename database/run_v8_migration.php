<?php
/**
 * Local runner for supabase_upgrade_v8_document_review.sql
 *
 * Usage:
 *   c:/xampp/php/php.exe database/run_v8_migration.php
 *
 * Idempotent. Delete after applying if you don't want it lying around.
 */

require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Connecting to Supabase...\n";

$steps = [
    "create document_review_status enum" => <<<SQL
DO $$
BEGIN
    CREATE TYPE document_review_status AS ENUM (
        'pending', 'accepted', 'needs_replacement', 'missing'
    );
EXCEPTION
    WHEN duplicate_object THEN NULL;
END
$$
SQL,

    "add review_status column" =>
        "ALTER TABLE enrollment_documents
         ADD COLUMN IF NOT EXISTS review_status document_review_status NOT NULL DEFAULT 'pending'",

    "add reviewer_note column" =>
        "ALTER TABLE enrollment_documents
         ADD COLUMN IF NOT EXISTS reviewer_note TEXT DEFAULT NULL",

    "add reviewed_by column" =>
        "ALTER TABLE enrollment_documents
         ADD COLUMN IF NOT EXISTS reviewed_by INT DEFAULT NULL",

    "add reviewed_at column" =>
        "ALTER TABLE enrollment_documents
         ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP DEFAULT NULL",

    "add reviewer foreign key" => <<<SQL
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_enrollment_document_reviewer'
    ) THEN
        ALTER TABLE enrollment_documents
            ADD CONSTRAINT fk_enrollment_document_reviewer
            FOREIGN KEY (reviewed_by) REFERENCES users(id)
            ON DELETE SET NULL;
    END IF;
END
$$
SQL,

    "create review_status index" =>
        "CREATE INDEX IF NOT EXISTS idx_enrollment_documents_review_status
         ON enrollment_documents (review_status)",
];

foreach ($steps as $label => $sql) {
    try {
        $pdo->exec($sql);
        echo "  + {$label}\n";
    } catch (PDOException $e) {
        echo "  ! {$label}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone. The clerk dashboard now has document review fields.\n";
