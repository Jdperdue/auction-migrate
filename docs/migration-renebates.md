# Data Migration — René Bates → Auction Platform

## Overview

Migrates the René Bates `admin_live` database into the auction platform
schema under a single tenant (René Bates operator record). This is a
one-time historical migration, not an ongoing sync.

The source schema uses a pattern where each auction (workspace) gets three
discrete tables: `{NNN}_inventory`, `{NNN}_lot_activity`, and
`{NNN}_bidder_activity`. The source database contains 300 auction table
sets (001–300), though not all will have data.

---

## Source Schema Summary

### Auction structure
- `a_workspaces` — one row per auction, holds client_id, seller_id, status
- `a_auctions` — auction metadata (title, terms, end_time, soft close config)
- `a_staggered_ends` — stagger templates (num_lots, num_minutes)
- `{NNN}_inventory` — lots for auction NNN
- `{NNN}_lot_activity` — per-lot closing state for auction NNN
- `{NNN}_bidder_activity` — bid records for auction NNN

### Client/seller structure
- `c_clients` — client identity record
- `c_client_contact` — client contact info (normalized — email/phone/zip via FK)
- `c_sellers` — seller subdivision of client
- `c_seller_contact` — seller contact info
- `c_consignors` — consignor (subdivision of client, maps to inventory)

### Bidder structure
- `b_bidders` — core identity + auth
- `b_bidder_contact` — bidder contact info (same normalized pattern)
- `b_bidder_status` — status enum + activation flag
- `b_bidder_deposit` — deposit record (single row per bidder)
- `b_bidder_notes` — pivot to `g_notes`

### Lookup tables (normalized contact data)
- `g_emails` — email address lookup by ID
- `g_phones` — phone number lookup by ID
- `g_zip_codes` — zip → city lookup
- `g_cities` — city → state lookup
- `g_states` — state lookup
- `g_notes` — shared notes text store
- `g_categories` — shared category list

### Invoices
- `a_invoices` — per auction per bidder invoice totals

---

## Target Schema Mapping

### 1. Tenant (one record — René Bates)

```
tenants:
  name        = "René Bates Auctioneers"
  slug        = "renebates"
  email       = [from operator]
  status      = active
```

Record the generated `tenant_id` — used as FK on all migrated records.

---

### 2. Categories

```
SOURCE: g_categories WHERE cat_type = 'lot'
TARGET: categories

g_categories.ID              → categories.id (preserve)
g_categories.cat_description → categories.name
                             → categories.slug (slugify cat_description)
                               categories.is_active = true
                               categories.sort_order = sequential
```

Note: `g_categories` has `cat_type` enum ('lot','geo','spc'). Only
`cat_type = 'lot'` maps to auction categories. Skip geo and spc.

---

### 3. Clients

```
SOURCE: c_clients
        JOIN c_client_contact ON c_client_contact.client_id = c_clients.ID
        JOIN g_emails ON g_emails.ID = c_client_contact.email_id
        JOIN g_phones work ON work.ID = c_client_contact.work_phone_id
        LEFT JOIN g_phones cell ON cell.ID = c_client_contact.cell_phone_id
        JOIN g_zip_codes ON g_zip_codes.ID = c_client_contact.m_zip_id
        JOIN g_cities ON g_cities.ID = g_zip_codes.city_id
        JOIN g_states ON g_states.ID = g_cities.state_id
TARGET: clients

c_clients.ID                    → clients.id (preserve)
c_client_contact.company        → clients.name
                                → clients.slug (slugify company)
c_client_contact.email (joined) → clients.contact_email
c_client_contact.full_name      → clients.contact_name
work.number (joined)            → clients.phone
c_client_contact.m_address1     → clients.address
g_zip_codes.zip_code (joined)   → clients.zip
g_cities.city_name (joined)     → clients.city
g_states.abbreviation (joined)  → clients.state
                                   clients.tenant_id = {renebates_tenant_id}
                                   clients.is_self_serve = false
                                   clients.status = active

Logo:
  No DB column stores the logo reference — the source app derives the
  path from the client ID at request time (admin/clients.php,
  admin/client/rmlogo.php): SOURCE_LOGOS_DIR/logo_{client_id:%06d}.jpg
  (confirmed on-disk at /var/www/logos/, flat directory, 1,042 files
  against 1,936 c_clients rows — most clients have none, matching this
  app's fallback-icon design). Since clients.id is preserved 1:1 from
  c_clients.ID (no remapping, unlike lots), the copy is a direct
  existence check per client — no source→target ID map needed:
    for each client: if SOURCE_LOGOS_DIR/logo_{id:%06d}.jpg exists,
    copy to TARGET_STORAGE_DIR/clients/{id}/logo.jpg and set
    clients.logo_path = "clients/{id}/logo.jpg"; otherwise leave
    logo_path null (app-side accessor falls back to the platform
    auctioneer-figure icon).
  Implemented as migration step 16_ClientLogos, after 03_Clients so
  client rows exist to check against.
```

