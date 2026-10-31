#!/bin/sh
# One database backup: pg_dump (custom format, compressed) to
# s3://$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX/<date>.dump, keeping the last
# BACKUP_KEEP_DAYS days. Files (PDFs, XML, PEC envelopes) already live in
# S3-compatible storage: turn versioning on for that bucket.
#
# Needs: DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD,
# BACKUP_S3_BUCKET, BACKUP_S3_ENDPOINT (optional), AWS_ACCESS_KEY_ID,
# AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION.
set -eu

: "${DB_HOST:?}" "${DB_DATABASE:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}" "${BACKUP_S3_BUCKET:?}"
PREFIX="${BACKUP_S3_PREFIX:-database}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-30}"
ENDPOINT_FLAG=""
if [ -n "${BACKUP_S3_ENDPOINT:-}" ]; then
    ENDPOINT_FLAG="--endpoint-url $BACKUP_S3_ENDPOINT"
fi

STAMP="$(date -u +%Y-%m-%dT%H%M%SZ)"
FILE="/tmp/${DB_DATABASE}-${STAMP}.dump"

export PGPASSWORD="$DB_PASSWORD"
pg_dump --host="$DB_HOST" --port="${DB_PORT:-5432}" --username="$DB_USERNAME" --format=custom --compress=9 --no-owner --no-privileges --file="$FILE" "$DB_DATABASE"
pg_restore --list "$FILE" > /dev/null   # the dump is readable before it is trusted

# shellcheck disable=SC2086
aws $ENDPOINT_FLAG s3 cp "$FILE" "s3://${BACKUP_S3_BUCKET}/${PREFIX}/$(basename "$FILE")" --only-show-errors
rm -f "$FILE"
echo "backup ${STAMP} uploaded"

# Prune: anything older than KEEP_DAYS.
CUTOFF="$(date -u -d "-${KEEP_DAYS} days" +%Y-%m-%d 2>/dev/null || date -u -v-"${KEEP_DAYS}"d +%Y-%m-%d)"
# shellcheck disable=SC2086
aws $ENDPOINT_FLAG s3 ls "s3://${BACKUP_S3_BUCKET}/${PREFIX}/" | awk '{print $4}' | while read -r name; do
    day="$(echo "$name" | sed -E 's/.*-([0-9]{4}-[0-9]{2}-[0-9]{2})T.*/\1/')"
    if [ -n "$day" ] && [ "$day" \< "$CUTOFF" ]; then
        # shellcheck disable=SC2086
        aws $ENDPOINT_FLAG s3 rm "s3://${BACKUP_S3_BUCKET}/${PREFIX}/${name}" --only-show-errors
        echo "pruned $name"
    fi
done
