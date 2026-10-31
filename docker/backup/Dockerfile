# Nightly database backup to S3-compatible storage (docs/runbook.md, "Backups").
FROM alpine:3.21

RUN apk add --no-cache postgresql18-client aws-cli gzip coreutils tzdata

COPY --chmod=0755 backup.sh /usr/local/bin/backup
COPY --chmod=0755 restore.sh /usr/local/bin/restore
COPY --chmod=0755 loop.sh /usr/local/bin/loop

CMD ["loop"]