---

### 4. Sellers

```
SOURCE: c_sellers
        JOIN c_seller_contact ON c_seller_contact.seller_id = c_sellers.ID
        JOIN g_emails ON g_emails.ID = c_seller_contact.email_id
        JOIN g_phones work ON work.ID = c_seller_contact.work_phone_id
        JOIN g_zip_codes ON g_zip_codes.ID = c_seller_contact.zip_id
        JOIN g_cities ON g_cities.ID = g_zip_codes.city_id
        JOIN g_states ON g_states.ID = g_cities.state_id
TARGET: sellers

c_sellers.ID                    → sellers.id (preserve)
c_sellers.client_id             → sellers.client_id
c_seller_contact.description    → sellers.name
                                → sellers.slug (slugify description)
c_seller_contact.email (joined) → sellers.contact_email
c_seller_contact.full_name      → sellers.contact_name
work.number (joined)            → sellers.phone
                                   sellers.tenant_id = {renebates_tenant_id}
                                   sellers.status = active
```

---

### 5. Auctions

```
SOURCE: a_workspaces
        JOIN a_auctions ON a_auctions.workspace_id = a_workspaces.ID
        LEFT JOIN a_staggered_ends ON
          a_staggered_ends.ID = a_auctions.staggered_end_id

TARGET: auctions

a_workspaces.ID                 → auctions.id (preserve)
a_workspaces.client_id          → auctions.client_id
a_workspaces.seller_id          → auctions.seller_id (nullable)
a_auctions.title                → auctions.title
a_auctions.highlights           → auctions.description
a_auctions.notes                → auctions.preview_text
a_auctions.terms / tandc        → auctions.terms (concatenate if both present)
a_auctions.end_time             → auctions.opens_at (approximate — source
                                  stores end time not open time; use
                                  end_time - 14 days as opens_at estimate,
                                  or NULL for historical auctions)
a_auctions.auto_extend_time     → (informs lots.soft_close_minutes)
                                   auctions.tenant_id = {renebates_tenant_id}
                                   auctions.buyer_premium_percent = 10.00
                                   (René Bates standard — confirm with client)

Status mapping:
  a_workspaces.status:
    'open'    → auctions.status = open
    'ending'  → auctions.status = closing
    'ended'   → auctions.status = closed
    'listed'  → auctions.status = approved
    'pending' → auctions.status = pending_approval
    'closed'  → auctions.status = closed
```

---

### 6. Lots

