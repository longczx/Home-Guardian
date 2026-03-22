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
};

#endif
