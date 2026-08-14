#!/usr/bin/env bash
set -euo pipefail
cd /opt/apps/bms

BASE="${1:-http://127.0.0.1:8082}"
EMAIL="${2:-admin.waebulen@gmail.com}"
PW="${3:-password}"

echo "BASE=$BASE EMAIL=$EMAIL"

RESP=$(curl -sS -X POST "$BASE/api/auth/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PW\"}")
echo "LOGIN_RESP=$RESP"

TOK=$(RESP="$RESP" python3 - <<'PY'
import json, os
j=json.loads(os.environ["RESP"])
print(j.get("token") or (j.get("data") or {}).get("token") or "")
PY
)

echo "TOK_LEN=${#TOK}"
if [ -z "$TOK" ]; then
  echo "LOGIN_FAILED"
  exit 1
fi

echo "=== ME ==="
curl -sS "$BASE/api/auth/me" -H "Accept: application/json" -H "Authorization: Bearer $TOK"
echo
echo "=== TECHNICIANS ==="
curl -sS "$BASE/api/service-records/technicians" -H "Accept: application/json" -H "Authorization: Bearer $TOK"
echo
echo "=== EMPLOYEES has_position ==="
curl -sS "$BASE/api/employees?has_position=teknisi&status=active" -H "Accept: application/json" -H "Authorization: Bearer $TOK"
echo
