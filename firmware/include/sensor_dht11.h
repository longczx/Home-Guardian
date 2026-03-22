#ifndef SENSOR_DHT11_H
#define SENSOR_DHT11_H

#include "sensor_base.h"
#include <DHT.h>

class SensorDHT11 : public ISensor {
public:
    SensorDHT11(uint8_t pin, const char* deviceUid);
    bool begin() override;
    bool read(JsonObject& telemetry) override;
    const char* name() const override { return "DHT11"; }
    const char* uid() const override { return _uid; }

private:
    DHT _dht;
    uint8_t _pin;
    const char* _uid;
};

#endif
