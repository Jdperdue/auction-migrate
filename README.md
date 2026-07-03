# René Bates Migration Tool

Standalone PHP migration tool. No Laravel dependency. Migrates the
René Bates Auctioneers `admin_live` MariaDB database into the auction
platform schema as the first operator tenant.

See `PROJECT.md` and `docs/migration-renebates.md` for the full spec.

## Setup

No Composer dependencies — plain PHP, PDO only.

```bash
cd /var/www/auction-migrate
cp .env.example .env
nano .env           # fill in SOURCE_* and TARGET_* credentials
```

Run steps 01 through 09 first, then set `TENANT_ID` in `.env` to the
tenant ID captured from step 01's output before running step 03 onward
(steps 03+ require `TENANT_ID` to be set).

## Running

```bash
# Dry run — reports counts, inserts nothing
php run.php --dry-run

# Full run (all 15 steps in order)
php run.php

# Single step (debug or re-run a failed step)
php run.php --step=10_Lots

# Dry run single step
php run.php --step=03_Clients --dry-run
```

Steps 13-15 are a separate follow-on pass, run after the core 01-12
pipeline has completed against real data:

- `13_LotPhotos` — copies lot image files from the local René Bates
  photo folders (`SOURCE_PHOTOS_DIR/bates{NNN}/`) into the target
  app's public storage disk and rewrites `lot_images.path`
  accordingly. About 20% of source filenames don't resolve to an
  actual file on disk (renamed/deleted on the source side) — these
  are logged and skipped, not treated as errors.
- `14_ClientTemplates` — seeds `client_terms_templates` (payment
  terms, removal terms, title transfer instructions) per client by
  inferring from that client's most recent source auction with a
  non-empty terms blob. Only clients with a usable source auction get
  a row; most of the 300 source auction slots have no real history.
- `15_ClientLocations` — seeds `client_locations` from each auction's
  numbered location list and links each lot to the correct location
  via a trailing "Location N" reference in the source description
  (stripped from the migrated title/description once extracted).

**All three are seeding functions, not sources of truth** — every row
they insert has `status = 'needs_review'`. An operator must proof each
client's template and locations before they're used on new auctions.

## Key rules

1. Always run `--dry-run` first on any new step.
2. Never run live against the source DB without a current backup of
   the target DB.
3. `TENANT_ID` in `.env` must be set (from step 01's output) before
   running steps 03 onward.
4. Steps are idempotent where possible — safe to re-run if a step
   fails partway through, but verify counts after any re-run.
5. Source DB is never written to — all writes go to the target only.
6. Lot IDs cannot be preserved (300 auction tables collide on
   auto-increment) — resolved via the `_migration_lot_map` table.
   Drop that table only after step 12 (Finalize) and verification
   queries pass.

## Prerequisite: target schema

Step 05 (Bidders) sets `bidders.requires_password_reset`. As of this
writing that column does not yet exist on the target `bidders` table —
it must be added via a Laravel migration in `/var/www/auction/` before
step 05 can run live. Dry runs work without it since no writes occur.

Steps 14 and 15 require the `client_terms_templates` and
`client_locations` tables and `lots.location_id`, added via the
Laravel migrations dated `2026_07_02_215907`-`2026_07_02_215909` in
`/var/www/auction/database/migrations/`. Run
`php artisan migrate` in `/var/www/auction/` before running steps 14
or 15 live. Dry runs of steps 14/15 also require these to exist,
since they query the tables to check idempotency. Step 13 has no
schema prerequisite beyond the existing `lot_images`/
`_migration_lot_map` tables.

Step 13 also requires `SOURCE_PHOTOS_DIR` and `TARGET_STORAGE_DIR` to
be set in `.env` (see `.env.example`).

## Verification

After step 12 completes, run the SQL spot-checks in
`docs/migration-renebates.md` under "Post-Migration Verification
Queries".