```
SOURCE: {NNN}_inventory inv
        JOIN {NNN}_lot_activity la ON la.inventory_id = inv.ID
        -- iterate all 300 auction table sets

TARGET: lots

inv.ID                          → lots.id (WARNING: IDs will collide across
                                  auction tables — must remap to new IDs,
                                  maintain a source→target ID map for bid
                                  migration)
{NNN} (table prefix)            → lots.auction_id (the workspace ID)
inv.lot_number                  → lots.lot_number
inv.description                 → lots.title
inv.additional_desc             → lots.description
inv.primary_category_id         → lots.category_id
inv.min_bid                     → lots.starting_bid
inv.item_reserve                → lots.reserve_price (0.00 = null — convert)
la.end_time                     → lots.closes_at
la.current_price                → lots.current_bid
la.current_winner_id            → lots.current_bidder_id
la.bid_count                    → lots.bid_count
la.is_extended                  → (if > 0, set extended_closes_at = closes_at)
inv.premium                     → (if non-zero, overrides auction premium —
                                  store as note or ignore if matches standard)
                                   lots.tenant_id = {renebates_tenant_id}
                                   lots.soft_close_enabled = true
                                   lots.soft_close_minutes =
                                     a_auctions.auto_extend_time for this
                                     auction (default 5)

Status mapping (derive from auction status + lot_activity):
  If la.is_halted = 1         → lots.status = pending
  If la.current_winner_id     → lots.status = closed
  If auction.status = closed  → lots.status = closed
  Otherwise                   → lots.status = open

Images:
  inv.photo_string contains space-separated image filenames (not
  comma-separated). Parse and create lot_images records.
  Image files are copied by step 13 (LotPhotos) from the local
  SOURCE_PHOTOS_DIR/bates{NNN}/ folders into the target app's public
  disk at TARGET_STORAGE_DIR/lots/{lot_id}/{filename}, with
  lot_images.path rewritten to "lots/{lot_id}/{filename}" to match
  Storage::disk('public') conventions. T_{filename} thumbnails are
  never copied — the target app has no thumbnail concept.
  is_primary = true for first image in photo_string.
```

**ID collision note:** `001_inventory` and `002_inventory` both start
auto_increment at 1. You cannot preserve source IDs on lots. The migration
must assign new auto-increment IDs and maintain a mapping table:

```sql
CREATE TABLE _migration_lot_map (
  source_auction INT,
  source_lot_id INT,
  target_lot_id BIGINT,
  PRIMARY KEY (source_auction, source_lot_id)
);
```

---

### 7. Bids

```
SOURCE: {NNN}_bidder_activity WHERE type = 'bid' AND is_accepted = 1

TARGET: bids

ba.ID                           → (new ID — do not preserve)
ba.inventory_id + auction NNN   → bids.lot_id (via _migration_lot_map)
{NNN}                           → bids.auction_id
ba.bidder_id                    → bids.bidder_id
ba.standard_bid                 → bids.amount
ba.time                         → bids.created_at
ba.ip_address                   → bids.ip_address (stored as int in source —
                                  convert with INET_NTOA())
                                   bids.tenant_id = {renebates_tenant_id}
                                   bids.is_winning = false (set after insert)

After all bids inserted for a lot:
  UPDATE bids SET is_winning = true
  WHERE id = (SELECT id FROM bids WHERE lot_id = X
              ORDER BY amount DESC LIMIT 1)
```

Note: `ba.type = 'attempt'` rows are failed/rejected bids — skip them.
`ba.is_removed = 1` rows are retracted bids — skip or flag.

---

### 8. Bidders

```
SOURCE: b_bidders bb
        JOIN b_bidder_contact bc ON bc.bidder_id = bb.ID
        JOIN g_emails ON g_emails.ID = bc.email_id
        JOIN g_phones work ON work.ID = bc.work_phone_id
        LEFT JOIN g_phones cell ON cell.ID = bc.cell_phone_id
        JOIN g_zip_codes ON g_zip_codes.ID = bc.m_zip_id
        JOIN g_cities ON g_cities.ID = g_zip_codes.city_id
        JOIN g_states ON g_states.ID = g_cities.state_id
        LEFT JOIN b_bidder_status bs ON bs.bidder_id = bb.ID

TARGET: bidders + bidder_tenant

bb.ID                           → bidders.id (preserve — referenced by bids)
bc.full_name                    → bidders.first_name + last_name (split on
                                  first space)
g_emails.address (joined)       → bidders.email
work.number (joined)            → bidders.phone
bc.m_address1                   → bidders.address
g_zip_codes.zip_code (joined)   → bidders.zip
g_cities.city_name (joined)     → bidders.city
g_states.abbreviation (joined)  → bidders.state
bb.created                      → bidders.created_at
bb.password                     → bidders.password (source is MD5 — CANNOT
                                  be used with Laravel's bcrypt. Set a
                                  temporary random password and force reset
                                  on first login, OR store raw and add a
                                  legacy_password_hash field + custom auth
                                  guard. See note below.)
                                   bidders.tenant_id = {renebates_tenant_id}
                                   bidders.email_verified_at = bb.created
                                   (treat all existing bidders as verified)
                                   bidders.terms_accepted_at = bb.created

Status mapping (b_bidder_status.status):
  'active'   → bidders.status = active
  'inactive' → bidders.status = inactive
  'limited'  → bidders.status = active (with note)
  'banned'   → bidders.status = suspended

bidder_tenant pivot:
  bidder_id = bb.ID
  tenant_id = {renebates_tenant_id}
  status    = mapped from b_bidder_status
  approved_at = bb.created
```

