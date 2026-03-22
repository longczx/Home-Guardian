#ifndef SENSOR_REGISTRY_H
#define SENSOR_REGISTRY_H

#include "sensor_base.h"

class SensorRegistry {
public:
    void registerSensor(ISensor* sensor);
    bool beginAll();
    bool readAll(JsonObject& telemetry);
    ISensor* get(uint8_t index) const { return (index < _count) ? _sensors[index] : nullptr; }
    uint8_t count() const { return _count; }

private:
    static constexpr uint8_t MAX_SENSORS = 8;
    ISensor* _sensors[MAX_SENSORS] = {};
    uint8_t _count = 0;
};

#endif
