#include "sensor_dht11.h"
#include <Arduino.h>

SensorDHT11::SensorDHT11(uint8_t pin, const char* deviceUid)
    : _dht(pin, DHT11), _pin(pin), _uid(deviceUid) {}

bool SensorDHT11::begin() {
    _dht.begin();
    delay(1000);
    float t = _dht.readTemperature();
    return !isnan(t);
}

bool SensorDHT11::read(JsonObject& telemetry) {
    float temp = _dht.readTemperature();
    float humi = _dht.readHumidity();

    if (isnan(temp) || isnan(humi)) {
        Serial.println("[DHT11] 读取失败");
        return false;
    }

    telemetry["temperature"] = round(temp * 10.0) / 10.0;
    telemetry["humidity"]    = round(humi * 10.0) / 10.0;
    return true;
}
