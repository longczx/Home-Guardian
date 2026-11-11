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
cp firmware/include/config_store.h firmware/include/long_press.h "$work/include/"
cp firmware/src/config_store.cpp tests/Firmware/provisioning_test.cpp "$work/"
g++ -std=gnu++17 -pthread -I"$work/stubs" -I"$work/include" \
    "$work/config_store.cpp" "$work/provisioning_test.cpp" -o "$work/provisioning-test"
"$work/provisioning-test"
cp firmware/include/reset_button.h "$work/include/"
cp firmware/src/reset_button.cpp tests/Firmware/reset_task_test.cpp "$work/"
g++ -std=gnu++17 -pthread -I"$work/stubs" -I"$work/include" \
    "$work/config_store.cpp" "$work/reset_button.cpp" "$work/reset_task_test.cpp" -o "$work/reset-task-test"
"$work/reset-task-test"
cp tests/Firmware/app_defaults_test.cpp "$work/"
# Compile the real configuration store against the actual shipped example, not the legacy fixture.
cp firmware/include/config.example.h "$work/stubs/config.h"
g++ -std=gnu++17 -pthread -I"$work/stubs" -I"$work/include" \
    "$work/config_store.cpp" "$work/app_defaults_test.cpp" -o "$work/app-defaults-test"
"$work/app-defaults-test"
cp firmware/include/module_store.h firmware/include/module_manager.h firmware/include/module_pins.h "$work/include/"
cp firmware/src/module_store.cpp firmware/src/module_manager.cpp tests/Firmware/module_manager_test.cpp "$work/"
g++ -std=gnu++17 -pthread -I"$work/stubs" -I"$work/include" -I"$work/json" \
    "$work/module_store.cpp" "$work/module_manager.cpp" "$work/module_manager_test.cpp" -o "$work/module-manager-test"
"$work/module-manager-test"
cp firmware/include/command_handler.h "$work/include/"
cp tests/Firmware/stubs/mqtt_manager.h "$work/include/"
cp firmware/src/command_handler.cpp tests/Firmware/module_command_test.cpp "$work/"
g++ -std=gnu++17 -pthread -I"$work/stubs" -I"$work/include" -I"$work/json" \
    "$work/command_handler.cpp" "$work/module_command_test.cpp" -o "$work/module-command-test"
"$work/module-command-test"
