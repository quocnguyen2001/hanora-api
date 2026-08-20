#!/bin/sh
# Backup hằng ngày, giữ 7 bản.
#
# Chạy trong container postgres qua cron trên host:
#   0 3 * * * docker compose -f docker-compose.prod.yml exec -T postgres /usr/local/bin/backup.sh
set -eu

STAMP=$(date +%Y%m%d-%H%M%S)
TARGET="/backups/hanora-${STAMP}.sql.gz"

pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" | gzip > "$TARGET"

# Giữ 7 bản gần nhất. Giữ vô hạn thì đĩa đầy, và đĩa đầy làm Postgres dừng ghi.
ls -1t /backups/hanora-*.sql.gz | tail -n +8 | xargs -r rm --

echo "backup ok: ${TARGET} ($(du -h "$TARGET" | cut -f1))"
