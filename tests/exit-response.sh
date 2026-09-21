#!/usr/bin/env bash
#
# Regression test: a script that calls exit() still gets its response out.
#
# exit and die end the process, not just the request. In a pooled worker
# that took the worker down with the finished response still sitting in
# its output buffer: the parent saw the socket close with nothing on it
# and answered 502 "Worker died". The work had been done and was thrown
# away.
#
# This is not an edge case. eZ/Exponential ends every dispatch in
# eZExecution::cleanExit(), which is an exit; redirects are conventionally
# header("Location: ...") followed by exit; JSON endpoints echo and exit.
# All of them returned 502.
#
# A shutdown function is the one thing that still runs after exit, so the
# worker answers from there, tells the parent it is on its way out, and
# the parent recycles it instead of handing the next request to a dead
# process — the second half matters, or the request after every exit is
# the one that 502s.
#
#   ./tests/exit-response.sh
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

ROOT="$TMP/public"; mkdir -p "$ROOT"
cat > "$ROOT/plain.php" <<'PHP'
<?php header("Content-Type: text/plain"); echo "normal";
PHP
cat > "$ROOT/exit.php" <<'PHP'
<?php header("Content-Type: text/plain"); echo "before exit"; exit;
PHP
cat > "$ROOT/die.php" <<'PHP'
<?php header("Content-Type: text/plain"); echo "before die"; die();
PHP
cat > "$ROOT/json.php" <<'PHP'
<?php header("Content-Type: application/json");
echo json_encode(array("ok" => true)); exit;
PHP
cat > "$ROOT/redirect.php" <<'PHP'
<?php http_response_code(302); header("Location: /target"); exit;
PHP
cat > "$ROOT/status.php" <<'PHP'
<?php http_response_code(404); header("Content-Type: text/plain");
echo "not here"; exit(0);
PHP

echo "=============================================="
echo " Qbix Server — exit() keeps the response"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=1 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/plain.php" 2>/dev/null \
        && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

body()   { curl -s --max-time 15 "http://127.0.0.1:$PORT$1" 2>/dev/null; }
code()   { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PORT$1" 2>/dev/null; }
ctype()  { curl -s -o /dev/null -w '%{content_type}' --max-time 15 "http://127.0.0.1:$PORT$1" 2>/dev/null; }
loc()    { curl -s -o /dev/null -w '%{redirect_url}' --max-time 15 "http://127.0.0.1:$PORT$1" 2>/dev/null; }

# ── the response itself survives exit ──
[ "$(code /exit.php)" = "200" ] \
    && ok "exit: status is 200, not 502" \
    || bad "exit gave $(code /exit.php)"
[ "$(body /exit.php)" = "before exit" ] \
    && ok "exit: body is what the script echoed" \
    || bad "exit body was '$(body /exit.php)'"
[ "$(code /die.php)" = "200" ] \
    && ok "die: status is 200" \
    || bad "die gave $(code /die.php)"
[ "$(body /die.php)" = "before die" ] \
    && ok "die: body survives" \
    || bad "die body was '$(body /die.php)'"

# ── headers set before exit are kept ──
case "$(ctype /json.php)" in
    application/json*) ok "exit: Content-Type survives" ;;
    *) bad "Content-Type was '$(ctype /json.php)'" ;;
esac
[ "$(body /json.php)" = '{"ok":true}' ] \
    && ok "exit: JSON endpoint answers" \
    || bad "JSON body was '$(body /json.php)'"

# ── status code and Location survive too: header()+exit is how PHP redirects ──
[ "$(code /redirect.php)" = "302" ] \
    && ok "redirect: status is 302" \
    || bad "redirect gave $(code /redirect.php)"
case "$(loc /redirect.php)" in
    */target) ok "redirect: Location is kept" ;;
    *) bad "Location was '$(loc /redirect.php)'" ;;
esac
[ "$(code /status.php)" = "404" ] \
    && ok "exit(0) keeps an explicit 404" \
    || bad "status.php gave $(code /status.php)"

# ── the worker is replaced, so the NEXT request is not the casualty ──
n=0
for _ in 1 2 3 4; do
    [ "$(code /exit.php)"  = "200" ] || n=$((n+1))
    [ "$(code /plain.php)" = "200" ] || n=$((n+1))
done
[ "$n" -eq 0 ] \
    && ok "requests after an exit still work (8 in a row)" \
    || bad "$n of 8 alternating requests failed after an exit"

# ── and a plain script is untouched by any of it ──
[ "$(body /plain.php)" = "normal" ] \
    && ok "a script without exit is unaffected" \
    || bad "plain.php returned '$(body /plain.php)'"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
