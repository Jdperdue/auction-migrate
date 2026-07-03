# René Bates Migration Project

> Standalone PHP migration tool. No Laravel dependency.
> Lives at /var/www/auction-migrate/ — completely separate
> from the auction platform at /var/www/auction/.
> Archive or delete when migration is verified complete.

---

## Purpose

One-time migration of the René Bates Auctioneers `admin_live` MariaDB
database into the auction platform schema. René Bates becomes the first
operator tenant on the platform.

---

## Source Database

- Host: defined in `.env`
- Database: `admin_live`
- Engine: MariaDB 10.6
- Notable: 300 discrete auction table sets (`001_inventory`,
  `001_lot_activity`, `001_bidder_activity` through `300_*`)
- ~117,000 registered bidders
- ~149,000 payment records

## Target Database

- Host: 127.0.0.1 (local to auction VPS)
- Database: `auction`
- Engine: MariaDB
- Credentials: defined in `.env`

---

## Project Structure

```
/var/www/auction-migrate/
  .env                        -- DB credentials (never commit)
  .env.example                -- template with placeholder values
  run.php                     -- CLI entry point
  README.md                   -- run instructions
  PROJECT.md                  -- this file
  docs/
    migration-renebates.md    -- full field-by-field migration spec
    migration-gap-analysis.md -- source fields with no target landing place
  src/
    Database.php              -- PDO connection factory (source + target)
    Logger.php                -- console + file logging
    MigrationMap.php          -- lot ID source→target mapping helpers
    steps/
      01_Tenant.php
      02_Categories.php
      03_Clients.php
      04_Sellers.php
      05_Bidders.php
      06_BidderTenant.php
      07_BidderDeposits.php
      08_BidderNotes.php
      09_Auctions.php
      10_Lots.php
      11_Bids.php
      12_Finalize.php
  logs/
    migration.log
```

---

## .env Structure

```
# Source database (René Bates)
SOURCE_HOST=
SOURCE_PORT=3306
SOURCE_DB=admin_live
SOURCE_USER=
SOURCE_PASS=

# Target database (auction platform)
TARGET_HOST=127.0.0.1
TARGET_PORT=3306
TARGET_DB=auction
TARGET_USER=auctionuser
TARGET_PASS=

# Migration config
TENANT_ID=          -- populate after step 01 runs
DRY_RUN=false
BATCH_SIZE=500
LOG_FILE=logs/migration.log
```

---

## Run Instructions

```bash
# Dry run — reports counts, inserts nothing
php run.php --dry-run

# Full run
php run.php

# Single step (debug or re-run a failed step)
php run.php --step=10_Lots

# Dry run single step
php run.php --step=03_Clients --dry-run
```

---

## Architecture

### Database.php
Two static PDO factory methods — `source()` and `target()`.
Both use `PDO::ERRMODE_EXCEPTION`.
Each step receives both connections as constructor arguments.

### Logger.php
Writes to both console (stdout) and `LOG_FILE`.
Log format: `[YYYY-MM-DD HH:MM:SS] [STEP] message`
Log levels: INFO, WARN, ERROR, DRY_RUN.

### MigrationMap.php
Manages the `_migration_lot_map` table in the target DB:
```sql
CREATE TABLE _migration_lot_map (
  source_auction INT,
  source_lot_id  INT,
  target_lot_id  BIGINT,
  PRIMARY KEY (source_auction, source_lot_id)
);
```
Exposes `set(auction, sourceLotId, targetLotId)` and
`get(auction, sourceLotId)` methods.
Used by step 11 (Bids) to resolve lot IDs after step 10 (Lots) runs.

### Step files
Each step implements a simple interface:
```php
interface StepInterface {
    public function run(PDO $source, PDO $target, bool $dryRun): StepResult;
}

class StepResult {
    public int $processed;
    public int $inserted;
    public int $skipped;
    public array $errors;
}
```

Steps 01–09 wrap target inserts in a transaction.
Steps 10 (Lots) and 11 (Bids) process in chunks of `BATCH_SIZE`
— too large for a single transaction.

---

## Migration Order

```
01  Tenant         — create René Bates tenant record
02  Categories     — g_categories WHERE cat_type = 'lot'
03  Clients        — c_clients + c_client_contact + lookups
04  Sellers        — c_sellers + c_seller_contact + lookups
05  Bidders        — b_bidders + b_bidder_contact + b_bidder_status
06  BidderTenant   — bidder_tenant pivot
07  BidderDeposits — b_bidder_deposit → accounts + transactions
08  BidderNotes    — b_bidder_notes + g_notes
09  Auctions       — a_workspaces + a_auctions
10  Lots           — iterate 001–300, inventory + lot_activity
11  Bids           — iterate 001–300, bidder_activity (accepted only)
12  Finalize       — set winning_bid_id, verify counts, report
```

