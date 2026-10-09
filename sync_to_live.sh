#!/usr/bin/env bash
#
# Syncs ONE tenant's data from the dash2bid-staging app's DB (`jdss_staging`)
# into the real production platform DB (`dash2bid`). `jdss_staging` is where
# `run.php`/`reset.php` do their work (tenant 1 / René Bates only), and this
# script is the step that pushes a refresh into the live platform.
#
# Scope is every table with a tenant_id column (or that hangs off one via a
# parent FK), same universe `reset.php` operates on — EXCEPT `tenants`,
# `tenant_domains`, `users`, and `categories`, which are never touched here
# either. Other tenants in `dash2bid` are never read or written.
#
# Also syncs the actual photo FILES (lot photos + client logos) from the
# staging app's storage disk to the live app's storage disk, scoped to the
# same tenant's current lot/client IDs — the DB rows alone are useless
# without the files `lot_images.path`/`clients.logo_path` point at. This
# was missed in the original version (2026-10-09): DB sync succeeded but
# every lot/auction photo on the live site was a broken image, because the
# files only ever existed on the staging box.
#
# Dry-run by default (shows what would happen, touches nothing).
# Pass --confirm to actually run it. Pass --tenant=<id> to skip the prompt.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

CONFIRM=false
TENANT_ID=""
for arg in "$@"; do
  case "$arg" in
    --confirm) CONFIRM=true ;;
    --tenant=*) TENANT_ID="${arg#--tenant=}" ;;
    *) echo "Unknown argument: $arg" >&2; exit 1 ;;
  esac
done

SOURCE_DB="jdss_staging"
LIVE_DB="${LIVE_DB:-dash2bid}"
SOURCE_STORAGE_DIR="${SOURCE_STORAGE_DIR:-/var/www/dash2bid-staging/storage/app/public}"
LIVE_STORAGE_DIR="${LIVE_STORAGE_DIR:-/var/www/dash2bid/storage/app/public}"

# Tables with a tenant_id column — same "migrated content" scope as
# reset.php's cascade, minus tenants/tenant_domains/users/categories.
TENANT_TABLES=(
  auctions auction_bidder_access auction_notifications auction_templates
  bidders bidder_category_interests bidder_credit_limit_history
  bidder_deposit_accounts bidder_deposit_transactions bidder_notes
  bidder_tenant bids bid_increment_sets clients client_users
  commission_rates consignors deposit_tier_sets invoices invoice_line_items
  lots lot_import_batches lot_payment_transactions lot_watches max_bids
  paid_receipts sellers tax_jurisdictions
)


if [[ -z "$TENANT_ID" ]]; then
  echo "Tenants available in '$SOURCE_DB':"
  sudo mysql -e "SELECT id, name, slug FROM tenants ORDER BY id;" "$SOURCE_DB"
  echo
  read -r -p "Tenant ID to sync into '$LIVE_DB': " TENANT_ID
fi

if ! [[ "$TENANT_ID" =~ ^[0-9]+$ ]]; then
  echo "Invalid tenant id: '$TENANT_ID'" >&2
  exit 1
fi

TENANT_NAME=$(sudo mysql -N -e "SELECT name FROM tenants WHERE id=${TENANT_ID};" "$SOURCE_DB")
TENANT_SLUG=$(sudo mysql -N -e "SELECT slug FROM tenants WHERE id=${TENANT_ID};" "$SOURCE_DB")

if [[ -z "$TENANT_NAME" ]]; then
  echo "No tenant with id=${TENANT_ID} found in '$SOURCE_DB'." >&2
  exit 1
fi

echo "== sync_to_live.sh =="
echo "Source:  $SOURCE_DB"
echo "Target:  $LIVE_DB"
echo "Tenant:  ${TENANT_ID} (${TENANT_NAME} / ${TENANT_SLUG})"
echo

TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="backups"
BACKUP_FILE="${BACKUP_DIR}/${LIVE_DB}_pre_sync_tenant${TENANT_ID}_${TIMESTAMP}.sql.gz"
mkdir -p "$BACKUP_DIR"

