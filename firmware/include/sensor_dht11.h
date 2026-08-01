#ifndef SENSOR_DHT11_H
#define SENSOR_DHT11_H

#include "sensor_base.h"
#include <DHT.h>

class SensorDHT11 : public ISensor {
public:
    SensorDHT11(uint8_t pin, const char* deviceUid);
    bool begin() override;
    bool read(JsonObject& telemetry) override;
    void describeFields(JsonArray& fields) const override;
    const char* name() const override { return "DHT11"; }
    const char* uid() const override { return _uid.c_str(); }

private:
    DHT _dht;
    uint8_t _pin;
    String _uid;   // 自持一份：热插拔时清单条目会移动，裸指针会悬空
};

#endif