---

## Key Rules

1. Always run `--dry-run` first on any new step
2. Never run live against source DB without a current backup
3. `TENANT_ID` in `.env` must be set before steps 03 onwards
4. Steps are idempotent where possible — safe to re-run if a step
   fails partway through, but verify counts after any re-run
5. Do not modify source DB — all writes go to target only
6. Lots cannot preserve source IDs — use `_migration_lot_map`
7. Bidder IDs are preserved — bids reference them directly

---

## Password Migration Decision

Source passwords are MD5 (32-char varchar). Laravel uses bcrypt.
Decision: **Option A — force password reset.**

- Set `bidders.password` to an invalid bcrypt string during migration
- Add `bidders.requires_password_reset (bool default false)` flag
- On first login post-migration, detect flag and redirect to reset flow
- René Bates to send email blast to bidders before go-live

This field (`requires_password_reset`) needs to be added to the
`bidders` migration in `/var/www/auction/` before this migration runs.
Add to backlog if not yet done.

---

## Post-Migration Verification

See `docs/migration-renebates.md` — Post-Migration Verification Queries
section for SQL spot-checks to run after step 12 completes.

---

## Per-Session Instructions for Claude Code

At the start of each Claude Code session:
1. Read this file (PROJECT.md)
2. Read docs/migration-renebates.md
3. Do not run live inserts — use --dry-run until explicitly told otherwise
4. At end of session update a progress note here or in a session log

---

## Session Log

### 2026-07-01 — Initial scaffold and all 12 steps implemented

Full project scaffolded per spec: `run.php`, `src/Database.php`,
`src/Logger.php`, `src/MigrationMap.php`, `src/StepInterface.php`,
`src/StepResult.php`, `src/StepConfig.php`, `src/Env.php`,
`src/Support.php`, and all 12 `src/steps/*.php` files (01_Tenant
through 12_Finalize). `.env.example`, `README.md`, `.gitignore` added.

Target column names/types were verified directly against the live
`auction` DB migrations in `/var/www/auction/database/migrations/`.

**Validation performed:**
- `php -l` on every file — clean.
- Full `--dry-run` pipeline (all 12 steps) run against a synthetic
  throwaway source DB (`admin_live_test`) and the real target schema —
  clean, correct skip/insert reporting.
- Full **live** run against `admin_live_test` → a throwaway clone of
  the target schema (`auction_test`, structure only, no real data) —
  caught and fixed a real bug: step 03 (Clients) inserted into a
  non-existent `clients.phone` column; the real column is
  `clients.contact_phone`. Fixed and re-verified live end to end —
  all 12 steps ran clean, verification counts matched exactly, winning
  bids and lot_images parsing were correct. Both throwaway databases
  were dropped and DB grants restored after testing; no real data
  (source `admin_live` or target `auction`) was touched.

**Known blocker before a real live run:** `bidders.requires_password_reset`
does not exist yet on the target `bidders` table. Step 05 requires it
(see README.md "Prerequisite: target schema"). Must be added via a
Laravel migration in `/var/www/auction/` first. Dry-run works without it.

**Not yet done:** real `SOURCE_*` credentials for the actual René Bates
`admin_live` database have not been configured (no `.env` exists —
only `.env.example`). Nothing has been run against the real source or
target databases.

### 2026-07-01 (later) — Live migration executed against real `admin_live` → real `auction` DB

All 12 steps completed successfully against production data. Final
verification (step 12): `clients` 1936/1936, `lots` 1862/1862, `bids`
8263/8263 all match source counts exactly. `bidders` (104949/104950)
and `auctions` (112/300) mismatches are expected/explained below —
not bugs.

**Bugs found and fixed during the live run:**
- `03_Clients.php` / `05_Bidders.php` — `g_states` has no `abbreviation`
  column, only `state_name` (which already holds 2-letter codes for US
  rows). Fixed both queries to select `gs.state_name AS state`.
- `03_Clients.php` / `04_Sellers.php` — slug uniqueness collisions
  (`tenant_id`+`slug` / `client_id`+`slug`) from duplicate source company
  names ("JDSystems Solutions LLC" x2, "Purchasing" x2 under one client).
  Both steps now append the source ID to the slug on collision.
- `08_BidderNotes.php` — no idempotency guard existed; a mid-pipeline
  rerun (see swap note below) double-inserted 762k notes. Cleaned up the
  duplicate batch (kept the one with correct `author_id` attribution)
  and added a whole-step guard (skip if `bidder_notes` already has rows
  for the tenant) since there's no per-row source ID to check against.
