#pragma once
#include <Arduino.h>
#include <cassert>
#include <vector>
constexpr int WIFI_OFF = 0, WIFI_STA = 1, WIFI_AP = 2, WIFI_AP_STA = 3;
constexpr int AP_STARTED_BIT = 1;
class IPAddress {
    uint32_t value;
public:
    IPAddress() : value(0) {}
    IPAddress(uint8_t a, uint8_t b, uint8_t c, uint8_t d) : value((uint32_t(a)<<24) | (uint32_t(b)<<16) | (uint32_t(c)<<8) | d) {}
    bool operator==(const IPAddress& other) const { return value == other.value; }
    String toString() const {
        char result[20]; snprintf(result, sizeof(result), "%u.%u.%u.%u", value>>24, (value>>16)&255, (value>>8)&255, value&255);
        return result;
    }
};
struct TestWiFi {
    int eraseCount = 0;
    int currentMode = WIFI_OFF, offCount = 0, apCalls = 0;
    int failOff = 0, failMode = 0, failConfig = 0, failAp = 0;
    int channel = 0, hidden = 1, clients = 0;
    bool autoIp = true, autoStarted = true, persistence = true, autoReconnect = true, open = false;
    int statusBits = 0;
    IPAddress ip, configuredIp;
    String ssid;
    std::vector<String> calls;
    bool eraseAP() { ++eraseCount; return true; }
    void persistent(bool enabled) { persistence = enabled; }
    bool setAutoReconnect(bool enabled) { autoReconnect = enabled; return true; }
    bool mode(int next) {
        if (next == WIFI_OFF) {
            ++offCount; calls.push_back("off");
            if (failOff > 0) { --failOff; return false; }
            ip = IPAddress(); ssid = ""; statusBits = 0;
        } else { calls.push_back("ap_mode"); if (failMode > 0) { --failMode; return false; } }
        currentMode = next; return true;
    }
    int getMode() { return currentMode; }
    int getStatusBits() { return statusBits; }
    bool softAPConfig(IPAddress local, IPAddress gateway, IPAddress mask) {
        calls.push_back("ip_config");
        if (failConfig > 0) { --failConfig; return false; }
        assert(local == gateway && mask == IPAddress(255,255,255,0));
        configuredIp = local; return true;
    }
    bool softAP(const char* name, const char* password, int ch, int hide, int maxClients) {
        ++apCalls; calls.push_back("soft_ap");
        if (failAp > 0) { --failAp; return false; }
        ssid = name; channel = ch; hidden = hide; clients = maxClients; open = password == nullptr;
        if (autoIp) ip = configuredIp;
        if (autoStarted) statusBits |= AP_STARTED_BIT;
        return true;
    }
    IPAddress softAPIP() { return ip; }
    String softAPSSID() { return ssid; }
};
inline TestWiFi WiFi;