**Password migration note:** René Bates passwords are MD5 hashed (32-char
varchar). Laravel uses bcrypt. Two options:

- **Option A (recommended):** Force password reset. Set `password = null`
  (or a known invalid hash), add `requires_password_reset = true` flag to
  `bidders` table, implement a "first login since migration" flow that
  redirects to password reset before allowing access.

- **Option B:** Add `legacy_md5_password` column to `bidders`, write a
  custom auth guard that checks bcrypt first, then falls back to MD5
  verification and re-hashes on success. More complex but seamless for
  bidders.

Option A is cleaner and more secure. Recommend an email blast to all
bidders before go-live explaining the migration.

---

### 9. Bidder Deposits

```
SOURCE: b_bidder_deposit
TARGET: bidder_deposit_accounts + bidder_deposit_transactions

One account per bidder:
  bidder_id       → bidder_deposit_accounts.bidder_id
  amount_paid     → bidder_deposit_accounts.balance
  tenant_id       = {renebates_tenant_id}
  status          = active
  credit_limit    = 0
  exposure_limit  = amount_paid (balance + credit_limit)

One transaction per non-zero deposit:
  bidder_id       → recorded against the account
  amount_paid     → bidder_deposit_transactions.amount
  type            = deposit
  payment_type    → bidder_deposit_transactions.reference
  date_paid       → bidder_deposit_transactions.created_at
  recorded_by     = migration system user ID
```

---

### 10. Bidder Notes

```
SOURCE: b_bidder_notes bn
        JOIN g_notes gn ON gn.ID = bn.note_id

TARGET: bidder_notes

gn.note         → bidder_notes.note
bn.bidder_id    → bidder_notes.bidder_id
gn.created      → bidder_notes.created_at
gn.creator      → (if 'admin' or 'auction' → author_id = migration system user)
                   bidder_notes.tenant_id = {renebates_tenant_id}
                   bidder_notes.is_flagged = false
```

---

### 11. Schema drift additions (steps 17-20)

Added 2026-07-24 to close gaps opened by Laravel migrations that landed
on the target after this doc and the core 01-12 pipeline were written
(see `docs/migration-gap-analysis.md` for the CLOSED entries). Steps
17, 18, and 20 run after step 10 (Lots), resolving target lot IDs via
`_migration_lot_map` the same way steps 13/15 do. Steps 19 and 21
(added/rewritten 2026-07-27 once `c_client_data` was found) only touch
`clients` and have no lot dependency — they just need step 03
(Clients) and, for 21, step 17 (`tax_jurisdictions` seeded).

