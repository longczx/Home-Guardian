#pragma once
#include "module_manager.h"
#include "sensor_registry.h"
#include "ac_ir.h"

class GatewayModuleRuntime : public IModuleRuntime {
public:
    GatewayModuleRuntime(SensorRegistry& sensors, AcIr*& ac, int resetPin)
        : _sensors(sensors), _ac(ac), _resetPin(resetPin) {}
    bool prepare(const ModuleSlot& next, const ModuleSlot* previous) override;
    void commit(const ModuleSlot& next, const ModuleSlot* previous) override;
    void discard() override;
    void remove(const ModuleSlot& previous) override;
private:
    SensorRegistry& _sensors;
    AcIr*& _ac;
    int _resetPin;
    ISensor* _pendingSensor = nullptr;
    AcIr* _pendingAc = nullptr;
    int _pendingPin = -1;
    void release(int pin);
};
