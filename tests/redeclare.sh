#!/usr/bin/env bash
#
# Regression test: a function library included twice does not kill the worker.
#
# A worker outlives the request, so a file pulled in with require rather
# than require_once declares its functions a second time and PHP stops
# with "Cannot redeclare". Under fpm the process is new each request and
# nobody ever notices, so applications do this freely: Exponential
# includes kernel/content/node_edit.php that way, and editing any content
# died on the second attempt — with the fatal only in the server log, the
# browser getting a 500 with nothing in it.
#
# The compat layer wraps top-level declarations in existence checks. Two
# limits are deliberate and are checked here: only files that do nothing
# but declare are touched, because guarding costs a function its hoisting;
# and the rewritten source is parsed before use, so a file the transform
# cannot handle is left exactly as it was.
#
# What that leaves unsolved, and this test says so rather than hiding it:
# a file that both runs statements and declares a function still dies when
# it is included twice. Guarding it would cost the hoisting it relies on,
# and not guarding it means the redeclaration stands. Such a file needs
# require_once at the call site; no rewrite here can have it both ways.
#
#   ./tests/redeclare.sh
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

ROOT="$TMP/public"; mkdir -p "$ROOT"

# A library of functions, the shape that breaks. Note the interpolated
# brace: counting those wrongly ends the body early and corrupts the file.
cat > "$ROOT/lib.php" <<'PHP'
<?php
function lib_one($a) { return $a + 1; }
function lib_two() { $x = array('k' => 'v'); return "a{$x['k']}b"; }
class LibClass { public function m() { return 'method'; } }
PHP

# require, not require_once: the second request re-declares.
cat > "$ROOT/page.php" <<'PHP'
<?php
require __DIR__ . '/lib.php';
header('Content-Type: text/plain');
echo lib_one(1), '|', lib_two(), '|', (new LibClass)->m();
PHP

# A file that also runs statements must not be rewritten, so its
# functions keep their hoisting.
cat > "$ROOT/mixed.php" <<'PHP'
<?php
header('Content-Type: text/plain');
echo hoisted();                  // called before it is declared
function hoisted() { return 'HOISTED'; }
PHP

echo "=============================================="
echo " Qbix Server — including a function library twice"
echo "=============================================="
echo

# One worker, so the second request lands on the one that already ran.
( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=1 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/page.php" 2>/dev/null && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

body()   { curl -s --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }
status() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }

want='2|avb|method'
for i in 1 2 3; do
    r=$(body page.php)
    if [ "$r" = "$want" ]; then ok "request $i serves the library ($r)"
    else bad "request $i: '$r', expected '$want'"; fi
done

[ "$(status page.php)" = "200" ] && ok "status stays 200" || bad "status $(status page.php)"

# The fatal used to be in the log only, so look there too.
grep -q 'Cannot redeclare' "$TMP/server.log" \
    && bad "the log still shows a redeclaration" \
    || ok "nothing was redeclared"

# Hoisting must survive in a file that is not a pure library.
r=$(body mixed.php)
[ "$r" = "HOISTED" ] \
    && ok "a mixed file keeps its hoisting ($r)" \
    || bad "mixed file: '$r', expected HOISTED"

# On a reused worker the same file redeclares and dies. Reported, not
# asserted: it is the known cost of leaving mixed files alone, and the
# fix for it belongs in the application's require_once.
r=$(body mixed.php)
if [ "$r" = "HOISTED" ]; then
    echo "  note: a mixed file survived reuse here; it is not guaranteed to"
else
    echo "  note: a mixed file still dies when included twice — use"
    echo "        require_once for files that declare and run"
fi

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
