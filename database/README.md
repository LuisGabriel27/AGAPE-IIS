# AGAPE AIIS Database Setup

This branch uses **Supabase PostgreSQL** as the active database backend.

Do not use the legacy MySQL files for the active `w/supabase` branch unless you are intentionally inspecting old history.

## Canonical Supabase Path

Use this order for a fresh Supabase database:

1. Run `supabase_schema.sql`.
2. Run `supabase_upgrade_v2.sql`.
3. Run `supabase_upgrade_v3_split_name.sql`.
4. Run `supabase_upgrade_v4_enrollment_documents.sql`.
5. Run `supabase_upgrade_v5_deped_grading.sql`.
6. Run `supabase_upgrade_v6_subject_grade_levels.sql`.
7. Run `supabase_upgrade_v7_enrollment_statuses.sql`.
8. Run `supabase_upgrade_v8_document_review.sql`.
9. Run `supabase_upgrade_v9_payment_assessments.sql`.
10. Run `supabase_production_readiness_preflight.sql`.
11. If every preflight `issue_count` is `0`, run `supabase_production_readiness_hardening.sql`.
12. Run `supabase_email_case_hardening.sql`.
13. Run `supabase_profile_fields_expansion.sql`.

The production readiness hardening SQL was successfully applied to the current Supabase project on 2026-05-17.

## Existing Supabase Database

For an existing database with data:

1. Back up/export the database first.
2. Run only missing upgrade files in order.
3. Run `supabase_production_readiness_preflight.sql`.
4. Clean any reported duplicate or invalid data.
5. Run `supabase_production_readiness_hardening.sql`.

Do not rerun `supabase_schema.sql` on a database that already contains live data.

## File Status

Active Supabase files:

- `supabase_schema.sql`
- `supabase_upgrade_v2.sql`
- `supabase_upgrade_v3_split_name.sql`
- `supabase_upgrade_v4_enrollment_documents.sql`
- `supabase_upgrade_v5_deped_grading.sql`
- `supabase_upgrade_v6_subject_grade_levels.sql`
- `supabase_upgrade_v7_enrollment_statuses.sql`
- `supabase_upgrade_v8_document_review.sql`
- `supabase_upgrade_v9_payment_assessments.sql`
- `supabase_production_readiness_preflight.sql`
- `supabase_production_readiness_hardening.sql`
- `supabase_email_case_hardening.sql`
- `supabase_profile_fields_expansion.sql`

Legacy or reference files:

- `schema.sql` is the old MySQL schema.
- `upgrade_v2.sql` and `upgrade_migrations.sql` are old MySQL upgrade files.
- `supabase_update_required_latest.sql` is an older combined upgrade helper and does not replace the canonical order above.
- `supabase_reset.sql` is useful only for destructive local reset/testing. Do not run it on a database with data.
- `run_v7_migration.php`, `run_v8_migration.php`, and `run_v9_migration.php` are older PHP convenience runners. Prefer Supabase SQL Editor for the canonical path.

## Verification

After migrations, run:

```sql
SELECT 'database_ready' AS check_name;
```

Then run `supabase_production_readiness_preflight.sql` again. All summary `issue_count` values should be `0`.