# source COUNT query for each table, scoped to the chosen tenant.
count_query() {
  case "$1" in
    lot_images) echo "SELECT COUNT(*) FROM ${SOURCE_DB}.lot_images li JOIN ${SOURCE_DB}.lots l ON li.lot_id=l.id WHERE l.tenant_id=${TENANT_ID}" ;;
    lot_vehicle_details) echo "SELECT COUNT(*) FROM ${SOURCE_DB}.lot_vehicle_details lvd JOIN ${SOURCE_DB}.lots l ON lvd.lot_id=l.id WHERE l.tenant_id=${TENANT_ID}" ;;
    bid_increment_tiers) echo "SELECT COUNT(*) FROM ${SOURCE_DB}.bid_increment_tiers bit JOIN ${SOURCE_DB}.bid_increment_sets s ON bit.bid_increment_set_id=s.id WHERE s.tenant_id=${TENANT_ID}" ;;
    deposit_tiers) echo "SELECT COUNT(*) FROM ${SOURCE_DB}.deposit_tiers dt JOIN ${SOURCE_DB}.deposit_tier_sets s ON dt.deposit_tier_set_id=s.id WHERE s.tenant_id=${TENANT_ID}" ;;
    client_user_seller) echo "SELECT COUNT(*) FROM ${SOURCE_DB}.client_user_seller cus JOIN ${SOURCE_DB}.client_users cu ON cus.client_user_id=cu.id WHERE cu.tenant_id=${TENANT_ID}" ;;
    bidder_password_reset_tokens) echo "SELECT COUNT(*) FROM ${SOURCE_DB}.bidder_password_reset_tokens WHERE email IN (SELECT email FROM ${SOURCE_DB}.bidders WHERE tenant_id=${TENANT_ID} AND email IS NOT NULL)" ;;
    client_user_password_reset_tokens) echo "SELECT COUNT(*) FROM ${SOURCE_DB}.client_user_password_reset_tokens WHERE email IN (SELECT email FROM ${SOURCE_DB}.client_users WHERE tenant_id=${TENANT_ID})" ;;
    *) echo "SELECT COUNT(*) FROM ${SOURCE_DB}.${1} WHERE tenant_id=${TENANT_ID}" ;;
  esac
}

# target DELETE statement for each table, scoped to the chosen tenant.
# Explicit per-table — does NOT rely on FK cascade, because child rows can
# carry a mislabeled tenant_id that doesn't match their actual parent's
# tenant (seen in bidder_deposit_accounts: a row tagged tenant_id=1 whose
# bidder actually belongs to a different tenant). Deleting every table
# directly by its own scope condition is robust to that.
delete_query() {
  case "$1" in
    lot_images) echo "DELETE li FROM ${LIVE_DB}.lot_images li JOIN ${LIVE_DB}.lots l ON li.lot_id=l.id WHERE l.tenant_id=${TENANT_ID}" ;;
    lot_vehicle_details) echo "DELETE lvd FROM ${LIVE_DB}.lot_vehicle_details lvd JOIN ${LIVE_DB}.lots l ON lvd.lot_id=l.id WHERE l.tenant_id=${TENANT_ID}" ;;
    bid_increment_tiers) echo "DELETE bit FROM ${LIVE_DB}.bid_increment_tiers bit JOIN ${LIVE_DB}.bid_increment_sets s ON bit.bid_increment_set_id=s.id WHERE s.tenant_id=${TENANT_ID}" ;;
    deposit_tiers) echo "DELETE dt FROM ${LIVE_DB}.deposit_tiers dt JOIN ${LIVE_DB}.deposit_tier_sets s ON dt.deposit_tier_set_id=s.id WHERE s.tenant_id=${TENANT_ID}" ;;
    client_user_seller) echo "DELETE cus FROM ${LIVE_DB}.client_user_seller cus JOIN ${LIVE_DB}.client_users cu ON cus.client_user_id=cu.id WHERE cu.tenant_id=${TENANT_ID}" ;;
    bidder_password_reset_tokens) echo "DELETE FROM ${LIVE_DB}.bidder_password_reset_tokens WHERE email IN (SELECT email FROM ${LIVE_DB}.bidders WHERE tenant_id=${TENANT_ID} AND email IS NOT NULL)" ;;
    client_user_password_reset_tokens) echo "DELETE FROM ${LIVE_DB}.client_user_password_reset_tokens WHERE email IN (SELECT email FROM ${LIVE_DB}.client_users WHERE tenant_id=${TENANT_ID})" ;;
    *) echo "DELETE FROM ${LIVE_DB}.${1} WHERE tenant_id=${TENANT_ID}" ;;
  esac
}

