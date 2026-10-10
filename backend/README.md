# Masal Laravel backend

**Live backend: `20261007-full-system-07`; portal frontend: `20261007-full-system-07-ui01`, published on 2026-10-07.** Production runs PHP **8.5.11** and Laravel **13.34**, with 94 InnoDB tables. Deployment matched 609 files, preserved the contents of 59 existing business tables and retained the two existing accounts and two users. Production HTTP QA passed **1746/1746 checks**. Focused Digital desktop/mobile review and real signed backup creation passed; full visual, external-provider, printer and SMTP acceptance remain separate items.

Vue source and Node dependencies are in `../frontend`. This directory contains the domain API, business workflows and server authorization. See [the migration plan](../MIGRATION_PLAN.md), [release-07 verification](../docs/FULL_SYSTEM_07_VERIFICATION.md) and [deployment guide](../deployment/README.md).

## Modules and authorization

The current application implements accounts and ancestry, staff and permission profiles, private attachments, catalog and reference data, finance/wallets/prices/funding/invoices, stock/import/claims/returns/export, sales and printing/receipts/reprints, support and notifications, reports/dashboard, digital providers, maps/presence, operation stops/time policies/archive, company/public enquiries, server backups and user preferences.

Domains are grouped under `app/Models`, `app/Services`, `app/Http/Controllers` and the corresponding FormRequests/resources. Domain route files are registered from `routes/web.php`. Server services own hierarchy checks, transaction guards and business rules; frontend visibility is not authorization.

- Portal sessions use exact administrator, agents and POS hostnames, with separate host-only cookies. The public company host has a limited read/enquiry API surface, enforced by both the bridge and `ResolvePortal`.
- `/api/v1/*` uses session and CSRF protection in the `web` middleware stack. Sanctum supplies `/sanctum/csrf-cookie` and the authentication guard. Portal login does not issue bearer tokens.
- `X-Masal-Portal` is accepted only in local/testing mode. Production identity comes from the allowed hostname and current server session.
- Account scope, membership kind, employee scope roots, action grants, ancestor state and record state are checked for each request. POS belongs to any agent level and cannot create child accounts. A Main employee restricted to POS accounts does not gain its employer's provider connections or balances.
- Account creation saves the child, ancestry, initial user, membership and audit entry atomically. No default production user or password is seeded; permission seeding is idempotent.
- Business data and user language/theme preferences are stored server-side. Frontend state is transient; local browser storage is not a database or login store.
- Money uses integer minor units and exact decimal strings, with currencies kept separate. Protected or unknown values remain unavailable. Cost/profit/PIN checks apply to resources, reports and full exports as well as pages; provider credentials and encrypted purchase evidence are never exported as report data.

Digital connections can have optional catalog-company metadata. Configuring a real connection does not invent a catalog provider or product. Fourth's sales amount is an open total of succeeded sales, not a remaining balance. Provider balances and settled costs are nullable unless their actual source establishes them. Provider calls and credentials remain in Laravel.

## Local setup

Locked dependencies require **PHP 8.4.1+** and Composer. Use that PHP version for Composer as well as Artisan. The root `scripts/backend.ps1` wrapper selects `MASAL_PHP` or the documented portable runtime under `C:\Users\PRO\.codex\runtimes\masal-php`; it does not modify XAMPP PHP.

For a **new local checkout and an empty local database only**, install dependencies from `composer.lock` in this directory, then run from the repository root:

```powershell
Copy-Item backend/.env.local.example backend/.env
& ./scripts/backend.ps1 artisan key:generate
New-Item -ItemType File -Path backend/database/database.sqlite -ErrorAction SilentlyContinue
& ./scripts/backend.ps1 artisan migrate --seed
npm run backend:serve
```

Use an independently configured local database. Never replace an existing production environment or regenerate its APP_KEY as part of an update. Local portal hosts are `admin.localhost`, `agents.localhost` and `pos.localhost`; the public development entry uses `public.localhost:5177` through Vite's local proxy.

The initial administrator can be created interactively on an approved fresh environment:

```powershell
& ./scripts/backend.ps1 artisan masal:create-admin --login=admin.system --email=your-address@example.com --name=Administrator
```

The password is entered through a hidden prompt, not a command argument or document. Production already has its accounts; deployments do not recreate them or seed test fixtures.

## Production layout and background work

Laravel and vendor live outside the web root:

```text
/home/dananiriq/masal-backend/releases/<release>/
/home/dananiriq/masal-backend/current -> releases/20261007-full-system-07
/home/dananiriq/masal-backend/shared/.env
/home/dananiriq/masal-backend/shared/storage
```

