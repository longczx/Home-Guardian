#include "sensor_registry.h"
#include <Arduino.h>

void SensorRegistry::registerSensor(ISensor* sensor) {
    if (_count < MAX_SENSORS) {
        _sensors[_count++] = sensor;
    }
}

bool SensorRegistry::beginAll() {
    bool allOk = true;
    for (uint8_t i = 0; i < _count; i++) {
        if (_sensors[i]->begin()) {
            Serial.printf("[Sensor] %s 初始化成功\n", _sensors[i]->name());
        } else {
            Serial.printf("[Sensor] %s 初始化失败!\n", _sensors[i]->name());
            allOk = false;
        }
    }
    return allOk;
}

bool SensorRegistry::readAll(JsonObject& telemetry) {
    bool anySuccess = false;
    for (uint8_t i = 0; i < _count; i++) {
        if (_sensors[i]->read(telemetry)) {
            anySuccess = true;
        }
    }
    return anySuccess;
}
