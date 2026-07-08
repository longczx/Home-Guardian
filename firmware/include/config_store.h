#ifndef CONFIG_STORE_H
#define CONFIG_STORE_H

#include <Arduino.h>
#include <Preferences.h>

/**
 * 运行期配置存储（ESP32 NVS / Preferences）
 *
 * 把 WiFi、平台地址、配对码、MQTT 凭证、网关 UID 从编译期(config.h)搬到运行期，
 * 支撑自助配网。首次为空时若 config.h 填了真实凭证，则从 config.h 播种（兼容旧的手动烧录流程）。
 */
class ConfigStore {
public:
    void begin();

    bool hasWifi() const { return _ssid.length() > 0; }
    bool hasMqtt() const { return _mqttUser.length() > 0 && _mqttPass.length() > 0; }

    const String& wifiSsid()      const { return _ssid; }
    const String& wifiPass()      const { return _pass; }
    const String& serverUrl()     const { return _serverUrl; }
    const String& provisionCode() const { return _code; }
    const String& mqttHost()      const { return _mqttHost; }
    uint16_t      mqttPort()      const { return _mqttPort; }
    const String& mqttUser()      const { return _mqttUser; }
    const String& mqttPass()      const { return _mqttPass; }
    const String& gatewayUid()    const { return _gatewayUid; }

    // 配网页提交后保存（WiFi + 平台地址 + 配对码），随后重启进入注册
    void saveProvisioning(const String& ssid, const String& pass,
                          const String& serverUrl, const String& code);

    // 注册成功后保存 MQTT 凭证
    void saveMqtt(const String& host, uint16_t port,
                  const String& user, const String& pass);

    // 恢复出厂：清空全部
    void clearAll();

    // MAC 派生的稳定设备 UID：esp32-aabbcc（无需预配置即唯一）
    static String deviceUid();

private:
    Preferences _prefs;
    String _ssid, _pass, _serverUrl, _code;
    String _mqttHost, _mqttUser, _mqttPass, _gatewayUid;
    uint16_t _mqttPort = 1883;

    void load();
};

#endif // CONFIG_STORE_H
