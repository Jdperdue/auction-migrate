# Migration Gap Analysis — René Bates Source Data

> Data elements present in the René Bates source schema that have no
> landing place in the current auction platform schema. Reviewed for
> potential v2 additions or conscious decisions to discard.

---

## Bidder Data

### Tax Exemption (`b_bidder_tax`)
```
tax_number   varchar(24)
details      varchar(255)
```
Bidder's tax exemption number and notes. Municipal auctions frequently
involve tax-exempt entities. No target field in `bidders`.

**Recommendation:** Add to v2. `bidders.tax_exempt (bool)` and
`bidders.tax_number (string nullable)` are straightforward additions.
Relevant for invoice generation and reporting.

---

### Driver's License (`b_bidder_dl`)
```
number       varchar(16)
state_id     (state)
date_of_birth date
```
Identity verification data. Some operators require DL on file before
activating a bidder. No target schema.

**Recommendation:** Consider for v2 as an optional operator-configured
verification requirement. Sensitive PII — would need encryption at rest.

---

### Bidder Login History (`b_bidders`)
```
last_login    timestamp
this_login    timestamp
num_logins    mediumint
last_ip_address int
this_ip_address int
```
Login tracking fields on the core bidder record. Our `bidders` table
has no login history beyond `updated_at`.

**Recommendation:** Add `last_login_at` and `login_count` to `bidders`
in v2. Useful for trust scoring (Phase 7) and operator visibility into
bidder engagement. Low effort, high value.

---

### Bidder Category Interests (`b_bidder_interests`)
```
category_string  text  (comma-separated category IDs)
```
Bidder's stated category preferences. Used for targeted email
notifications about new auctions in their interest areas.

**Recommendation:** Good v2 feature. Maps naturally to a
`bidder_category_interests` pivot table. Enables "new auction in your
interest area" notification type.

---

### Bidder Payment History (`b_bidder_payment`)
```
workspace_id   (auction)
amount_paid    decimal
date_paid      date
payment_type   enum (cash|check|morder|cashiers|wire|paypal|cc/mo|deposit|zelle|internal)
details        text
```
Per-auction payment records — what the winning bidder actually paid and
how. 149,000+ rows. This is richer than our current model which only
tracks deposit transactions, not lot payment transactions.

**Recommendation:** High value — add to v2. This is the payment history
that feeds the trust scoring algorithm (Phase 7). Without it, scoring
has no payment behavior signal. Consider adding a `lot_payment_transactions`
table (distinct from `bidder_deposit_transactions`) to capture per-lot
payment records with payment method.

Also note the `payment_type` enum is a useful reference for what payment
methods to support: cash, check, money order, cashier's check, wire,
PayPal, cc/mo, deposit, Zelle, internal. Worth mirroring in our
`bidder_deposit_transactions.type` or a separate payment_method field.

---

### Bidder Status Details (`b_bidder_status`)
```
details   text
```
Free text explanation of a bidder's status — e.g. why they were banned
or limited. Our schema has `bidder_notes` for this purpose but the
`details` field is directly on the status record, not a separate note.

**Recommendation:** Migrate `details` text into `bidder_notes` with
`is_flagged = true` where status is `banned` or `limited`. Not a schema
gap per se — the data lands somewhere — but worth noting the semantic
difference.

---

## Auction Data

### Private Auctions (`a_private_auctions`)
```
workspace_id   (auction)
bidder_id      (bidder)
```
Pivot table — controls which bidders can access a private/invite-only
auction. `a_workspaces.is_private` flags the auction as private.
Our schema has no private auction concept.

**Recommendation:** Notable gap. René Bates uses this. Add to v2:
`auctions.is_private (bool)` and an `auction_bidder_access` pivot table.
When `is_private = true`, only bidders in the pivot can view and bid.

---

### Per-Lot Premium Override (`a_invoice_overrides`)
```
workspace_id, bidder_id, inventory_id
premium      float
tax_class_id
```
Allows overriding the buyer's premium on a per-lot per-bidder basis.
Our schema applies premium at the auction level only.

