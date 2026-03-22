#ifndef SENSOR_SOUND_H
#define SENSOR_SOUND_H

#include "sensor_base.h"

class SensorSound : public ISensor {
public:
    SensorSound(uint8_t pin, const char* deviceUid);
    bool begin() override;
    bool read(JsonObject& telemetry) override;
    const char* name() const override { return "Sound"; }
    const char* uid() const override { return _uid; }

private:
    uint8_t _pin;
    const char* _uid;
    static constexpr int SAMPLE_COUNT = 128;
};

#endif