```
Tax Jurisdictions (step 17_TaxJurisdictions):
  g_tax_classes.ID, tax_description, tax_rate
    → tax_jurisdictions (one row per distinct class, tenant-scoped)
  {NNN}_inventory.tax_class_id → lots.tax_jurisdiction_id
  Clean FK-for-FK swap, no inference.

Consignors (step 18_Consignors):
  c_consignors.ID                 → consignors.id (preserve, like clients)
  c_consignors.client_id          → consignors.client_id
  c_consignors.description        → consignors.name
  {NNN}_inventory.consignor_id    → lots.consignor_id

Client Commission (step 19_ClientCommissions):
  c_client_data.comm_class_id1 → g_commission_classes.com_rate × 100
    → clients.commission_rate (authoritative, one row per client)
  c_client_data.comm_cap → clients.commission_cap (populated for
    3/1936 clients in the source; the rest left NULL)
  c_client_data.comm_class_id2 (secondary class, 23/1936 clients) has
    no equivalent field on `clients` — unused.
  (Before c_client_data was found on 2026-07-27, this step inferred a
  default from mode({NNN}_inventory.comm_class_id across a client's
  lots) — wrong for 58/1936 clients vs. the real data. c_indiv_commissions,
  keyed by a free-text recipient name with no client FK, is still unused.)

Lot Vehicle Details (step 20_LotVehicleDetails):
  {NNN}_inventory.description, gated on a VIN-shaped token
    → lot_vehicle_details.make/model/model_year/mileage/fuel_type/
      transmission/identification_number (marker-split on ";")
  {NNN}_inventory.disclosure_id → a_disclosures.disc_title (normalized)
    → lot_vehicle_details.title_status (more reliable than the free
      text for this one field)
    → lots.requires_title = true when the disclosure implies a real
      title changes hands

Client Tax Jurisdiction (step 21_ClientTaxJurisdictions):
  c_client_data.tax_class_id → g_tax_classes.tax_description
    → tax_jurisdictions.name (tenant-scoped, matched to the rows step
      17 seeds) → clients.tax_jurisdiction_id
  Clean FK-for-FK swap, one row per client, no inference.
  (Before c_client_data was found on 2026-07-27, this step inferred a
  default from mode(lots.tax_jurisdiction_id across a client's own
  lots, via auctions.client_id) — wrong for 42/1936 clients and left
  1857 NULL for clients with no lots to infer from.)
```

---

## Migration Order (dependency sequence)

```
1.  Create tenant record → capture tenant_id
2.  Migrate categories (g_categories WHERE cat_type='lot')
3.  Migrate clients (c_clients + c_client_contact + lookups)
4.  Migrate sellers (c_sellers + c_seller_contact + lookups)
5.  Migrate bidders (b_bidders + b_bidder_contact + b_bidder_status + lookups)
6.  Migrate bidder_tenant pivot
7.  Migrate bidder deposits (b_bidder_deposit → accounts + transactions)
8.  Migrate bidder notes (b_bidder_notes + g_notes)
9.  Migrate auctions (a_workspaces + a_auctions) — 300 sets
10. Migrate lots — iterate NNN 001–300:
      a. Check if {NNN}_inventory exists and has rows
      b. Insert lots, capture new IDs into _migration_lot_map
      c. Parse photo_string → lot_images records
11. Migrate bids — iterate NNN 001–300:
      a. Insert accepted bids using _migration_lot_map for lot_id
      b. Set is_winning on highest bid per lot
12. Update lots.winning_bid_id from bids.id where is_winning = true
13. Verify counts and spot-check data
14. Drop _migration_lot_map when satisfied
```

---

## Implementation Approach

Standalone PHP project — completely separate from `/var/www/auction/`.
No Laravel dependency. Plain PHP with two PDO connections.
Archive or delete when migration is verified complete.

**Location:** `/var/www/auction-migrate/`

---

## Project Structure

```
/var/www/auction-migrate/
  .env                        -- DB credentials (never commit)
  .env.example                -- template with placeholder values
  run.php                     -- entry point / CLI runner
  README.md                   -- run instructions
  src/
    Database.php              -- PDO connection factory (source + target)
    Logger.php                -- console + file logging
    MigrationMap.php          -- lot ID source→target mapping helpers
    steps/
      01_Tenant.php           -- create René Bates tenant record
      02_Categories.php       -- migrate g_categories (lot type only)
      03_Clients.php          -- migrate c_clients + contact + lookups
      04_Sellers.php          -- migrate c_sellers + contact + lookups
      05_Bidders.php          -- migrate b_bidders + contact + status
      06_BidderTenant.php     -- bidder_tenant pivot records
      07_BidderDeposits.php   -- deposit accounts + transactions
      08_BidderNotes.php      -- bidder notes
      09_Auctions.php         -- migrate a_workspaces + a_auctions
      10_Lots.php             -- iterate 001–300, migrate inventory + lot_activity
      11_Bids.php             -- iterate 001–300, migrate bidder_activity
      12_Finalize.php         -- set winning_bid_id, verify counts
  logs/
    migration.log             -- full run log
```

