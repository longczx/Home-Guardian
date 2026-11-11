#include "gateway_module_runtime.h"
#include "sensor_dht11.h"
#include "sensor_sound.h"
#include "config.h"
#include <new>

void GatewayModuleRuntime::release(int pin) {
    if (pin >= 0) pinMode(pin, pin == _resetPin ? INPUT_PULLUP : INPUT);
}

bool GatewayModuleRuntime::prepare(const ModuleSlot& next, const ModuleSlot* previous) {
    discard();
    _pendingPin = next.pin;
    if (next.type == "ac_ir") {
        _pendingAc = new (std::nothrow) AcIr(next.pin, AC_PROTOCOL);
        if (!_pendingAc) return false;
        _pendingAc->begin();
        if (previous && _ac) _pendingAc->restoreRuntimeState(*_ac);
        return true;
    }
    if (next.type == "dht11") _pendingSensor = new (std::nothrow) SensorDHT11(next.pin, next.uid.c_str());
    else if (next.type == "sound") _pendingSensor = new (std::nothrow) SensorSound(next.pin, next.uid.c_str());
    return _pendingSensor && _pendingSensor->begin();
}

void GatewayModuleRuntime::commit(const ModuleSlot&, const ModuleSlot* previous) {
    if (previous) remove(*previous);
    if (_pendingAc) { _ac = _pendingAc; _pendingAc = nullptr; }
    if (_pendingSensor) { _sensors.registerSensor(_pendingSensor); _pendingSensor = nullptr; }
    _pendingPin = -1;
}

void GatewayModuleRuntime::discard() {
    delete _pendingSensor; _pendingSensor = nullptr;
    delete _pendingAc; _pendingAc = nullptr;
    release(_pendingPin); _pendingPin = -1;
}

void GatewayModuleRuntime::remove(const ModuleSlot& previous) {
    if (previous.type == "ac_ir") { delete _ac; _ac = nullptr; }
    else _sensors.removeByUid(previous.uid.c_str());
    release(previous.pin);
}
