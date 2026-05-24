# AGAPE AIIS Database Setup

This branch uses **Supabase PostgreSQL** as the active database backend.

Do not use the legacy MySQL files for the active `w/supabase` branch unless you are intentionally inspecting old history.

## Canonical Supabase Path

Use this single paste-ready file for the current app database:

1. Back up/export the database first if it already has live data.
2. Open Supabase Dashboard -> SQL Editor -> New Query.
3. Paste and run `supabase_update_required_latest.sql`.

That file is idempotent and combines the current required app updates, including enrollment document review notes, payment assessments, profile fields, quarterly settings, PSA uniqueness, and Philippine ZIP code validation.

For a brand-new empty database, run `supabase_schema.sql` first, then run `supabase_update_required_latest.sql` once. Do not rerun `supabase_schema.sql` on a database that already contains live data.

## Existing Supabase Database

For an existing database with data:

1. Back up/export the database first.
2. Run `supabase_update_required_latest.sql`.
3. If Supabase reports duplicate PSA or invalid ZIP values, clean those rows and rerun the same file.

Do not rerun `supabase_schema.sql` on a database that already contains live data.

## File Status

Active Supabase files:

- `supabase_schema.sql`
- `supabase_update_required_latest.sql`

Legacy or reference files:

- `schema.sql` is the old MySQL schema.
- `upgrade_v2.sql` and `upgrade_migrations.sql` are old MySQL upgrade files.
- `supabase_upgrade_v*.sql`, `supabase_profile_fields_expansion.sql`, `supabase_email_case_hardening.sql`, and production-readiness scripts are retained as history/reference. The combined latest file is the normal one to paste.
- `supabase_reset.sql` is useful only for destructive local reset/testing. Do not run it on a database with data.
- `run_v7_migration.php`, `run_v8_migration.php`, and `run_v9_migration.php` are older PHP convenience runners. Prefer Supabase SQL Editor for the canonical path.

## Verification

After migrations, run:

```sql
SELECT 'database_ready' AS check_name;
```

Optional production audits can still use `supabase_production_readiness_preflight.sql`.