- `09_Auctions.php` — `strtotime('0000-00-00 00:00:00')` returns a huge
  negative timestamp instead of `false`, producing an invalid `opens_at`
  date (`-0001-11-16`) for 2 auctions with a zero-date sentinel
  `end_time`. Now explicitly treated as unknown → `opens_at` stays NULL.
- `10_Lots.php` — two bugs: (1) lot titles >255 chars overflowed
  `lots.title` (varchar 255) — now truncated with the full text preserved
  in `description`; (2) **source `photo_string` is space-separated, not
  comma-separated as `docs/migration-renebates.md` claimed** — every
  multi-image lot was getting all its filenames jammed into one bogus
  `lot_images.path` row. Fixed the split logic and wrote a one-off repair
  script to regenerate `lot_images` for the ~1800 lots already migrated
  before the fix (7027 correct rows after repair, verified every lot has
  ≥1 image). Also added an auction-level idempotency guard via a new
  `MigrationMap::hasAuction()` check, since per-chunk transactions meant
  a rerun would have duplicated already-migrated auctions' lots.
- Docs (`migration-renebates.md`) were simply wrong about the photo
  filename separator — worth fixing if this doc is reused elsewhere.

**Infra:** VPS only had 1.8GB RAM / no swap, which silently OOM-killed
the full 12-step pipeline (no error logged — process just died). Added
a persistent 2GB swap file (`/swapfile`, in `/etc/fstab`), after which
the full run completed without issue.

### 2026-07-02 — Steps 13-15 added: lot photos, client terms templates, client locations

Three gaps identified after the core 12-step migration went live:

1. **Lot photo files were never copied.** The 12-step pipeline only
   migrated `lot_images` DB rows (bare filenames like `17b.jpg`,
   parsed from `inv.photo_string`) — no image files existed on the
   target server, and the stored paths didn't match the target app's
   `Storage::disk('public')` convention (`lots/{lot_id}/{filename}`,
   confirmed via `LotImageController.php` and `LotImageResource.php`
   in `/var/www/auction/`) anyway.
2. **No per-client terms template.** The auction detail page needs
   payment terms, removal terms, and (when the auction has titled
   lots) title transfer instructions, varying by client. No such
   table exists in the source — `c_client_templates` is a historical
   snippet library (2,575/1,099/1,292 header/terms/footer rows), not
   a canonical per-client record.
