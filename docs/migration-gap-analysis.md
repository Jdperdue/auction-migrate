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

### Commission Structure (`c_client_data`, `c_indiv_commissions`)
```
comm_class_id1       (primary commission class)
comm_class_id2       (secondary commission class)
comm_cap             decimal (commission cap)
indiv_comm_id        (individual commission rate)
rate                 float (in c_indiv_commissions)
recipient            varchar
```
René Bates tracks commission rates owed back to clients/consignors —
the seller's side of the transaction. Our schema only tracks buyer's
premium (buyer pays). Seller commission is the other half of the
economics.

**Recommendation:** Significant v2 feature for operators who need to
manage seller-side commission reporting. Add `clients.commission_rate`,
`clients.commission_cap` and a `client_commission_classes` table.
Consignor commissions (individual rates) are an additional layer on top.

---

### Tax Classes (`c_client_data`, `g_tax_classes`)
```
tax_class_id    (per client, per lot)
```
Tax classification applied to lots — determines whether buyer pays
sales tax and at what rate. Our schema has no tax handling.

**Recommendation:** Notable gap for operators in jurisdictions that
require sales tax on auction purchases. Add to v2 as `tax_classes`
table with `lots.tax_class_id`. Not required for all operators but
important for municipal auctions in tax-collecting states.

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
| Seller commissions | `c_client_data` | Medium | Add commission fields to `clients` |
| Tax classes | `g_tax_classes` | Medium | Add `tax_classes` table + lot FK |
| Auction refresh rate | `a_auctions` | None | Obsolete — replaced by WebSocket |
| Contracts | `c_contracts` | None | Out of scope — CRM concern |