**Recommendation:** Edge case but real — some operators negotiate
different premiums for specific buyers or lots. Add to backlog as
`lot_bidder_premium_overrides` for v2.

---

### Auction Location / Directions (`a_auctions`)
```
locations    text
directions   text
```
Physical pickup location and directions for the auction. Our `auctions`
table has no location fields — only `lots.location_notes`.

**Recommendation:** Add `auctions.pickup_location (text nullable)` and
`auctions.pickup_directions (text nullable)` to v2. Municipal auctions
almost always have a physical pickup requirement.

---

### Alternate End Time Display (`a_auctions`)
```
alternate_time   varchar(32)
```
A display string for cases where the auction end time needs a human
readable override (e.g. "Closing approximately 6:00 PM CST"). Purely
presentational.

**Recommendation:** Add `auctions.closing_time_display (string nullable)`
to v2. Low effort, useful for operator communication.

---

### Auction Refresh Rate (`a_auctions`)
```
sec_to_refresh   tinyint
```
How often the bidding page auto-refreshes in seconds. Pre-WebSocket era
polling config. Our platform uses WebSocket push (Phase 4) so this is
obsolete architecture — no landing place needed.

**Recommendation:** Discard. Replaced by realtime service.

---

### Live Auction Photos (`a_live_auctions`)
```
workspace_id
photo_number
description
```
Photos associated with a live/simulcast auction event. Different from
lot images — these are event-level photos (e.g. auction site, preview
day). No target schema.

**Recommendation:** Add `auction_images` table to v2, mirroring the
`lot_images` pattern. Useful for auction landing pages.

---

### Staggered End Templates (`a_staggered_ends`)
```
num_lots     tinyint
num_minutes  tinyint
```
Named templates defining stagger patterns (e.g. "every 2 minutes for
groups of 5 lots"). Our schema does per-lot `closes_at` directly with
no template concept. The Phase 9 spec notes a bulk-setter UI as a
backlog item.

**Recommendation:** The data itself doesn't need migrating (templates
are configuration, not history) but the concept of saved stagger
templates is worth adding to v2 as a convenience feature for operators
setting up large auctions.

---

## Client Data

### Commission Structure (`g_commission_classes`, `c_indiv_commissions`) — CLOSED
```
comm_class_id   (per lot, on {NNN}_inventory)
com_rate        float  (g_commission_classes)
rate            float  (c_indiv_commissions, keyed by free-text recipient name)
```
No longer a gap — `clients.commission_rate`/`commission_cap` shipped on
the target 2026-07-04. Migration step `19_ClientCommissions` sets a
default `commission_rate` per client from their most-frequent
`comm_class_id` across historical lots. This is a best-effort inferred
default, not authoritative: the source assigns commission per lot, not
per client, and `c_indiv_commissions` can't be joined to any client (its
`recipient` column is free text like `"David Dean SEE NOTES"`).
`commission_cap` has no source analog and is left NULL. See
`migration-renebates.md` for the full mapping.

---

### Tax Classes (`g_tax_classes`) — CLOSED
```
tax_class_id    (per lot, on {NNN}_inventory)
```
No longer a gap — `tax_jurisdictions` and `lots.tax_jurisdiction_id`
shipped on the target 2026-07-04. Migration step `17_TaxJurisdictions`
seeds one `tax_jurisdictions` row per distinct source tax class and
sets `lots.tax_jurisdiction_id` directly from `tax_class_id` — a clean
FK-for-FK swap, no inference needed.

---

### Consignors (`c_consignors`) — CLOSED
```
ID          (526 rows)
client_id
description
```
No longer a gap — `consignors` and `lots.consignor_id` shipped on the
target 2026-07-04. Migration step `18_Consignors` migrates
`c_consignors` 1:1 (same shape as step 03 Clients — source IDs
preserved, no remapping) and sets `lots.consignor_id` from each lot's
`consignor_id`.

---

