#include <cassert>
#include <iostream>
#include "config_store.h"
#include "config.h"

int main() {
    assert(String(WIFI_SSID).empty() && String(WIFI_PASSWORD).empty());
    assert(String(DEFAULT_SERVER_URL).empty() && String(MQTT_HOST).empty());
    assert(String(MQTT_PASSWORD).empty() && String(GATEWAY_UID).empty());
    Preferences modules, ir;
    modules.begin("hg-mods", false); modules.putString("list", "keep-existing-pins");
    ir.begin("ac_ir", false); ir.putString("proto", "KELON168");
    ConfigStore fresh;
    fresh.begin();
    assert(!fresh.hasWifi() && !fresh.hasMqtt() && fresh.serverUrl().empty());
    assert(fresh.gatewayUid() == ConfigStore::deviceUid());
    assert(fresh.saveProvisioning("app-wifi", "app-pass", "http://new.example", "PAIRCODE"));
    fresh.saveMqtt("broker.example", 2883, "registered-user", "registered-password");
    ConfigStore reboot;
    reboot.begin();
    assert(reboot.wifiSsid() == "app-wifi" && reboot.wifiPass() == "app-pass");
    assert(reboot.serverUrl() == "http://new.example" && reboot.hasMqtt());
    assert(reboot.mqttHost() == "broker.example" && reboot.mqttPort() == 2883);
    assert(modules.getString("list") == "keep-existing-pins" && ir.getString("proto") == "KELON168");
    assert(ConfigStore::resetNetworkForRestart());
    ConfigStore reset;
    reset.begin();
    assert(!reset.hasWifi() && !reset.hasMqtt() && reset.serverUrl().empty());
    assert(modules.getString("list") == "keep-existing-pins" && ir.getString("proto") == "KELON168");
    std::cout << "Actual example defaults: blank boot, App provisioning, saved settings and hardware/IR preservation passed\n";
}
