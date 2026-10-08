#!/usr/bin/env bash
#
# Syncs the migration tool's working DB (`auction`) into the DB the live
# jdss.xyz app actually serves (`jdss_staging`). Both are served by the
# same Laravel codebase/storage tree; `auction` is where `run.php`/
# `reset.php` do their work, and this script is the step that makes a
# refresh visible on the live site.
#
# Dry-run by default (shows what would happen, touches nothing).
# Pass --confirm to actually run it.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

CONFIRM=false
for arg in "$@"; do
  case "$arg" in
    --confirm) CONFIRM=true ;;
    *) echo "Unknown argument: $arg" >&2; exit 1 ;;
  esac
done

SOURCE_DB="auction"
LIVE_DB="${LIVE_DB:-jdss_staging}"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="backups"
BACKUP_FILE="${BACKUP_DIR}/${LIVE_DB}_pre_sync_${TIMESTAMP}.sql.gz"
DUMP_FILE="${BACKUP_DIR}/${SOURCE_DB}_sync_${TIMESTAMP}.sql"

mkdir -p "$BACKUP_DIR"

echo "== sync_to_live.sh =="
echo "Source (working):  $SOURCE_DB"
echo "Target (live):     $LIVE_DB"
echo

if [[ "$CONFIRM" != true ]]; then
  echo "DRY RUN — no changes will be made. Pass --confirm to execute."
  echo
  echo "Would do, in order:"
  echo "  1. Back up current '$LIVE_DB' to $BACKUP_FILE"
  echo "  2. Dump '$SOURCE_DB' to $DUMP_FILE"
  echo "  3. Import that dump into '$LIVE_DB' (overwrites its current content)"
  echo "  4. Spot-check row counts (clients, lots, bids) match between the two DBs"
  exit 0
fi

echo "This will OVERWRITE the live-serving database '$LIVE_DB' with the"
echo "contents of '$SOURCE_DB'. A backup of '$LIVE_DB' will be taken first."
read -r -p "Type the target db name ('$LIVE_DB') to proceed: " TYPED
if [[ "$TYPED" != "$LIVE_DB" ]]; then
  echo "Aborted — confirmation text did not match." >&2
  exit 1
fi

echo
echo "[1/4] Backing up '$LIVE_DB' -> $BACKUP_FILE"
sudo mysqldump --single-transaction --quick "$LIVE_DB" | gzip > "$BACKUP_FILE"
echo "      $(du -h "$BACKUP_FILE" | cut -f1) backup written."

echo "[2/4] Dumping '$SOURCE_DB' -> $DUMP_FILE"
sudo mysqldump --single-transaction --quick --routines --triggers "$SOURCE_DB" > "$DUMP_FILE"
echo "      $(du -h "$DUMP_FILE" | cut -f1) dump written."

echo "[3/4] Importing into '$LIVE_DB'"
sudo mysql "$LIVE_DB" < "$DUMP_FILE"

echo "[4/4] Verifying row counts match for key tables"
for TABLE in clients lots bids auctions bidders; do
  SRC_COUNT=$(sudo mysql -N -e "SELECT COUNT(*) FROM $TABLE;" "$SOURCE_DB")
  LIVE_COUNT=$(sudo mysql -N -e "SELECT COUNT(*) FROM $TABLE;" "$LIVE_DB")
  if [[ "$SRC_COUNT" == "$LIVE_COUNT" ]]; then
    echo "      $TABLE: OK ($SRC_COUNT)"
  else
    echo "      $TABLE: MISMATCH (source=$SRC_COUNT live=$LIVE_COUNT)" >&2
  fi
done

rm -f "$DUMP_FILE"

echo
echo "Done. '$LIVE_DB' now matches '$SOURCE_DB'."
echo "Rollback backup kept at: $BACKUP_FILE"