Authenticated Vue outputs are under `public_html/masal/portals/{admin,agents,pos}`, and the public company entry is in `/home/dananiriq/public_html/`. Bridges forward to `current/public/index.php` while preserving hostname and URI. Do not expose source, vendor, .env, databases, backup snapshots or private storage through public roots.

Keep `APP_DEBUG=false`, HTTPS, host-only secure session cookies and the current APP_KEY. Current production `schedule:list` and the existing minute cron with `flock` were checked. `routes/console.php` schedules hourly support-attachment pruning and minute `backups:work` with overlap prevention. Verify actual execution after operational changes; a route or scheduled definition alone does not prove its prerequisite is available.

## Snapshot and restore boundaries

Backups stream signed, encrypted records and referenced private files. They exclude .env, APP_KEY, sessions/auth-device records, runtime caches and backup/write-gate control tables. Uploaded preview is capped at 15 MB; same-server snapshots can stream larger archives. Legacy browser JSON is not an accepted server snapshot.

Preview checks the signed format, schema, references and domain invariants. Password/version-reviewed restoration creates a new scratch database; it never drops or overwrites the current database. Existing financial triggers remain enabled during replay. The worker drains guarded writes through the runtime gate, enters owned maintenance, takes a current safety snapshot, validates the target and performs a controlled environment switch with rollback on failure. Active or unresolved digital purchases block switching; restoring a snapshot does not repeat purchases. Restored sessions are invalidated and presence is offline, with historical location records retained.

`BackupWorkflow` maintains a separate source connection while the active named connection is switched. A cloned configuration sets its own explicit `name` before use; otherwise Laravel can resolve an aliased model back to the newly configured active connection. Source and target terminal control records are saved before the returned model rebinds to the active connection and temporary aliases are purged. Successful switching leaves the old source blocked and reopens only the verified target.

Schema discovery is explicitly limited to the selected MySQL/MariaDB database, or SQLite's `main` schema. Laravel's default discovery must not enumerate other accessible databases. Snapshot schema/driver fingerprints are checked before replay.

`CpanelRestorePlatform` requires canonical private release/environment/storage paths, an explicit local MySQL connection without DB_URL, a correctly prefixed cPanel database/user and file maintenance. Environment rewriting, scratch grants, write-gate ownership and cached fresh-process health are guarded. `BACKUP_PLATFORM_CLASS` defaults to an unavailable implementation; configured environments must meet the actual provider prerequisites. Successful shadow acceptance does not authorize an unreviewed production restore.

## Verification and isolated native acceptance

Run local verification from the repository root:

```powershell
npm run backend:test
& ./scripts/backend.ps1 vendor/bin/pint --dirty --format agent
& ./scripts/backend.ps1 artisan route:list --path=api
```

Final release results:

| Evidence | Result |
| --- | --- |
| Local SQLite | 561 cases: 557 PASS, four genuine SKIP, 5055 assertions. |
| Native MariaDB | 561 cases: 557 PASS, four genuine SKIP, 5057 assertions. |
| Separate cPanel direct restore/rollback shadow | One passing test, 34 assertions. |
| Separate full `backups:work` shadow | One passing test, 68 assertions. |
| Vue and production builds | 154 passing tests; four successful outputs. |

The four skips in the broad suites are not counted as passes; separately enabled shadow results have their own evidence. Artifact identities, native runner records and remaining browser limits belong in the release verification record.

`phpunit.xml` forces testing mode, in-memory SQLite and non-production cache/session settings. `Tests\TestCase` loads `tests/Support/.env.testing` instead of the application's real .env. Native acceptance uses a separately prepared configuration and requires the disposable `dananiriq_masalverify` database; `MASAL_NATIVE_DB_ACCEPTANCE` checks testing mode, the mysql driver and that database identity. These are acceptance guards, not instructions to relabel a production database for testing.

`CpanelRestoreNativeTest` and `CpanelRestoreWorkerNativeTest` are separately enabled only in their exact private Linux CLI shadow at `/home/dananiriq/masal-verification/restore-platform-20261006-07`. They check canonical base/storage/environment paths, database identity both from configuration and `SELECT DATABASE()`, the expected DB user and the explicit shadow marker. Worker acceptance also requires an unblocked gate, no active job and no surrounding database transaction; its committed fixtures belong only to the disposable source.

The full shadow worker test really creates/imports/switches isolated databases. Its final `freshHealth` subprocess is **read-only**: with inherited DB/environment overrides cleared, it boots the cached application anew and verifies actual target database identity, completed job, released gate and maintenance state. This is separate from provider prepare/switch actions and from production QA. Local fake-worker fixtures also purge/reconfigure the active connection and inspect the committed source through an independent alias.

Do not execute the native shadow tests or a restore action against production during browser/HTTP QA. Production data preservation and frontend acceptance have their own checks; a successful build or SQL test does not prove complete original design parity.
