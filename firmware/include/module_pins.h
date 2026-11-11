#pragma once
#include <string.h>

// ESP32 DevKit: exclude flash, UART0, strapping pins and unexposed pads.
namespace ModulePins {
inline bool allowed(const char* type, int pin, int resetPin, bool psram) {
    static const int exposed[] = {4,13,14,16,17,18,19,21,22,23,25,26,27,32,33,34,35,36,39};
    if (pin == resetPin || (psram && (pin == 16 || pin == 17))) return false;
    bool found = false;
    for (int candidate : exposed) if (candidate == pin) found = true;
    if (!found) return false;
    if (!strcmp(type, "sound")) return pin >= 32;
    return (!strcmp(type, "dht11") || !strcmp(type, "ac_ir")) && pin < 34;
}
}
