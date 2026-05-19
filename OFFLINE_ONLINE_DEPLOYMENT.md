# AGAPE AIIS Offline/Online Deployment Plan

## What This Setup Does

AGAPE AIIS can run in two database modes:

- **Online mode**: the Docker app connects directly to Supabase.
- **Offline mode**: the Docker app connects to the local PostgreSQL container.

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
- Database records are synced; uploaded files in `uploads/` still need a file-sync process.
- If Supabase rejects a local change because of a constraint or duplicate, the row remains pending in `sync_outbox` with an error message.