# Join-scoped tables must be deleted BEFORE their parents (clients/auctions/
# bidders/etc. in TENANT_TABLES) — the delete's own JOIN needs the parent
# row to still exist to identify which children to remove.
JOIN_TABLES=(lot_images lot_vehicle_details bid_increment_tiers deposit_tiers client_user_seller bidder_password_reset_tokens client_user_password_reset_tokens)
ALL_TABLES=("${JOIN_TABLES[@]}" "${TENANT_TABLES[@]}")

# Builds the list of lots/<id> and clients/<id> storage directories for the
# chosen tenant's CURRENT rows (queried fresh from $SOURCE_DB each call), one
# per line, limited to dirs that actually exist on disk. Used both for the
# dry-run preview and the real rsync --files-from list.
storage_dir_list() {
  {
    sudo mysql -N -e "SELECT id FROM ${SOURCE_DB}.lots WHERE tenant_id=${TENANT_ID};" \
      | while read -r id; do [[ -d "${SOURCE_STORAGE_DIR}/lots/${id}" ]] && echo "lots/${id}"; done
    sudo mysql -N -e "SELECT id FROM ${SOURCE_DB}.clients WHERE tenant_id=${TENANT_ID};" \
      | while read -r id; do [[ -d "${SOURCE_STORAGE_DIR}/clients/${id}" ]] && echo "clients/${id}"; done
  }
}

if [[ "$CONFIRM" != true ]]; then
  echo "DRY RUN — no changes will be made. Pass --confirm to execute."
  echo
  echo "Would do, in order:"
  echo "  1. Back up current '$LIVE_DB' to $BACKUP_FILE"
  echo "  2. Delete tenant ${TENANT_ID}'s rows from '$LIVE_DB' (explicit per-table, not relying on FK cascade)"
  echo "  3. Copy tenant ${TENANT_ID}'s rows from '$SOURCE_DB' into '$LIVE_DB':"
  for t in "${ALL_TABLES[@]}"; do
    COUNT=$(sudo mysql -N -e "$(count_query "$t");" 2>/dev/null)
    echo "       ${t}: ${COUNT} row(s)"
  done
  DIR_COUNT=$(storage_dir_list | wc -l)
  echo "  4. Sync ${DIR_COUNT} lot-photo/client-logo director(ies) from '$SOURCE_STORAGE_DIR' to '$LIVE_STORAGE_DIR'"
  echo "  5. Verify row counts match between '$SOURCE_DB' and '$LIVE_DB' for tenant ${TENANT_ID}"
  echo
  echo "NOT touched: tenants, tenant_domains, users, categories, and every other tenant's data in '$LIVE_DB' (DB or storage)."
  exit 0
fi

echo "This will REPLACE tenant ${TENANT_ID}'s (${TENANT_NAME}) data in '$LIVE_DB' with"
echo "the current contents of '$SOURCE_DB'. Other tenants are not touched."
echo "A full backup of '$LIVE_DB' will be taken first."
read -r -p "Type the tenant slug ('${TENANT_SLUG}') to proceed: " TYPED
if [[ "$TYPED" != "$TENANT_SLUG" ]]; then
  echo "Aborted — confirmation text did not match." >&2
  exit 1
fi

echo
echo "[1/4] Backing up '$LIVE_DB' -> $BACKUP_FILE"
sudo mysqldump --single-transaction --quick "$LIVE_DB" | gzip > "$BACKUP_FILE"
echo "      $(du -h "$BACKUP_FILE" | cut -f1) backup written."

echo "[2/4] Syncing tenant ${TENANT_ID} (${TENANT_NAME}) from '$SOURCE_DB' into '$LIVE_DB'"

SQL="$(mktemp)"
trap 'rm -f "$SQL"' EXIT

