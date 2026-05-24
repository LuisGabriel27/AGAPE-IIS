-- ============================================================
-- AGAPE-IIS Supabase Upgrade v9 - Clerk payment assessments
--
-- Adds an assessment record per enrollment plus a line-items
-- table so clerks can break down tuition, fees, discounts, and
-- scholarships before sending the enrollee to the cashier.
--
-- Idempotent: safe to rerun.
-- ============================================================

-- ── 1. ENUM types ───────────────────────────────────────────
DO $$
BEGIN
    CREATE TYPE assessment_status AS ENUM ('draft', 'sent_to_cashier', 'cancelled');
EXCEPTION
    WHEN duplicate_object THEN NULL;
END
$$;

DO $$
BEGIN
    CREATE TYPE assessment_item_category AS ENUM (
        'tuition',
        'enrollment_fee',
        'miscellaneous',
        'discount',
        'scholarship',
        'other'
    );
EXCEPTION
    WHEN duplicate_object THEN NULL;
END
$$;

-- ── 2. enrollment_assessments table ─────────────────────────
CREATE TABLE IF NOT EXISTS enrollment_assessments (
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
);

CREATE INDEX IF NOT EXISTS idx_enrollment_assessments_enrollment
    ON enrollment_assessments (enrollment_id);

CREATE INDEX IF NOT EXISTS idx_enrollment_assessments_status
    ON enrollment_assessments (status);

-- ── 3. enrollment_assessment_items table ────────────────────
CREATE TABLE IF NOT EXISTS enrollment_assessment_items (
    id              SERIAL PRIMARY KEY,
    assessment_id   INT NOT NULL REFERENCES enrollment_assessments(id) ON DELETE CASCADE,
    category        assessment_item_category NOT NULL,
    description     VARCHAR(255) NOT NULL DEFAULT '',
    amount          DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_enrollment_assessment_items_assessment
    ON enrollment_assessment_items (assessment_id);
