#include "config_store.h"
#include <WiFi.h>
#include "config.h"

static const char* NS = "hg";  // Preferences 命名空间

void ConfigStore::begin() {
    _gatewayUid = deviceUid();  // 默认用 MAC 派生
    load();

    // ── 向后兼容：NVS 为空但 config.h 填了真实 WiFi → 从 config.h 播种（旧的手动烧录流程仍可用）
#if defined(WIFI_SSID)
    if (_ssid.length() == 0 && strlen(WIFI_SSID) > 0 && strcmp(WIFI_SSID, "YourWiFi") != 0) {
        String surl = "";
#ifdef DEFAULT_SERVER_URL
        surl = DEFAULT_SERVER_URL;
#endif
        saveProvisioning(WIFI_SSID, WIFI_PASSWORD, surl, "");

        // 若 config.h 也给了真实 MQTT 凭证，则一并播种 → 直接进 NORMAL，跳过自注册
#if defined(MQTT_PASSWORD) && defined(GATEWAY_UID) && defined(MQTT_HOST)
        if (strlen(MQTT_PASSWORD) > 0 && strcmp(MQTT_PASSWORD, "gateway_password") != 0) {
            _gatewayUid = GATEWAY_UID;
            _prefs.begin(NS, false);
            _prefs.putString("guid", _gatewayUid);
            _prefs.end();
            saveMqtt(MQTT_HOST, MQTT_PORT, GATEWAY_UID, MQTT_PASSWORD);
        }
#endif
    }
#endif
}

void ConfigStore::load() {
    _prefs.begin(NS, true);  // 只读
    _ssid      = _prefs.getString("ssid", "");
    _pass      = _prefs.getString("pass", "");
    _serverUrl = _prefs.getString("surl", "");
    _code      = _prefs.getString("pcode", "");
    _mqttHost  = _prefs.getString("mhost", "");
    _mqttPort  = _prefs.getUShort("mport", 1883);
    _mqttUser  = _prefs.getString("muser", "");
    _mqttPass  = _prefs.getString("mpass", "");
    String guid = _prefs.getString("guid", "");
    _prefs.end();
    if (guid.length() > 0) _gatewayUid = guid;
}

void ConfigStore::saveProvisioning(const String& ssid, const String& pass,
                                   const String& serverUrl, const String& code) {
    _prefs.begin(NS, false);
    _prefs.putString("ssid", ssid);
    _prefs.putString("pass", pass);
    _prefs.putString("surl", serverUrl);
    _prefs.putString("pcode", code);
    _prefs.putString("guid", _gatewayUid);
    _prefs.end();

    _ssid = ssid; _pass = pass; _serverUrl = serverUrl; _code = code;
}

void ConfigStore::saveMqtt(const String& host, uint16_t port,
                           const String& user, const String& pass) {
    _prefs.begin(NS, false);
    _prefs.putString("mhost", host);
    _prefs.putUShort("mport", port);
    _prefs.putString("muser", user);
    _prefs.putString("mpass", pass);
    _prefs.end();

    _mqttHost = host; _mqttPort = port; _mqttUser = user; _mqttPass = pass;
}

void ConfigStore::clearAll() {
    _prefs.begin(NS, false);
    _prefs.clear();
    _prefs.end();
    _ssid = ""; _pass = ""; _serverUrl = ""; _code = "";
    _mqttHost = ""; _mqttUser = ""; _mqttPass = "";
    // gatewayUid 保持 MAC 派生（稳定）
    _gatewayUid = deviceUid();
}

String ConfigStore::deviceUid() {
    uint64_t mac = ESP.getEfuseMac();
    char buf[20];
    snprintf(buf, sizeof(buf), "esp32-%02x%02x%02x",
             (uint8_t)(mac & 0xFF),
             (uint8_t)((mac >> 8) & 0xFF),
             (uint8_t)((mac >> 16) & 0xFF));
    return String(buf);
}