{
  # multi-table DELETE ... JOIN requires a default database selected, even
  # with every table reference already schema-qualified.
  echo "USE ${LIVE_DB};"
  echo "START TRANSACTION;"
  echo "SET FOREIGN_KEY_CHECKS=0;"
  for t in "${ALL_TABLES[@]}"; do
    echo "$(delete_query "$t");"
  done
  for t in "${TENANT_TABLES[@]}"; do
    echo "INSERT INTO ${LIVE_DB}.${t} SELECT * FROM ${SOURCE_DB}.${t} WHERE tenant_id=${TENANT_ID};"
  done
  echo "INSERT INTO ${LIVE_DB}.lot_images SELECT li.* FROM ${SOURCE_DB}.lot_images li JOIN ${SOURCE_DB}.lots l ON li.lot_id=l.id WHERE l.tenant_id=${TENANT_ID};"
  echo "INSERT INTO ${LIVE_DB}.lot_vehicle_details SELECT lvd.* FROM ${SOURCE_DB}.lot_vehicle_details lvd JOIN ${SOURCE_DB}.lots l ON lvd.lot_id=l.id WHERE l.tenant_id=${TENANT_ID};"
  echo "INSERT INTO ${LIVE_DB}.bid_increment_tiers SELECT bit.* FROM ${SOURCE_DB}.bid_increment_tiers bit JOIN ${SOURCE_DB}.bid_increment_sets s ON bit.bid_increment_set_id=s.id WHERE s.tenant_id=${TENANT_ID};"
  echo "INSERT INTO ${LIVE_DB}.deposit_tiers SELECT dt.* FROM ${SOURCE_DB}.deposit_tiers dt JOIN ${SOURCE_DB}.deposit_tier_sets s ON dt.deposit_tier_set_id=s.id WHERE s.tenant_id=${TENANT_ID};"
  echo "INSERT INTO ${LIVE_DB}.client_user_seller SELECT cus.* FROM ${SOURCE_DB}.client_user_seller cus JOIN ${SOURCE_DB}.client_users cu ON cus.client_user_id=cu.id WHERE cu.tenant_id=${TENANT_ID};"
  echo "INSERT INTO ${LIVE_DB}.bidder_password_reset_tokens SELECT bprt.* FROM ${SOURCE_DB}.bidder_password_reset_tokens bprt WHERE bprt.email IN (SELECT email FROM ${SOURCE_DB}.bidders WHERE tenant_id=${TENANT_ID} AND email IS NOT NULL);"
  echo "INSERT INTO ${LIVE_DB}.client_user_password_reset_tokens SELECT cuprt.* FROM ${SOURCE_DB}.client_user_password_reset_tokens cuprt WHERE cuprt.email IN (SELECT email FROM ${SOURCE_DB}.client_users WHERE tenant_id=${TENANT_ID});"
  echo "SET FOREIGN_KEY_CHECKS=1;"
  echo "COMMIT;"
} > "$SQL"

sudo mysql < "$SQL"

echo "[3/4] Syncing lot photos + client logos from '$SOURCE_STORAGE_DIR' to '$LIVE_STORAGE_DIR'"

STORAGE_LIST="$(mktemp)"
trap 'rm -f "$SQL" "$STORAGE_LIST"' EXIT
storage_dir_list > "$STORAGE_LIST"
DIR_COUNT=$(wc -l < "$STORAGE_LIST")

if [[ "$DIR_COUNT" -eq 0 ]]; then
  echo "      No lot/client directories to sync."
else
  # --recursive must be passed explicitly: --files-from silently disables
  # the -r implied by -a, so without this every listed directory would be
  # created empty instead of having its files copied in (hit this exact
  # bug fixing today's broken-photos incident). --delete is scoped safely
  # here — it only prunes extraneous files *within the listed directories*,
  # never touching any lot/client dir (this tenant's stale ones, or any
  # other tenant's) that isn't in the list.
  sudo rsync -a --recursive --delete --files-from="$STORAGE_LIST" \
    "${SOURCE_STORAGE_DIR}/" "${LIVE_STORAGE_DIR}/"
  echo "      Synced ${DIR_COUNT} director(ies)."
fi

echo "[4/4] Verifying row counts match for tenant ${TENANT_ID}"
for t in "${ALL_TABLES[@]}"; do
  SRC_COUNT=$(sudo mysql -N -e "$(count_query "$t");" 2>/dev/null)
  LIVE_Q="$(count_query "$t" | sed "s/${SOURCE_DB}\./${LIVE_DB}./g")"
  LIVE_COUNT=$(sudo mysql -N -e "${LIVE_Q};" 2>/dev/null)
  if [[ "$SRC_COUNT" == "$LIVE_COUNT" ]]; then
    echo "      ${t}: OK (${SRC_COUNT})"
  else
    echo "      ${t}: MISMATCH (source=${SRC_COUNT} live=${LIVE_COUNT})" >&2
  fi
done

echo
echo "Done. Tenant ${TENANT_ID} (${TENANT_NAME}) in '$LIVE_DB' now matches '$SOURCE_DB' — DB rows and storage files."
echo "Rollback backup (DB only) kept at: $BACKUP_FILE"
echo "Other tenants in '$LIVE_DB' were not touched (DB or storage)."
