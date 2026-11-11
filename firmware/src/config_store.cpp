#include "config_store.h"
#include <WiFi.h>
#include "config.h"
#include <mutex>

static const char* NS = "hg";  // Preferences 命名空间
static const char* RESET_NS = "hg-reset";
static std::mutex configMutex;
static bool resetting = false;

void ConfigStore::begin() {
    resetting = false;  // begin 仅在启动、按键任务创建前调用
    _gatewayUid = deviceUid();  // 默认用 MAC 派生
    Preferences flags;
    flags.begin(RESET_NS, true);
    const bool pending = flags.getBool("pending", false);
    _manualProvisioning = flags.getBool("manual", false) || pending;
    String stableUid = flags.getString("guid", "");
    flags.end();
    if (stableUid.length() > 0) _gatewayUid = stableUid;
    if (pending) {
        // 重启中断或旧请求落盘也不能恢复旧联网身份；标记保留到配网页成功提交。
        _prefs.begin(NS, false);
        _prefs.clear();
        _prefs.end();
        return;
    }
    load();

    // ── 向后兼容：NVS 为空但 config.h 填了真实 WiFi → 从 config.h 播种（旧的手动烧录流程仍可用）
#if defined(WIFI_SSID)
    if (!_manualProvisioning && _ssid.length() == 0 && strlen(WIFI_SSID) > 0 && strcmp(WIFI_SSID, "YourWiFi") != 0) {
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

bool ConfigStore::saveProvisioning(const String& ssid, const String& pass,
                                   const String& serverUrl, const String& code) {
    std::lock_guard<std::mutex> lock(configMutex);
    if (resetting || !_prefs.begin(NS, false)) return false;
    _prefs.putString("ssid", ssid);
    _prefs.putString("pass", pass);
    _prefs.putString("surl", serverUrl);
    if (_prefs.getString("pcode", "") != code) _prefs.remove("regtoken");
    _prefs.putString("pcode", code);
    _prefs.putString("guid", _gatewayUid);
    bool saved = _prefs.getString("ssid", "") == ssid &&
        _prefs.getString("pass", "") == pass && _prefs.getString("surl", "") == serverUrl &&
        _prefs.getString("pcode", "") == code && _prefs.getString("guid", "") == _gatewayUid;
    _prefs.end();
    if (!saved) return false;
    Preferences flags;
    if (!flags.begin(RESET_NS, false)) return false;
    if (_manualProvisioning && !flags.putBool("manual", true)) { flags.end(); return false; }
    const bool accepted = flags.putBool("pending", false) != 0;
    flags.end();
    if (!accepted) return false;
    _ssid = ssid; _pass = pass; _serverUrl = serverUrl; _code = code;
    return true;
}

void ConfigStore::saveMqtt(const String& host, uint16_t port,
                           const String& user, const String& pass) {
    std::lock_guard<std::mutex> lock(configMutex);
    if (resetting) return;
    _prefs.begin(NS, false);
    _prefs.putString("mhost", host);
    _prefs.putUShort("mport", port);
    _prefs.putString("muser", user);
    _prefs.putString("mpass", pass);
    _prefs.end();

    _mqttHost = host; _mqttPort = port; _mqttUser = user; _mqttPass = pass;
}

bool ConfigStore::resetNetworkForRestart() {
    std::lock_guard<std::mutex> lock(configMutex);
    Preferences flags;
    if (!flags.begin(RESET_NS, false)) return false;
    Preferences identity;
    identity.begin(NS, true);
    String uid = identity.getString("guid", "");
    identity.end();
    // 网络重配不能改变网关身份，否则已有模块和后台重新配网授权无法对应。
    if (uid.length() > 0 && !flags.putString("guid", uid)) { flags.end(); return false; }
    // 先持久化保护标记，掉电也进入配网。保留 manual，今后也不播种编译期旧凭证。
    bool saved = flags.putBool("pending", true) != 0;
    if (saved) saved = flags.putBool("manual", true) != 0;
    flags.end();
    if (!saved) return false;
    resetting = true;
    Preferences network;
    if (network.begin(NS, false)) { network.clear(); network.end(); }
    return true;  // pending 保证下次启动再次清理，即使此次擦除失败
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

String ConfigStore::registrationToken() {
    std::lock_guard<std::mutex> lock(configMutex);
    if (resetting) return "";
    _prefs.begin(NS, false);
    String token = _prefs.getString("regtoken", "");
    if (token.length() != 64) {
        token = "";
        for (int i = 0; i < 8; i++) { char part[9]; snprintf(part, sizeof(part), "%08x", (unsigned)esp_random()); token += part; }
        if (!_prefs.putString("regtoken", token)) token = "";
    }
    _prefs.end();
    return token;
}
