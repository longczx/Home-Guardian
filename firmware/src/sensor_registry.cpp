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

ISensor* SensorRegistry::findByUid(const char* uid) const {
    if (!uid) return nullptr;
    for (uint8_t i = 0; i < _count; i++) {
        if (strcmp(_sensors[i]->uid(), uid) == 0) return _sensors[i];
    }
    return nullptr;
}

bool SensorRegistry::removeByUid(const char* uid) {
    if (!uid) return false;
    for (uint8_t i = 0; i < _count; i++) {
        if (strcmp(_sensors[i]->uid(), uid) != 0) continue;
        delete _sensors[i];
        for (uint8_t j = i; j + 1 < _count; j++) {
            _sensors[j] = _sensors[j + 1];
        }
        _sensors[--_count] = nullptr;
        return true;
    }
    return false;
}

SensorRegistry::~SensorRegistry() {
    for (uint8_t i = 0; i < _count; i++) {
        delete _sensors[i];
    }
}
