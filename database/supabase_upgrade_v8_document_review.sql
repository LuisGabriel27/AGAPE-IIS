-- ============================================================
-- AGAPE-IIS Supabase Upgrade v8 - Per-document review checklist
--
-- Adds a review status, reviewer note, and reviewer audit fields
-- to enrollment_documents so the Enrollment Clerk can validate
-- each required file individually.
--
-- Idempotent: safe to rerun.
-- ============================================================

-- ── 1. ENUM type for document review state ──────────────────
DO $$
BEGIN
    CREATE TYPE document_review_status AS ENUM (
        'pending',
        'accepted',
        'needs_replacement',
        'missing'
    );
EXCEPTION
    WHEN duplicate_object THEN NULL;
END
$$;

-- ── 2. Columns on enrollment_documents ──────────────────────
ALTER TABLE enrollment_documents
    ADD COLUMN IF NOT EXISTS review_status document_review_status NOT NULL DEFAULT 'pending';

ALTER TABLE enrollment_documents
    ADD COLUMN IF NOT EXISTS reviewer_note TEXT DEFAULT NULL;

ALTER TABLE enrollment_documents
    ADD COLUMN IF NOT EXISTS reviewed_by INT DEFAULT NULL;

ALTER TABLE enrollment_documents
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP DEFAULT NULL;

-- ── 3. Foreign key for reviewed_by (drop+recreate idempotently) ─
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
$$;

-- ── 4. Index for filtering by review state ──────────────────
CREATE INDEX IF NOT EXISTS idx_enrollment_documents_review_status
    ON enrollment_documents (review_status);
