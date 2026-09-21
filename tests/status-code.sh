#!/usr/bin/env bash
#
# Regression test: the status a script sets reaches the client.
#
# http_response_code() is a no-op under the CLI SAPI, and the compat shim
# records the code in Q_Response rather than in Q_WebServer_State. The
# server read the native function and the state object, so in the static
# binary both said 200 while the script had asked for a 302: every
# redirect went out as "200 OK" carrying a Location header, which a
# browser simply ignores. Logins that redirect after authenticating landed
# back on the form.
#
# Running from source it happened to work, because Q_Response::code() also
# reaches the native function there. That is why this test prefers the
# built binary -- from source it proves very little.
#
#   ./tests/status-code.sh
#   QB=/path/to/qbixserver ./tests/status-code.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"

PORT=0
for _ in $(seq 1 60); do
    _p=$(( 19000 + RANDOM % 900 ))
    ss -ltn 2>/dev/null | grep -q ":$_p " || { PORT=$_p; break; }
done
[ "$PORT" = "0" ] && { echo "  no free port found"; exit 1; }

SRV=""
cleanup() { [ -n "$SRV" ] && kill "$SRV" 2>/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

command -v "$PHP" >/dev/null || { echo "no php"; exit 1; }

QB="${QB:-$WS/bin/qbixserver}"
if [ -x "$QB" ]; then
    RUN=("$QB"); VIA="binary: $QB"
else
    RUN=("$PHP" "$WS/qbixserver.php")
    VIA="sources — the native function still carries the code, this run proves little"
fi

ROOT="$TMP/public"; mkdir -p "$ROOT"
printf '<?php http_response_code(302); header("Location: /target");\n'      > "$ROOT/redirect.php"
printf '<?php http_response_code(302); header("Location: /target"); exit;\n' > "$ROOT/redirect-exit.php"
printf '<?php http_response_code(404); echo "gone";\n'                      > "$ROOT/notfound.php"
printf '<?php http_response_code(201); echo "made";\n'                      > "$ROOT/created.php"
printf '<?php http_response_code(503); exit;\n'                             > "$ROOT/down.php"
printf '<?php echo "fine";\n'                                               > "$ROOT/plain.php"

echo "=============================================="
echo " Qbix Server — the script's status code"
echo "=============================================="
echo
echo "  via $VIA"
echo

( setsid "${RUN[@]}" --root="$ROOT" --port=$PORT --workers=1 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/plain.php" 2>/dev/null \
        && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PORT$1" 2>/dev/null; }
loc()  { curl -s -o /dev/null -w '%{redirect_url}' --max-time 15 "http://127.0.0.1:$PORT$1" 2>/dev/null; }

check() {  # label, path, expected
    local got; got=$(code "$2")
    [ "$got" = "$3" ] && ok "$1" || bad "$1 (got $got, expected $3)"
}

check "redirect: 302 reaches the client"        /redirect.php      302
check "redirect before exit: 302 as well"       /redirect-exit.php 302
check "404 reaches the client"                  /notfound.php      404
check "201 reaches the client"                  /created.php       201
check "503 before exit reaches the client"      /down.php          503
check "a plain script is still 200"             /plain.php         200

# A Location without a 3xx is the shape of the bug, not just a wrong number.
case "$(loc /redirect.php)" in
    */target) ok "redirect: Location is followed" ;;
    *) bad "Location was not followed: '$(loc /redirect.php)'" ;;
esac

# The status must not stick to the next request on the same worker.
check "status does not leak to the next request" /plain.php 200

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
