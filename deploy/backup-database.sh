#!/usr/bin/env bash
#
# deploy/backup-database.sh
#
# Daily MySQL backup with 14-day local rotation. Run via cron (see
# deploy/crontab.txt). Reads DB credentials from the Laravel .env file
# rather than hardcoding them here.
#
# Usage: ./backup-database.sh /path/to/backend/.env /path/to/backup/dir

set -euo pipefail

ENV_FILE="${1:?Usage: backup-database.sh <path-to-.env> <backup-dir>}"
BACKUP_DIR="${2:?Usage: backup-database.sh <path-to-.env> <backup-dir>}"
RETENTION_DAYS=14

# Pull DB_* values out of .env without sourcing the whole file (avoids
# accidentally executing anything unexpected in it).
db_value() {
  grep -E "^${1}=" "$ENV_FILE" | head -n1 | cut -d '=' -f2- | tr -d '"'
}

DB_HOST=$(db_value DB_HOST)
DB_PORT=$(db_value DB_PORT)
DB_DATABASE=$(db_value DB_DATABASE)
DB_USERNAME=$(db_value DB_USERNAME)
DB_PASSWORD=$(db_value DB_PASSWORD)

mkdir -p "$BACKUP_DIR"

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
OUTFILE="${BACKUP_DIR}/hotspot-billing_${TIMESTAMP}.sql.gz"

MYSQL_PWD="$DB_PASSWORD" mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USERNAME" \
  --single-transaction \
  --quick \
  --routines \
  "$DB_DATABASE" | gzip > "$OUTFILE"

echo "Backup written to $OUTFILE"

# Rotate: delete local backups older than RETENTION_DAYS. This is local
# disk retention only — for real durability, also sync $BACKUP_DIR to
# off-server storage (S3, Backblaze, etc.), which this script deliberately
# doesn't do since that requires credentials/config specific to your setup.
find "$BACKUP_DIR" -name "hotspot-billing_*.sql.gz" -mtime +"$RETENTION_DAYS" -delete