3. **No lot location table.** Each auction's lots reference a
   numbered location (from a client's participating sites) that must
   show on the lot card and winning-bidder invoice. No such table
   exists in the source either — `a_auctions.locations` has the
   numbered list per auction, and each lot's source `description`
   ends with a trailing "Location N" reference tying it to one.

Added three new steps, continuing the existing numbering rather than
being separate one-off scripts, so everything stays in one pipeline
with the same `--dry-run`/`--step=` conventions:

- `13_LotPhotos` — copies files from `SOURCE_PHOTOS_DIR/bates{NNN}/`
  (confirmed local to this server: `/var/www/admin_live/auctions/`)
  into `TARGET_STORAGE_DIR/lots/{lot_id}/`, case-insensitively (source
  filenames are inconsistently cased across folders even though the
  filesystem is case-sensitive — e.g. `17B.JPG` vs `17b.jpg`).
  `T_`-prefixed thumbnail files are skipped entirely — confirmed via
  code search that the target app has no thumbnail concept anywhere
  (no `thumbnail_path` column, no resize logic, every view just
  CSS-scales the one full-size image).
- `14_ClientTemplates` — seeds `client_terms_templates` per client
  from their most recent source auction with a non-empty `terms`
  blob, splitting on `<u>SECTION:</u>` HTML markers (`IMPORTANT
  PAYMENT INSTRUCTIONS`, `REMOVAL`, `REGARDING PAPERWORK`).
- `15_ClientLocations` — seeds `client_locations` per auction from
  `a_auctions.locations` (single unmarked block if only one location,
  `<u>LOCATION N:</u>`-marked blocks if multiple) and links lots via
  the trailing "Location N" text in their source description, which
  is stripped from the migrated `lots.title`/`description` once
  extracted. Locations are seeded fresh per auction, not deduplicated
  against a client's other auctions by address — user's explicit
  call, to avoid fragile address-text matching.

New target schema (Laravel migrations `2026_07_02_215907` through
`_215909` in `/var/www/auction/database/migrations/`, plus
`ClientTermsTemplate`/`ClientLocation` models and `location()`/
`locations()`/`termsTemplate()` relations on `Lot`/`Client`):
`client_terms_templates` (unique per `client_id`), `client_locations`,
and `lots.location_id`. **Both new tables default `status` to
`needs_review`** — these are seeding functions the user explicitly
described as needing operator/client proofing before real use, not a
source of truth.

**Validation performed:** `php -l` on all new/changed files. Full
dry-run of step 13 against the real source and target DBs (read-only,
no schema dependency) — 7,030 processed, 5,619 would-copy, 1,411
skipped as genuinely missing from the source `bates{NNN}/` folders
(~20%, confirmed by manual inspection — e.g. lot 2393's
`photo_string` references `Pic1/2/3.jpg` but `bates003/` only
contains `1.jpg`/`1A.jpg`; not a bug in the step). Steps 14/15 needed
schema first, so cloned production `auction` → throwaway `auction_test`
(full mysqldump, structure+data) and applied the three new schema
changes there via raw DDL — dry-run then full live run against the
clone: step 14 seeded 80/1936 clients (73 clients actually have
migrated lots; a few more had a terms-bearing auction with 0 lots),
step 15 seeded all 1,862 lots with a resolved `location_id` and zero
lingering "Location N" suffixes in any title/description afterward.
`auction_test` dropped after validation; no real data touched.

**Not yet done:** the Laravel migrations have not been applied to the
real target `auction` DB, and steps 13-15 have not been run live
against real source/target data — awaiting confirmation before
touching production schema/files, per the same "backup + dry-run
first" discipline as the original 12 steps.

**Data note (not a bug):** 188/300 source auction workspaces have
`client_id IS NULL` (and `seller_id IS NULL`) — legacy/orphaned
workspaces, all `status = 'closed'`, all with **0 lots** in their
`{NNN}_inventory` tables. Confirmed harmless to skip (target
`auctions.client_id` is `NOT NULL`). Similarly 1 bidder (id=60107) has
no `b_bidder_contact` row and is correctly skipped everywhere.

**Users bootstrap:** target `users` table was empty (source `admin_live`
had no user table) but required for `created_by`/`recorded_by`/`author_id`
FKs. Created:
- id=1 — J David Perdue (jdavidperdue@me.com), `operator_admin`,
  tenant_id=1 — used as `MIGRATION_SYSTEM_USER_ID` in `.env`.
- id=2 — admin@jdss.xyz, `platform_admin`, tenant_id=NULL.
- id=3 — renebates@jdss.xyz, `operator_admin`, tenant_id=1.

Backfilled `bidder_deposit_transactions.recorded_by` (37,643 rows) to
user id=1 after the fact (steps 07/08 initially ran with
`MIGRATION_SYSTEM_USER_ID` unset, before the users table existed).
`bidder_notes.author_id` for non-flagged notes was left NULL by user
choice — no reliable key exists to re-derive `admin`/`auction`-authored
notes after the fact.

**Domain/SSL/deploy (in `/var/www/auction`, not this repo):**
- Added `tenant_domains` row: `renebates.jdss.xyz` → tenant 1, verified.
- Installed `certbot` + `python3-certbot-nginx`. Issued a wildcard
  Let's Encrypt cert for `jdss.xyz` + `*.jdss.xyz` via manual DNS-01
  (registrar has no certbot plugin support) — covers `admin.jdss.xyz`,
  `api.jdss.xyz`, and all tenant subdomains in one cert. **Expires
  2026-09-29, does not auto-renew** (manual mode) — needs the same
  manual TXT-record dance repeated before then.
- Added HTTPS server blocks + HTTP→HTTPS redirects to
  `/etc/nginx/sites-available/auction_admin` and `auction_api`.
- Fixed a frontend bug (platform-wide, not migration-specific):
  `frontend-operator/.env` had `VITE_API_BASE_URL` hardcoded to a dev
  value (`http://demo.jdss.xyz/...`), which broke login on every
  tenant's operator portal once HTTPS was enabled (mixed-content
  block). Moved the override to `.env.development` (dev-only, per the
  code's own comment), removed it from the production `.env`, renamed
  `.env.example` → `.env.development.example`, rebuilt the SPA.

**Verified working end-to-end:** login at `https://renebates.jdss.xyz`
as `renebates@jdss.xyz` returns a valid token and the operator portal
loads correctly.

**Remaining / optional follow-ups:**
- Drop `_migration_lot_map` once fully satisfied with spot-checks (Key
  Rule / migration-renebates.md step 14) — not yet done, still useful
  for traceability.
- Cert renewal before 2026-09-29 (manual DNS-01 repeat).
- Platform admin login (`admin.jdss.xyz`, `admin@jdss.xyz`) has not
  been smoke-tested yet, only the tenant/operator login.
