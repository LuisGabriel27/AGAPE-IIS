-- ============================================================
-- AGAPE-IIS Supabase Upgrade v7 - Enrollment workflow statuses
--
-- Replaces the broad 'pending' / 'approved' / 'rejected' triplet
-- with explicit pipeline stages. Existing rows are remapped based
-- on document upload counts and the latest payment record.
--
-- Idempotent: safe to rerun.
-- ============================================================

-- ── 1. Extend the enrollment_status ENUM ─────────────────────
-- (must commit before the new values can be used in DML below)
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'submitted';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'requirements_incomplete';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'documents_under_review';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'assessed_for_payment';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'awaiting_payment';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'paid_for_registrar';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'returned';

COMMIT;

-- ── 2. Remap legacy rows ─────────────────────────────────────
BEGIN;

-- 'rejected' → 'returned'
UPDATE enrollments
SET status = 'returned'
WHERE status = 'rejected';

-- 'approved' (rare legacy use; same display as enrolled but pre-submission)
-- Treat as paid + ready for registrar so a clerk can finalize.
UPDATE enrollments
SET status = 'paid_for_registrar'
WHERE status = 'approved';

-- 'pending' → split by pipeline stage
-- Order matters: each UPDATE narrows what 'pending' rows remain.

-- Cashier already verified payment -> ready for registrar
UPDATE enrollments e
SET status = 'paid_for_registrar'
WHERE e.status = 'pending'
  AND EXISTS (
      SELECT 1 FROM payments p
      WHERE p.enrollment_id = e.id
        AND p.status = 'paid'
  );

-- Guardian submitted payment proof but cashier hasn't verified
UPDATE enrollments e
SET status = 'awaiting_payment'
WHERE e.status = 'pending'
  AND e.payment_submitted_at IS NOT NULL;

-- All four required documents uploaded -> ready for clerk review
UPDATE enrollments e
SET status = 'documents_under_review'
WHERE e.status = 'pending'
  AND (
      SELECT COUNT(DISTINCT d.document_type)
      FROM enrollment_documents d
      WHERE d.enrollment_id = e.id
        AND d.document_type IN ('psa', 'medical', 'previous_school', 'parent_data')
  ) >= 4;

-- Some but not all documents uploaded
UPDATE enrollments e
SET status = 'requirements_incomplete'
WHERE e.status = 'pending'
  AND (
      SELECT COUNT(DISTINCT d.document_type)
      FROM enrollment_documents d
      WHERE d.enrollment_id = e.id
        AND d.document_type IN ('psa', 'medical', 'previous_school', 'parent_data')
  ) > 0;

-- No documents uploaded yet
UPDATE enrollments
SET status = 'submitted'
WHERE status = 'pending';

COMMIT;

-- Note: PostgreSQL ENUM values cannot be removed without recreating the
-- type. The legacy values 'pending', 'approved', 'rejected' remain in the
-- type definition but are no longer written by application code.
