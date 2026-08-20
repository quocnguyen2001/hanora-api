#!/bin/sh
# Khôi phục bản backup mới nhất vào database TẠM rồi đối chiếu số bản ghi.
#
# "Backup chưa khôi phục thử thì chưa phải backup." Chạy script này ít nhất một
# lần sau khi dựng backup, và lại sau mỗi lần đổi schema lớn.
#
#   docker compose -f docker-compose.prod.yml exec -T postgres /usr/local/bin/verify-restore.sh
set -eu

LATEST=$(ls -1t /backups/hanora-*.sql.gz | head -1)
TEMP_DB="hanora_restore_check"

echo "Khôi phục thử từ: ${LATEST}"

psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS ${TEMP_DB};"
psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE ${TEMP_DB};"
gunzip -c "$LATEST" | psql -U "$POSTGRES_USER" -d "$TEMP_DB" --quiet

echo ""
echo "Bảng                  gốc      khôi phục"
FAILED=0
for TABLE in users dictionary_words user_words review_logs dictionary_examples; do
  LIVE=$(psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT count(*) FROM ${TABLE};" 2>/dev/null || echo "-")
  COPY=$(psql -U "$POSTGRES_USER" -d "$TEMP_DB"     -tAc "SELECT count(*) FROM ${TABLE};" 2>/dev/null || echo "-")
  printf "%-20s %8s %10s" "$TABLE" "$LIVE" "$COPY"
  if [ "$LIVE" = "$COPY" ]; then echo "  ok"; else echo "  LỆCH"; FAILED=1; fi
done

psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE ${TEMP_DB};"

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "KHÔI PHỤC THỬ ĐẠT."
else
  # Lệch có thể do dữ liệu ghi thêm sau lúc dump — kiểm tay trước khi kết luận.
  echo "CÓ BẢNG LỆCH SỐ. Kiểm xem có phải do ghi thêm sau lúc dump không."
  exit 1
fi
