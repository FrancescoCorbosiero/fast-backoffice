#!/bin/sh
# Runs the backup once a day at BACKUP_HOUR (Europe/Rome), then sleeps.
set -eu
export TZ="${APP_TIMEZONE:-Europe/Rome}"
HOUR="${BACKUP_HOUR:-03}"

while true; do
    if [ "$(date +%H)" = "$HOUR" ]; then
        backup || echo "backup failed at $(date -Iseconds)" >&2
        sleep 3600
    fi
    sleep 300
done
