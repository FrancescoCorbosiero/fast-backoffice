#!/bin/sh
# Restores the change-request photos from their mirror (docs/runbook.md §4):
#
#   restore-uploads [target-folder]
#
# The target defaults to a scratch folder, never the live volume: the
# drill restores there, checks a sample of the files against the checksums
# in the database, and removes the folder. Restoring for real is
# `restore-uploads /srv/pipeline-uploads`, a deliberate step; photos the
# retention job had already removed come back with it, and its next run
# deletes them again.
set -eu

: "${DB_HOST:?}" "${DB_DATABASE:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}" "${BACKUP_S3_BUCKET:?}"
PREFIX="${BACKUP_S3_UPLOADS_PREFIX:-pipeline-uploads}"
ENDPOINT_FLAG=""
if [ -n "${BACKUP_S3_ENDPOINT:-}" ]; then
    ENDPOINT_FLAG="--endpoint-url $BACKUP_S3_ENDPOINT"
fi

SCRATCH="/tmp/pipeline-uploads-restore-test"
TARGET="${1:-$SCRATCH}"
mkdir -p "$TARGET"

# shellcheck disable=SC2086
aws $ENDPOINT_FLAG s3 sync "s3://${BACKUP_S3_BUCKET}/${PREFIX}/" "$TARGET/" --only-show-errors
chown -R 82:82 "$TARGET"   # www-data in the app and worker images
FILES="$(find "$TARGET" -type f | wc -l | tr -d ' ')"

# A sample of the photos kept, stored before last night's mirror.
export PGPASSWORD="$DB_PASSWORD"
ROWS="$(psql --host="$DB_HOST" --port="${DB_PORT:-5432}" --username="$DB_USERNAME" --dbname="$DB_DATABASE" -tAc \
    "select stored_path || ' ' || sha256 from change_request_attachments where removed_at is null and created_at < now() - interval '1 day' order by random() limit 5")"
CHECKED=0
FAILED=0
for ROW in $(echo "$ROWS" | tr ' ' ':'); do
    NAME="${ROW%%:*}"
    SUM="${ROW#*:}"
    CHECKED=$((CHECKED + 1))
    if [ "$(sha256sum "$TARGET/$NAME" 2>/dev/null | cut -d ' ' -f 1)" != "$SUM" ]; then
        echo "missing or changed: $NAME" >&2
        FAILED=$((FAILED + 1))
    fi
done

echo "restored $FILES files into $TARGET: $((CHECKED - FAILED)) of $CHECKED sampled checksums match"
if [ "$TARGET" = "$SCRATCH" ]; then
    rm -rf "$SCRATCH"
fi
[ "$FAILED" -eq 0 ]
