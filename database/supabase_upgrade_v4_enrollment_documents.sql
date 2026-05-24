-- ============================================================
-- AGAPE-IIS Supabase Upgrade v4 - Enrollment requirement uploads
-- Allows guardians to upload PSA, medical records, previous school
-- records, and parent/guardian data for registrar review.
-- Safe to rerun.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS enrollment_documents (
    id            SERIAL PRIMARY KEY,
    enrollment_id INT NOT NULL REFERENCES enrollments(id) ON DELETE CASCADE,
    document_type VARCHAR(50) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_path     VARCHAR(500) NOT NULL,
    mime_type     VARCHAR(100) DEFAULT NULL,
    file_size     BIGINT DEFAULT NULL,
    uploaded_by   INT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    uploaded_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_enrollment_document_type
        CHECK (document_type IN ('psa', 'medical', 'previous_school', 'parent_data'))
);

CREATE INDEX IF NOT EXISTS idx_enrollment_documents_enrollment
    ON enrollment_documents (enrollment_id);

CREATE INDEX IF NOT EXISTS idx_enrollment_documents_uploaded_by
    ON enrollment_documents (uploaded_by);

CREATE UNIQUE INDEX IF NOT EXISTS uq_enrollment_documents_type
    ON enrollment_documents (enrollment_id, document_type);

COMMIT;
