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
    const int FILTER_SAMPLES = 5;
    float db_results[FILTER_SAMPLES];

    for (int s = 0; s < FILTER_SAMPLES; s++) {
        int minVal = 4095, maxVal = 0;
        // 保留你原有的采样逻辑
        for (int i = 0; i < SAMPLE_COUNT; i++) {
            int sample = analogRead(_pin);
            if (sample < minVal) minVal = sample;
            if (sample > maxVal) maxVal = sample;
            delayMicroseconds(200); // 稍微缩短间隔，加快滤波速度
        }

        int peakToPeak = maxVal - minVal;
        float current_db = (peakToPeak <= 1) ? 30.0 : (20.0 * log10((float)peakToPeak) + 20.0);
        db_results[s] = current_db;
    }

    // 简单的冒泡排序或直接找极值
    float total = 0, min_db = 100, max_db = 0;
    for(int i=0; i<FILTER_SAMPLES; i++) {
        total += db_results[i];
        if(db_results[i] < min_db) min_db = db_results[i];
        if(db_results[i] > max_db) max_db = db_results[i];
    }
    
    // 去掉极值取平均
    float final_db = (total - min_db - max_db) / (FILTER_SAMPLES - 2);

    telemetry["noise_db"] = round(final_db * 10.0) / 10.0;
    return true;
}