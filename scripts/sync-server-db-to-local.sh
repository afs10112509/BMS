#!/usr/bin/env bash
# READ-ONLY on server: dump db_bms, never restore/drop on server.
set -eu

echo "=== SERVER COUNTS BEFORE DUMP (read-only) ==="
docker exec postgres psql -U postgres -d db_bms -t -A -c "SELECT count(*) FROM users;"
docker exec postgres psql -U postgres -d db_bms -t -A -c "SELECT count(*) FROM transactions;"
docker exec postgres psql -U postgres -d db_bms -t -A -c "SELECT count(*) FROM categories;"

echo "=== DUMPING (read-only) ==="
docker exec postgres pg_dump -U postgres -d db_bms --no-owner --no-acl --format=custom -f /tmp/db_bms_server.dump
docker cp postgres:/tmp/db_bms_server.dump /tmp/db_bms_server.dump
ls -lh /tmp/db_bms_server.dump

echo "=== SERVER COUNTS AFTER DUMP (must match before) ==="
docker exec postgres psql -U postgres -d db_bms -t -A -c "SELECT count(*) FROM users;"
docker exec postgres psql -U postgres -d db_bms -t -A -c "SELECT count(*) FROM transactions;"
docker exec postgres psql -U postgres -d db_bms -t -A -c "SELECT count(*) FROM categories;"

echo "DONE_DUMP"
