#!/usr/bin/env bash
# Run with PlatformIO and the pinned espressif32 platform installed.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
output=${1:-"$root/output/firmware/home-guardian-1.2.0"}
server=${HG_FIRMWARE_SERVER_URL:-https://your-server}
mkdir -p "$output"
output=$(cd "$output" && pwd)
staging=$(mktemp -d)
trap 'rm -rf "$staging"' EXIT
cp -r "$root/firmware/src" "$root/firmware/include" "$root/firmware/platformio.ini" "$staging/"
# Always use the credential-free example, never the developer's config.h.
cp "$root/firmware/include/config.example.h" "$staging/include/config.h"
HG_BUILD_CONFIG="$staging/include/config.h" HG_BUILD_SERVER="$server" python3 - <<'PY'
import json,os,pathlib,re
path=pathlib.Path(os.environ['HG_BUILD_CONFIG'])
path.write_text(re.sub(r'^#define DEFAULT_SERVER_URL\s+.*$', '#define DEFAULT_SERVER_URL '+json.dumps(os.environ['HG_BUILD_SERVER']), path.read_text(), flags=re.M))
PY
pio run -d "$staging"
cp "$staging/.pio/build/esp32/firmware.bin" "$output/firmware.bin"
cp "$staging/.pio/build/esp32/bootloader.bin" "$output/bootloader.bin"
cp "$staging/.pio/build/esp32/partitions.bin" "$output/partitions.bin"
platformio_dir=${PLATFORMIO_CORE_DIR:-${HOME}/.platformio}
cp "$platformio_dir/packages/framework-arduinoespressif32/tools/partitions/boot_app0.bin" "$output/boot_app0.bin"
cp "$root/docs/guide/hisense-ir-acceptance.md" "$output/README.md"
git -c safe.directory="$root" -C "$root" rev-parse HEAD > "$output/source-base-commit.txt"
git -c safe.directory="$root" -C "$root" diff --binary HEAD -- firmware > "$output/source-changes.patch"
(cd "$output" && sha256sum firmware.bin bootloader.bin partitions.bin boot_app0.bin > SHA256SUMS)
echo "Firmware package: $output"
