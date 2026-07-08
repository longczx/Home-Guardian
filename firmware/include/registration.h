#ifndef REGISTRATION_H
#define REGISTRATION_H

#include "config_store.h"
#include "sensor_registry.h"

/**
 * 设备自注册：连上 WiFi 后凭配对码向平台注册，拿回 MQTT 凭证并存入 NVS。
 * POST {server_url}/api/provisioning/register
 */
class Registration {
public:
    // 成功返回 true（MQTT 凭证已保存）
    static bool run(ConfigStore& store, SensorRegistry& sensors, const char* firmwareVersion);
};

#endif // REGISTRATION_H
