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

# Full run (all 20 steps in order)
php run.php

# Single step (debug or re-run a failed step)
php run.php --step=10_Lots

# Dry run single step
php run.php --step=03_Clients --dry-run
```

Steps 13-20 are a separate follow-on pass, run after the core 01-12
pipeline has completed against real data:

- `13_LotPhotos` — copies lot image files from the local René Bates
  photo folders (`SOURCE_PHOTOS_DIR/bates{NNN}/`) into the target
  app's public storage disk and rewrites `lot_images.path`
  accordingly. About 20% of source filenames don't resolve to an
  actual file on disk (renamed/deleted on the source side) — these
  are logged and skipped, not treated as errors. Ends with a coverage
  validation pass that reports the count of distinct lots with **zero**
  working photos (not just a flat skipped-file count) — that's the
  actionable number, since a lot missing 1 of 6 photos is fine but
  missing all 6 isn't.
- `14_ClientTemplates` — seeds `client_terms_templates` (payment
  terms, removal terms, title transfer instructions) per client by
  inferring from that client's most recent source auction with a
  non-empty terms blob. Only clients with a usable source auction get
  a row; most of the 300 source auction slots have no real history.
- `15_ClientLocations` — seeds `client_locations` from each auction's
  numbered location list and links each lot to the correct location
  via a trailing "Location N" reference in the source description
  (stripped from the migrated title/description once extracted).
- `16_ClientLogos` — copies client logo files from the flat René Bates
  logo directory (`SOURCE_LOGOS_DIR/logo_{client_id:%06d}.jpg`) into
  the target app's public storage disk and sets `clients.logo_path`.
  Client IDs are preserved 1:1 from `c_clients.ID` (step 03), so this
  is a direct existence check per client — no ID mapping needed.
  Roughly half of clients have no source logo file; those are skipped,
  not treated as errors (the app falls back to a platform default
  logo). Clients that already have a `logo_path` set (e.g. an operator
  uploaded one through the app) are left untouched — this step never
  overwrites an existing logo.
- `17_TaxJurisdictions` — seeds `tax_jurisdictions` from the source's
  `g_tax_classes` lookup table (one row per distinct class) and sets
  `lots.tax_jurisdiction_id` from each lot's `tax_class_id`. A clean
  FK-for-FK swap — no inference involved.
- `18_Consignors` — migrates `c_consignors` into `consignors`,
  preserving source IDs 1:1 (same shape as step 03 Clients), and sets
  `lots.consignor_id` from each lot's `consignor_id`.
- `19_ClientCommissions` — sets `clients.commission_rate` and
  `commission_cap` directly from `c_client_data.comm_class_id1` (via
  `g_commission_classes`) and `c_client_data.comm_cap` — one row per
  client, 0 orphans against the lookup table. A clean FK-for-FK-plus-
  column copy, not an inference: `c_client_data` was found 2026-07-27
  and has an authoritative per-client commission field, superseding
  the original mode-across-historical-lots approach (which had been
  wrong for 58/1936 clients). `comm_class_id2` (a secondary class,
  populated for only 23/1936 clients) has no equivalent second-rate
  field on `clients` and is left unused. `commission_cap` is only
  populated for 3/1936 clients in the source; the rest are left NULL.
- `20_LotVehicleDetails` — parses `{NNN}_inventory.description` for
  vehicle attributes (make, model, year, VIN, mileage, fuel type,
  transmission) and seeds `lot_vehicle_details`. Gated on finding a
  VIN-shaped token in the text, so livestock/equipment/furniture lots
  are left alone rather than mis-parsed. `title_status` comes from
  `disclosure_id` -> `a_disclosures`, not the free text, since that's
  the more reliable of the two sources; `lots.requires_title` is also
  set when the resolved disclosure implies a real title changes hands.
- `21_ClientTaxJurisdictions` — sets `clients.tax_jurisdiction_id`
  directly from `c_client_data.tax_class_id` (via `g_tax_classes`,
  matched by name to the `tax_jurisdictions` rows step 17 already
  seeded) — one row per client, 0 orphans against the lookup table.
  A clean FK-for-FK swap, not an inference: `c_client_data` was found
  2026-07-27 and has an authoritative per-client tax field, superseding
  the original mode-across-lots approach (which had been wrong for
  42/1936 clients and left 1857 NULL for clients with no lots to infer
  from). Runs after step 17 (`tax_jurisdictions` must already be
  seeded); no longer depends on lots/auctions.

**Steps 14 and 15 are seeding functions, not sources of truth** —
they insert rows with `status = 'needs_review'`; an operator must
proof each client's template and locations before they're used for
real auctions. Steps 16-18, 19, 20, and 21 copy or resolve real source
data 1:1 (or gate strictly enough that a written row is trustworthy),
so there's nothing to review beyond the normal skip warnings in the
log. (19 and 21 were inferred defaults until 2026-07-27, when
`c_client_data` — an authoritative per-client commission/tax table —
was found; both were rewritten to copy it directly instead.)

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
be set in `.env` (see `.env.example`). Step 16 requires
`SOURCE_LOGOS_DIR` and `TARGET_STORAGE_DIR`. Step 16 has no schema
prerequisite — `clients.logo_path` already exists on the target.

Steps 17-20 have no schema prerequisite beyond what's already on the
live target — `tax_jurisdictions`, `consignors`,
`clients.commission_rate`/`commission_cap`, `lot_vehicle_details`, and
`lots.tax_jurisdiction_id`/`consignor_id`/`requires_title` all already
exist from the app's own Laravel migrations. No `.env` additions
either; they reuse `SOURCE_*`/`TARGET_*` and `TENANT_ID`.

## Refreshing with current data

Source data (`admin_live`) ages out quickly, so tenant 1 (René Bates)
gets fully wiped and re-migrated from whatever is currently in
`admin_live` whenever it needs to be demo-fresh — this is a standing,
repeatable operation, not a one-off. The source filesystem/DB are
assumed already current before a refresh is requested; syncing them is
outside this tool.

```bash
# Preview what a reset would remove — deletes nothing
php reset.php

# Wipe tenant 1's migrated content, then re-import from current admin_live
php reset.php --confirm
php run.php
```

`reset.php` only touches tenant-scoped content (clients, sellers,
bidders, auctions, lots, bids, and everything cascading from them,
plus their storage files under `clients/{id}/` and `lots/{id}/`). It
never touches `tenants`, `tenant_domains`, `users`, or `categories` —
those are platform config and staff logins, not migrated content.
Since clients/sellers/bidders/auctions preserve source IDs 1:1, and
lots/bids get fresh IDs through a freshly-emptied
`_migration_lot_map`, `run.php` after a reset reproduces a full clean
import with no leftover state.

## Verification

After step 12 completes, run the SQL spot-checks in
`docs/migration-renebates.md` under "Post-Migration Verification
Queries".
