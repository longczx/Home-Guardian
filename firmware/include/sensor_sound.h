#ifndef SENSOR_SOUND_H
#define SENSOR_SOUND_H

#include "sensor_base.h"
#include <Arduino.h>

class SensorSound : public ISensor {
public:
    SensorSound(uint8_t pin, const char* deviceUid);
    bool begin() override;
    bool read(JsonObject& telemetry) override;
    void describeFields(JsonArray& fields) const override;
    const char* name() const override { return "Sound"; }
    const char* uid() const override { return _uid.c_str(); }

private:
    uint8_t _pin;
    String _uid;   // 自持一份：热插拔时清单条目会移动，裸指针会悬空
    static constexpr int SAMPLE_COUNT = 128;
};

#endif
