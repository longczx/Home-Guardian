#include "sensor_sound.h"
#include <Arduino.h>
#include <math.h>

SensorSound::SensorSound(uint8_t pin, const char* deviceUid)
    : _pin(pin), _uid(deviceUid) {}

bool SensorSound::begin() {
    pinMode(_pin, INPUT);
    analogReadResolution(12);
    int val = analogRead(_pin);
    return val >= 0;
}

bool SensorSound::read(JsonObject& telemetry) {
    int minVal = 4095, maxVal = 0;

    for (int i = 0; i < SAMPLE_COUNT; i++) {
        int sample = analogRead(_pin);
        if (sample < minVal) minVal = sample;
        if (sample > maxVal) maxVal = sample;
        delayMicroseconds(200);
    }

    int peakToPeak = maxVal - minVal;

    float db;
    if (peakToPeak <= 1) {
        db = 30.0;
    } else {
        db = 20.0 * log10((float)peakToPeak) + 20.0;
        if (db < 30.0) db = 30.0;
        if (db > 100.0) db = 100.0;
    }

    telemetry["noise_db"] = round(db * 10.0) / 10.0;
    return true;
}