### Vehicle Details (free text in `{NNN}_inventory.description`) — CLOSED
```
"2020 Chevrolet Express Van; VIN 1GAZGNFP3L1255690; 347,793 Miles
 showing; 4.3L Gas; Auto; ..."
```
No longer a gap — `lot_vehicle_details` shipped on the target
2026-07-11. Descriptions follow a semi-consistent semicolon-delimited
format for actual vehicle lots (not all lots are vehicles — the same
tables hold livestock and equipment too). Migration step
`20_LotVehicleDetails` gates on finding a VIN-shaped token before
parsing make/model/year/mileage/fuel/transmission out of the text.
`title_status` comes from `disclosure_id` → `a_disclosures` instead of
the free text, since that's the more reliable of the two sources for
that one field.

---

### Client Logos (filesystem, not DB)
```
/var/www/logos/logo_{client_id:%06d}.jpg   (flat dir, 1,042 files)
```
Not a schema gap — `clients.logo_path` already exists on both sides (the
target added it for the client-logo card feature; the source derives the
path from `c_clients.ID` at request time in `admin/clients.php` rather
than storing it in a column). Unlike lot images, `clients.id` is
preserved 1:1 from `c_clients.ID`, so there's no ID-remapping problem
here — just a per-client existence check and file copy.

**Recommendation:** Add migration step `16_ClientLogos`, after
`03_Clients`. For each client, check
`SOURCE_LOGOS_DIR/logo_{id:%06d}.jpg`; if present, copy to
`TARGET_STORAGE_DIR/clients/{id}/logo.jpg` and set `clients.logo_path`.
No landing-place gap, no priority ranking needed — this is an
implementation task, not a v2 feature decision. See
`migration-renebates.md` § Clients for the full mapping.

---

### Contract Tracking (`c_contracts`)
```
contract_type   enum (rfp|psc|other)
contract_date   date
expires         date
```
Tracks operator contracts with clients (RFP, professional services, etc.).
Internal CRM/legal tracking. No target schema.

**Recommendation:** Out of scope for auction platform — belongs in a
CRM or document management tool. Discard.

---

## Summary Table

| Data Element | Source Table | Priority | Recommendation |
|---|---|---|---|
| Bidder login history | `b_bidders` | Medium | Add `last_login_at`, `login_count` to `bidders` |
| Tax exemption | `b_bidder_tax` | Medium | Add `tax_exempt`, `tax_number` to `bidders` |
| Payment history | `b_bidder_payment` | High | Add `lot_payment_transactions` table |
| Category interests | `b_bidder_interests` | Medium | Add `bidder_category_interests` pivot |
| Driver's license | `b_bidder_dl` | Low | Optional — PII, needs encryption |
| Private auctions | `a_private_auctions` | High | Add `auctions.is_private` + access pivot |
| Auction location | `a_auctions` | High | Add `pickup_location`, `pickup_directions` to `auctions` |
| Lot premium override | `a_invoice_overrides` | Low | Add `lot_bidder_premium_overrides` table |
| Auction images | `a_live_auctions` | Medium | Add `auction_images` table |
| Closing time display | `a_auctions` | Low | Add `closing_time_display` to `auctions` |
| Stagger templates | `a_staggered_ends` | Low | UI convenience feature — no data to migrate |
| Seller commissions | `g_commission_classes` | Closed | Schema shipped 07-04; step `19_ClientCommissions` (inferred default) |
| Tax classes | `g_tax_classes` | Closed | Schema shipped 07-04; step `17_TaxJurisdictions` (clean FK swap) |
| Consignors | `c_consignors` | Closed | Schema shipped 07-04; step `18_Consignors` (1:1, preserved IDs) |
| Vehicle details | `{NNN}_inventory.description` | Closed | Schema shipped 07-11; step `20_LotVehicleDetails` (VIN-gated parse) |
| Auction refresh rate | `a_auctions` | None | Obsolete — replaced by WebSocket |
| Contracts | `c_contracts` | None | Out of scope — CRM concern |
