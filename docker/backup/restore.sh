#!/bin/sh
# Restores a backup into a database. Used by the restore drill (docs/runbook.md):
#
#   restore <dump-name-or-latest> [target-database]
#
# The target database defaults to "${DB_DATABASE}_restore_test", never the live
# one: restoring over production is a deliberate, separate step in the runbook.
set -eu

: "${DB_HOST:?}" "${DB_DATABASE:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}" "${BACKUP_S3_BUCKET:?}"
PREFIX="${BACKUP_S3_PREFIX:-database}"
ENDPOINT_FLAG=""
if [ -n "${BACKUP_S3_ENDPOINT:-}" ]; then
    ENDPOINT_FLAG="--endpoint-url $BACKUP_S3_ENDPOINT"
fi

NAME="${1:-latest}"
TARGET="${2:-${DB_DATABASE}_restore_test}"

if [ "$NAME" = "latest" ]; then
    # shellcheck disable=SC2086
    NAME="$(aws $ENDPOINT_FLAG s3 ls "s3://${BACKUP_S3_BUCKET}/${PREFIX}/" | awk '{print $4}' | sort | tail -n 1)"
fi
[ -n "$NAME" ] || { echo "no backup found" >&2; exit 1; }

FILE="/tmp/$NAME"
# shellcheck disable=SC2086
aws $ENDPOINT_FLAG s3 cp "s3://${BACKUP_S3_BUCKET}/${PREFIX}/${NAME}" "$FILE" --only-show-errors

export PGPASSWORD="$DB_PASSWORD"
psql --host="$DB_HOST" --port="${DB_PORT:-5432}" --username="$DB_USERNAME" --dbname=postgres -v ON_ERROR_STOP=1 -q \
    -c "DROP DATABASE IF EXISTS \"$TARGET\";" -c "CREATE DATABASE \"$TARGET\";"
pg_restore --host="$DB_HOST" --port="${DB_PORT:-5432}" --username="$DB_USERNAME" --dbname="$TARGET" --no-owner --no-privileges --exit-on-error "$FILE"

INVOICES="$(psql --host="$DB_HOST" --port="${DB_PORT:-5432}" --username="$DB_USERNAME" --dbname="$TARGET" -tAc 'select count(*) from invoices')"
CONTACTS="$(psql --host="$DB_HOST" --port="${DB_PORT:-5432}" --username="$DB_USERNAME" --dbname="$TARGET" -tAc 'select count(*) from contacts')"
rm -f "$FILE"
echo "restored $NAME into $TARGET: $CONTACTS contacts, $INVOICES invoices"
