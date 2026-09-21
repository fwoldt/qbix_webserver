#!/usr/bin/env bash
#
# Regression test: a class that appears during a request keeps its statics.
#
# Classes declared after the boot snapshot were added to it using their
# declaration defaults, and reset to those after every request. That is not
# a state they were ever in. Composer's ClassLoader builds its include
# helper once, behind a null check:
#
#     private static $includeFile;                 // implicitly null
#     ...
#     if (self::$includeFile !== null) return;     // already built
#     self::$includeFile = \Closure::bind(...);
#
# Set back to null, the guard reports "already built" is false — no, worse:
# the helper is null and the loader calls it anyway, so the second request
# died with "Value of type null is not callable", raised from class_exists()
# inside vendor code with nothing to connect it to a static being cleared.
#
# Every application using Composer is exposed, which is nearly all of them.
# Here the shape is reproduced directly: a class initialising a static once
# and relying on it afterwards.
#
#   ./tests/lazy-statics.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
# Pick a port nothing else holds. Fixed ranges collide with whatever the
# machine happens to run -- a container on the same number makes every
# assertion here fail with an empty reply and no hint why.
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

# Composer's shape: a closure built once and called on every later use.
cat > "$ROOT/lazy.php" <<'PHP'
<?php
header('Content-Type: text/plain');
if (!class_exists('Loader', false)) {
    class Loader {
        private static $run;          // implicitly null, like Composer's
        private static $count = 0;
        public static function init() {
            if (self::$run !== null) return;   // build once
            self::$run = static function ($x) { return "RAN:$x"; };
        }
        public static function go($x) {
            $f = self::$run;                   // null after a reset
            return $f($x);
        }
        public static function bump() { return ++self::$count; }
    }
    Loader::init();
}
echo Loader::go('ok');
PHP

# A counter, to show what the reset was for: state that does accumulate.
cat > "$ROOT/counter.php" <<'PHP'
<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/lazy.php';
PHP

echo "=============================================="
echo " Qbix Server — lazily initialised statics"
echo "=============================================="
echo

# One worker, so every request lands on the one that has already run.
( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=1 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/lazy.php" 2>/dev/null && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

body() { curl -s --max-time 15 "http://127.0.0.1:$PORT/lazy.php" 2>/dev/null; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PORT/lazy.php" 2>/dev/null; }

r1=$(body)
[ "$r1" = "RAN:ok" ] && ok "first request builds and uses the static" \
                    || bad "first request: $r1"

# The one that used to fail. The class is still declared, so the guarded
# initialisation is skipped; the static has to have survived on its own.
r2=$(body)
case "$r2" in
    "RAN:ok") ok "second request still has it" ;;
    *"not callable"*) bad "second request lost the static: $r2" ;;
    *) bad "second request: $r2" ;;
esac

r3=$(body)
[ "$r3" = "RAN:ok" ] && ok "third request still has it" || bad "third request: $r3"

[ "$(code)" = "200" ] && ok "status stays 200" || bad "status became $(code)"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
