#!/usr/bin/env bash
# Runs the popup harness (BNPL-1607) in headless Chrome: suite "main" (popups allowed) and
# suite "blocked" (Chrome's default popup blocker, no user gesture). Test only.
#
# Environment:
#   MAGENTO_SRC  Magento root that contains lib/web/jquery.js and lib/web/knockoutjs/knockout.js
#                (only needed the first time, to copy them into .out/lib)
#   MODULE_JS    module view/frontend/web/js directory (default: this repo)
#   CHROME       Chrome/Chromium binary (default: macOS Google Chrome, else google-chrome)
#   OUT          results directory (default: dev/popup-harness/.out)
set -u
H="$(cd "$(dirname "$0")" && pwd)"
MODULE_JS="${MODULE_JS:-$H/../../view/frontend/web/js}"
OUT="${OUT:-$H/.out}"
if [ -z "${CHROME:-}" ]; then
  if [ -x "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" ]; then
    CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
  else
    CHROME="$(command -v google-chrome || command -v chromium || true)"
  fi
fi
[ -x "$CHROME" ] || { echo "Chrome not found: set CHROME" >&2; exit 2; }

mkdir -p "$OUT/lib"
if [ ! -f "$OUT/lib/jquery.js" ] || [ ! -f "$OUT/lib/knockout.js" ]; then
  [ -n "${MAGENTO_SRC:-}" ] || { echo "Set MAGENTO_SRC to copy jquery.js and knockout.js into $OUT/lib" >&2; exit 2; }
  cp "$MAGENTO_SRC/lib/web/jquery.js" "$OUT/lib/jquery.js"
  cp "$MAGENTO_SRC/lib/web/knockoutjs/knockout.js" "$OUT/lib/knockout.js"
fi
rm -f "$OUT"/results-main.json "$OUT"/results-blocked.json

python3 -I "$H/server.py" --module-js "$MODULE_JS" --out "$OUT" > "$OUT/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
sleep 1

run_suite() {
  local suite="$1"; shift
  local profile; profile="$(mktemp -d)"
  "$CHROME" --headless=new --disable-gpu --no-first-run --no-default-browser-check \
    --user-data-dir="$profile" "$@" "http://localhost:8098/harness.html?suite=$suite" > "$OUT/chrome-$suite.log" 2>&1 &
  local pid=$!
  for _ in $(seq 1 120); do [ -f "$OUT/results-$suite.json" ] && break; sleep 0.5; done
  kill $pid 2>/dev/null; wait $pid 2>/dev/null
  rm -rf "$profile"
  [ -f "$OUT/results-$suite.json" ] || echo "{\"suite\":\"$suite\",\"error\":\"no results (timeout)\"}" > "$OUT/results-$suite.json"
}

run_suite main --disable-popup-blocking
run_suite blocked
python3 -I - "$OUT" <<'PY'
import json, sys, os
out = sys.argv[1]
total = fails = 0
for suite in ('main', 'blocked'):
    data = json.load(open(os.path.join(out, 'results-%s.json' % suite)))
    if isinstance(data, dict):
        print(suite, 'ERROR', data); fails += 1; continue
    for r in data:
        if r.get('observation'):
            print('%-7s %-40s OBSERVATION %s' % (suite, r['name'], json.dumps(r['observed'], ensure_ascii=False)))
            continue
        total += 1
        fails += 0 if r['ok'] else 1
        print('%-7s %-40s %s' % (suite, r['name'], 'OK' if r['ok'] else 'FAIL ' + '; '.join(r['failures'])))
print('\n%d tests, %d failures' % (total, fails))
sys.exit(1 if fails else 0)
PY
