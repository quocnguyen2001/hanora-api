#!/bin/sh
# Chạy một lần khi volume postgres còn trống.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE hanora_test OWNER $POSTGRES_USER;
SQL
