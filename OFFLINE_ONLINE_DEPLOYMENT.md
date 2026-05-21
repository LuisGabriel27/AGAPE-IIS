# AGAPE AIIS Offline/Online Deployment Plan

## What This Setup Does

AGAPE AIIS can run in two database modes:

- **Online mode**: the Docker app connects directly to Supabase.
- **Offline mode**: the Docker app connects to the local PostgreSQL container.

Enrollment document files can also run in two storage modes:

- **Local file mode**: uploaded files stay in `uploads/enrollment-documents`.
- **Supabase Storage mode**: uploaded files are stored in a private Supabase Storage bucket and opened through the protected `enrollment-document.php` route.

For offline work, run a Supabase-to-local snapshot while internet is available.
Users can then keep using the local Docker app without internet. Local inserts,
updates, and deletes are recorded in `sync_outbox`. When internet returns, run
the sync command to push those local changes back to Supabase.

## Current Foundation Added

- `Dockerfile` builds the PHP/Apache app.
- `docker-compose.yml` starts the app and a local PostgreSQL database.
- `config/config.example.php` reads database/app settings from environment variables.
- `database/offline_sync_foundation.sql` creates a local `sync_outbox` table and database triggers that record local inserts, updates, and deletes.
- `database/pull_supabase_snapshot.php` copies current Supabase records into the local Docker database.
- `database/sync_to_supabase.php` pushes local `sync_outbox` changes back to Supabase.
- `database/sync_status.php` shows whether the app is in online or offline mode.
- `docker/postgres/init/90_local_seed.sql` seeds local sections and fixes the local admin password.
- `database/supabase_storage_bucket_setup.sql` creates the private Supabase Storage bucket for enrollment requirement files.

## Recommended Operating Rule

Use one writer at a time:

```text
Before going offline: pull Supabase into local Docker.
While offline: staff use only the local Docker app.
When online returns: push local Docker changes to Supabase before anyone edits Supabase directly.
```

This avoids difficult conflict cases where the same record is changed in two
places at the same time.

## Mode Files

Keep these files local only. They are ignored by Git:

- `.env.online` - app reads/writes Supabase directly.
- `.env.offline` - app reads/writes the local Docker database, while keeping Supabase credentials for sync commands.
- `.env` - the active mode used by Docker Compose.

Switch online:

```powershell
Copy-Item .env.online .env -Force
docker compose up -d --build app
```

For hosted/home-user online mode, set these in `.env.online` before rebuilding:

```text
DB_HOST=<Supabase pooler host>
DB_PORT=6543
DB_NAME=postgres
DB_USER=<Supabase pooler user>
DB_PASS=<Supabase database password>
DB_SSLMODE=require
SUPABASE_URL=<Supabase project URL>
SUPABASE_SERVICE_ROLE_KEY=<server-side service role key>
SUPABASE_STORAGE_BUCKET=enrollment-documents
ENROLLMENT_DOCUMENT_STORAGE_DRIVER=supabase
```

Do not put the service role key in browser JavaScript. It belongs only in the server `.env`.

If offline users upload enrollment documents, also set `SUPABASE_SERVICE_ROLE_KEY`
in `.env.offline`. During Manual Sync, local files under
`uploads/enrollment-documents` are uploaded to Supabase Storage first, then the
database row is pushed with its `supabase://...` file path.

Switch offline:

```powershell
Copy-Item .env.offline .env -Force
docker compose up -d --build app
```

## Initial Local Setup

1. Copy environment template:

   ```powershell
   Copy-Item .env.example .env
   ```

2. Edit `.env` and change `POSTGRES_PASSWORD`.

3. Start the stack:

   ```powershell
   docker compose up -d --build
   ```

4. Open:

   ```text
   http://localhost:8080
   ```

5. Local default login:

   ```text
   Email: admin@academy.edu
   Password: Admin@1234
   ```

## How To Prepare For Offline Use

Do this while internet is available:

```powershell
Copy-Item .env.offline .env -Force
docker compose up -d --build app
docker compose exec -T app php database/pull_supabase_snapshot.php
docker compose exec -T app php database/sync_status.php
```

Then turn off internet and open:

```text
http://localhost:8080
```

If the app still opens and records are visible, offline viewing is working.

## How To Sync After Offline Work

After internet comes back:

In the system, sign in as administrator and open:

```text
Settings -> Manual Sync
```

Click **Preview Sync**, then **Sync to Supabase**.

Command-line fallback:

```powershell
docker compose exec -T app php database/sync_status.php
docker compose exec -T app php database/sync_to_supabase.php
docker compose exec -T app php database/sync_status.php
```

Optional dry-run first:

```powershell
docker compose exec -T app php database/sync_to_supabase.php --dry-run
```

After a successful push, you may refresh local from Supabase again:

```powershell
docker compose exec -T app php database/pull_supabase_snapshot.php
```

## Important Limits

This is now a practical offline/local workflow, but still not a perfect
multi-master sync system.

Remaining limits:

- Avoid editing the same record in Supabase and local Docker at the same time.
- In `ENROLLMENT_DOCUMENT_STORAGE_DRIVER=local`, uploaded files stay on that device until Manual Sync uploads enrollment documents to Supabase Storage.
- In `ENROLLMENT_DOCUMENT_STORAGE_DRIVER=supabase`, uploaded enrollment documents are shared through Supabase Storage and can be opened from other devices after login.
- If Supabase rejects a local change because of a constraint or duplicate, the row remains pending in `sync_outbox` with an error message.

## Recommended Real Deployment Shape

- School office/admin and enrollment clerk devices can run Docker offline mode when the internet is unreliable.
- Guardians and teachers should use a hosted online deployment connected directly to Supabase Postgres and Supabase Storage.
- Before the school office goes offline, pull the latest Supabase snapshot. After offline work, use Manual Sync before making more online admin changes.

## Current Project Hybrid Setup Without Public Hosting Yet

The codebase now supports a single-login hybrid setup through `APP_DEPLOYMENT_MODE`:

```text
development        = all roles use the same DB_* connection
local_school       = admin and enrollment clerk only
online_portal      = teacher and guardian only
hybrid_role_routed = all roles share one portal, but DB routing depends on role
```

For the current capstone setup, use one Docker app entry point:

```text
http://localhost:8080
APP_DEPLOYMENT_MODE=hybrid_role_routed

Admin and enrollment clerk:
DB_* points to Docker PostgreSQL
They can work while the school internet is down, then use Manual Sync.

Teacher and guardian:
SUPABASE_DB_* points to Supabase Transaction Pooler
Their changes save directly to Supabase.
```

For guardian enrollment document uploads in this hybrid setup, the app routes
guardian-side document storage to Supabase Storage. Keep these configured in
`.env` before testing online guardian uploads:

```text
SUPABASE_URL=...
SUPABASE_SERVICE_ROLE_KEY=...
SUPABASE_STORAGE_BUCKET=enrollment-documents
```

This keeps one portal/login page for the project demo while still matching the
future deployment idea: school-office staff can have offline capability, while
teachers and guardians use the live Supabase database. Later, the same code can
be hosted publicly; the hosted server can run `online_portal` mode or keep
`hybrid_role_routed` if the school still wants one shared deployment.
