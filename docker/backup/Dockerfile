# Nightly database backup, and mirror of the change-request photos, to
# S3-compatible storage (docs/runbook.md, "Backups").
FROM alpine:3.23

RUN apk add --no-cache postgresql18-client aws-cli gzip coreutils tzdata

COPY --chmod=0755 backup.sh /usr/local/bin/backup
COPY --chmod=0755 restore.sh /usr/local/bin/restore
COPY --chmod=0755 restore-uploads.sh /usr/local/bin/restore-uploads
COPY --chmod=0755 loop.sh /usr/local/bin/loop

# The mount point of the pipeline_uploads volume, owned like the app's
# (www-data, uid 82): a new volume takes the owner of whichever container
# mounts it first.
RUN mkdir -p /srv/pipeline-uploads && chown 82:82 /srv/pipeline-uploads

CMD ["loop"]
