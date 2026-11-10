#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
lib="${IR_LIBRARY_DIR:-firmware/.pio/libdeps/esp32/IRremoteESP8266}"
json="${ARDUINO_JSON_DIR:-firmware/.pio/libdeps/esp32/ArduinoJson}"
output="${IR_TEST_BINARY:-/tmp/home-guardian-ir-test}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/test" "$work/include"
cp -r "$lib/src" "$work/ir"
cp "$lib/test"/*.h "$work/test/"
cp -r "$json/src" "$work/json"
cp -r tests/Firmware/stubs "$work/stubs"
cp firmware/include/ac_ir.h firmware/include/ac_protocol_catalog.h firmware/include/kelon168_state.h "$work/include/"
cp tests/Firmware/ac_ir_test.cpp firmware/src/ac_ir.cpp "$work/"
compile() {
    g++ -std=gnu++17 -DUNIT_TEST -I"$work/stubs" -I"$work/include" \
        -I"$work/ir" -I"$work/test" -I"$work/json" -c "$1" -o "$1.o"
}
export work
export -f compile
printf '%s\0' "$work"/ir/*.cpp "$work"/ac_ir.cpp "$work"/ac_ir_test.cpp | \
    xargs -0 -n1 -P "${IR_TEST_JOBS:-4}" bash -c 'compile "$1"' _
g++ "$work"/ir/*.cpp.o "$work"/ac_ir.cpp.o "$work"/ac_ir_test.cpp.o -o "$output"
"$output"
