#ifndef SENSOR_REGISTRY_H
#define SENSOR_REGISTRY_H

#include "sensor_base.h"

class SensorRegistry {
public:
    // 注册后由本类接管所有权（热插拔时 remove/析构会 delete），
    // 故传入的实例必须是 new 出来的堆对象
    void registerSensor(ISensor* sensor);
    bool beginAll();
    bool readAll(JsonObject& telemetry);
    ISensor* get(uint8_t index) const { return (index < _count) ? _sensors[index] : nullptr; }
    uint8_t count() const { return _count; }

    // 热插拔：按 uid 摘除并销毁实例
    bool removeByUid(const char* uid);
    ISensor* findByUid(const char* uid) const;

    ~SensorRegistry();

private:
    static constexpr uint8_t MAX_SENSORS = 8;
    ISensor* _sensors[MAX_SENSORS] = {};
    uint8_t _count = 0;
};

#endif
