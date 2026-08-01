#ifndef SENSOR_BASE_H
#define SENSOR_BASE_H

#include <ArduinoJson.h>

class ISensor {
public:
    virtual ~ISensor() = default;
    virtual bool begin() = 0;
    virtual bool read(JsonObject& telemetry) = 0;
    virtual const char* name() const = 0;
    virtual const char* uid() const = 0;  // 传感器的 device_uid

    // 声明本传感器上报的遥测字段，注册时随之上报，让平台的 metric_fields
    // 与固件实际上报的 key 自动对齐（免去在 App 里手填、拼错）。
    // 每项形如 {key, label, unit}，key 必须与 read() 里写入 telemetry 的键一致。
    // 默认空实现：无字段的传感器（或纯执行器）无需覆盖。
    virtual void describeFields(JsonArray& fields) const { (void)fields; }
};

#endif
