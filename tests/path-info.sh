#!/usr/bin/env bash
#
# Regression test: PATH_INFO reaches a pooled request.
#
# The server computes PATH_INFO for its CGI path and for its in-process
# dispatch, and never passed it to a worker — which is the default mode.
# So on any ordinary run PATH_INFO was unset and PHP_SELF stopped at the
# script, whatever the URL said.
#
# index.php/route/here is how a great deal of PHP routes itself, this
# application included. Frameworks that read PATH_INFO saw nothing and
# fell back to the front page or to whatever REQUEST_URI parsing they
# happened to have; legacy code reading PHP_SELF built links that dropped
# the route. Nothing errors, the wrong page is simply served.
#
#   ./tests/path-info.sh
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
trap 'rm -rf "$TMP"; pkill -f "qbixserver.*--port=$PORT" 2>/dev/null' EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

command -v "$PHP" >/dev/null || { echo "no php"; exit 1; }

ROOT="$TMP/public"; mkdir -p "$ROOT/sub"
PROBE='<?php header("Content-Type: text/plain");
echo ($_SERVER["PATH_INFO"] ?? "-"), "|", ($_SERVER["PHP_SELF"] ?? "-"),
     "|", ($_SERVER["PATH_TRANSLATED"] ?? "-"), "|", ($_SERVER["SCRIPT_NAME"] ?? "-");'
printf '%s\n' "$PROBE" > "$ROOT/i.php"
printf '%s\n' "$PROBE" > "$ROOT/sub/i.php"

echo "=============================================="
echo " Qbix Server — PATH_INFO on pooled requests"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/i.php" 2>/dev/null && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

get() { curl -s --max-time 15 "http://127.0.0.1:$PORT$1" 2>/dev/null; }
field() { printf '%s' "$1" | cut -d'|' -f"$2"; }

r=$(get /i.php)
[ "$(field "$r" 1)" = "-" ] \
    && ok "no path info: PATH_INFO stays unset" \
    || bad "PATH_INFO set without one: $(field "$r" 1)"
[ "$(field "$r" 2)" = "/i.php" ] \
    && ok "no path info: PHP_SELF is the script" \
    || bad "PHP_SELF was $(field "$r" 2)"

r=$(get /i.php/foo/bar)
[ "$(field "$r" 1)" = "/foo/bar" ] \
    && ok "PATH_INFO is the trailing path" \
    || bad "PATH_INFO was '$(field "$r" 1)', expected /foo/bar"
[ "$(field "$r" 2)" = "/i.php/foo/bar" ] \
    && ok "PHP_SELF carries the path info" \
    || bad "PHP_SELF was '$(field "$r" 2)'"
[ "$(field "$r" 4)" = "/i.php" ] \
    && ok "SCRIPT_NAME does not" \
    || bad "SCRIPT_NAME was '$(field "$r" 4)'"

# PATH_TRANSLATED is the document root joined with the path info.
pt=$(field "$r" 3)
case "$pt" in
    */foo/bar) ok "PATH_TRANSLATED points below the document root" ;;
    *) bad "PATH_TRANSLATED was '$pt'" ;;
esac
case "$pt" in *//*) bad "PATH_TRANSLATED has a doubled slash ($pt)" ;;
              *) ok "PATH_TRANSLATED has no doubled slash" ;; esac

# A script in a subdirectory splits at the same place.
r=$(get /sub/i.php/a/b/c)
[ "$(field "$r" 1)" = "/a/b/c" ] \
    && ok "subdirectory script: PATH_INFO is right" \
    || bad "subdirectory PATH_INFO was '$(field "$r" 1)'"

# A query string belongs to neither.
r=$(get "/i.php/x?q=1")
[ "$(field "$r" 1)" = "/x" ] \
    && ok "query string stays out of PATH_INFO" \
    || bad "PATH_INFO was '$(field "$r" 1)', expected /x"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
