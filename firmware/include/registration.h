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
    //
    // actuatorUid/Name/Type：可选的子执行器（如红外空调），与传感器一样注册成
    // 独立子设备，后端据 type 归类；不传则只注册网关 + 传感器。
    static bool run(ConfigStore& store, SensorRegistry& sensors, const char* firmwareVersion,
                    const char* actuatorUid = nullptr,
                    const char* actuatorName = nullptr,
                    const char* actuatorType = nullptr);
};

#endif // REGISTRATION_H
