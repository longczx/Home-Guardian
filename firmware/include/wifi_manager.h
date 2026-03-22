#ifndef WIFI_MANAGER_H
#define WIFI_MANAGER_H

class WifiManager {
public:
    void begin(const char* ssid, const char* password);
    void loop();
    bool isConnected();

private:
    const char* _ssid = nullptr;
    const char* _password = nullptr;
    unsigned long _lastCheck = 0;
    static constexpr unsigned long CHECK_INTERVAL_MS = 10000;
};

#endif
