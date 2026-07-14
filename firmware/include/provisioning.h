#ifndef PROVISIONING_H
#define PROVISIONING_H

#include <Arduino.h>
#include <WebServer.h>
#include <DNSServer.h>
#include "config_store.h"

/**
 * SoftAP 配网门户（captive portal）
 *
 * 无 WiFi 凭证时进入：开热点 HG-Setup-xxxx，DNS 全劫持到 192.168.4.1，
 * 设备自托管配网页。用户填家里 WiFi + 粘贴配对码 + 平台地址后提交，
 * 存入 NVS 并置 done，由 main 重启进入注册。
 */
class Provisioning {
public:
    void begin(ConfigStore* store);
    void loop();
    bool isDone() const { return _done; }

private:
    ConfigStore* _store = nullptr;
    WebServer _server{80};
    DNSServer _dns;
    bool _done = false;

    void handleRoot();
    void handleScan();
    void handleProvision();
};

#endif // PROVISIONING_H
