#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
library="firmware/.pio/libdeps/esp32/IRremoteESP8266"
test -f "$library/src/ir_Kelon.cpp" || { echo 'Run PlatformIO dependency install/build first.' >&2; exit 1; }
build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT
g++ -std=c++11 -DUNIT_TEST -D_IR_ENABLE_DEFAULT_=false -DSEND_KELON168=true \
  -ffunction-sections -fdata-sections -I firmware/include -I "$library/src" -I "$library/test" \
  firmware/tests/kelon168_test.cpp "$library/src/ir_Kelon.cpp" \
  "$library/src/IRsend.cpp" "$library/src/IRtimer.cpp" "$library/src/IRutils.cpp" \
  "$library/src/IRtext.cpp" -Wl,--gc-sections -o "$build/kelon168-test"
"$build/kelon168-test"