---

## Running the Migration

```bash
cd /var/www/auction-migrate

# Copy and configure credentials
cp .env.example .env
nano .env

# Dry run first — reports counts, inserts nothing
php run.php --dry-run

# Full run
php run.php

# Run a single step (for debugging/re-running a failed step)
php run.php --step=10_Lots
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
TENANT_ID=          -- set after step 01 creates the tenant record
DRY_RUN=false
BATCH_SIZE=500      -- chunk size for lots and bids
LOG_FILE=logs/migration.log
```

---

## Database.php — Connection Pattern

```php
class Database {
    public static function source(): PDO {
        return new PDO(
            "mysql:host={$_ENV['SOURCE_HOST']};dbname={$_ENV['SOURCE_DB']}",
            $_ENV['SOURCE_USER'],
            $_ENV['SOURCE_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public static function target(): PDO {
        return new PDO(
            "mysql:host={$_ENV['TARGET_HOST']};dbname={$_ENV['TARGET_DB']}",
            $_ENV['TARGET_USER'],
            $_ENV['TARGET_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
```

Each step receives both connections. Lots and bids processed in chunks
of `BATCH_SIZE` — not a single transaction. All other steps wrapped in
a target-side transaction.

---

## What Is NOT Migrated

- `a_bidding_increments` — migrate manually as bid increment tier sets
  via the operator UI (small number of rows, better to configure fresh)
- `a_invoices` — historical invoice totals; not needed for platform operation
- `m_*` tables — mailing list / marketing data; not part of auction platform
- `g_admin_users` — operator staff accounts; recreate via platform UI
- `c_contracts` — internal CRM data; no target schema
  (`c_client_data` was originally listed here too, but it was found on
  2026-07-27 to hold authoritative per-client `tax_class_id` and
  `comm_class_id1`/`comm_cap` — see steps 19 and 21 above)
- `b_bidder_dl` — driver's license data; sensitive, no target schema
- `b_bidder_tax` — tax exemption data; no target schema in v1
- `a_archive_*` — archived search/category data; not needed
- `g_announcements`, `g_pages`, `g_faqs` — CMS content; not part of platform

---

## Pre-Migration Checklist

- [ ] Confirm buyer_premium_percent with René Bates (assumed 10%)
- [ ] Confirm password reset strategy with René Bates (Option A recommended)
- [ ] Confirm image file location on René Bates server
- [ ] Confirm image copy/rsync plan to new server
- [ ] Take full backup of auction platform DB before running
- [ ] Test dry-run against source DB first
- [ ] Confirm with René Bates which auctions are active vs historical

---

## Post-Migration Verification Queries

```sql
-- Count check
SELECT COUNT(*) FROM clients;          -- should match c_clients count
SELECT COUNT(*) FROM bidders;          -- should match b_bidders count
SELECT COUNT(*) FROM auctions;         -- should match a_workspaces count
SELECT COUNT(*) FROM lots;             -- sum of all {NNN}_inventory counts
SELECT COUNT(*) FROM bids;             -- sum of accepted bids across all tables

-- Spot check a known auction
SELECT * FROM auctions WHERE id = 42;
SELECT * FROM lots WHERE auction_id = 42 LIMIT 10;

-- Verify winning bids set
SELECT COUNT(*) FROM lots
WHERE status = 'closed' AND winning_bid_id IS NULL;
-- should be 0

-- Verify exposure calculations
SELECT COUNT(*) FROM bidder_deposit_accounts
WHERE balance != (
  SELECT COALESCE(SUM(amount), 0)
  FROM bidder_deposit_transactions
  WHERE bidder_deposit_account_id = bidder_deposit_accounts.id
  AND type = 'deposit'
);
-- should be 0
```
