#ifndef WIFI_MANAGER_H
#define WIFI_MANAGER_H

#include <Arduino.h>

class WifiManager {
public:
    void begin(const char* ssid, const char* password);
    void loop();
    bool isConnected();

private:
    // 存 String 拷贝：凭证来自运行期(NVS)的临时 buffer，不能存裸指针
    String _ssid;
    String _password;
    unsigned long _lastCheck = 0;
    static constexpr unsigned long CHECK_INTERVAL_MS = 10000;
};

#endif
