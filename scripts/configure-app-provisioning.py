#!/usr/bin/env python3
"""Clear compile-time network defaults without replacing hardware settings."""
import argparse
from pathlib import Path
import re


def configure(path: Path, example: Path) -> None:
    source = path.read_text(encoding="utf-8")
    defaults = example.read_text(encoding="utf-8")
    names = (
        "FIRMWARE_VERSION", "WIFI_SSID", "WIFI_PASSWORD", "DEFAULT_SERVER_URL",
        "MQTT_HOST", "MQTT_PORT", "GATEWAY_UID", "MQTT_PASSWORD",
    )
    # Validate everything before writing; never log existing credential values.
    for name in names:
        pattern = rf'^(#define\s+{name}\s+)(?:"(?:\\.|[^"\\\r\n])*"|\S+)'
        value = re.search(pattern, defaults, re.MULTILINE)
        if not value:
            raise ValueError(f"Example is missing {name}")
        replacement = value.group(0)[len(value.group(1)):]
        source, count = re.subn(pattern, lambda m: m.group(1) + replacement, source, flags=re.MULTILINE)
        if count != 1:
            raise ValueError(f"Expected one definition of {name} in {path}")
    with path.open("w", encoding="utf-8", newline="\n") as output:
        output.write(source)


if __name__ == "__main__":
    root = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=root / "firmware/include/config.h")
    args = parser.parse_args()
    configure(args.config, root / "firmware/include/config.example.h")
    print("App provisioning defaults applied; hardware and IR settings preserved.")
