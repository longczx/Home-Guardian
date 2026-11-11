#pragma once
#include "module_store.h"
#include <ArduinoJson.h>

// Prepare leaves the current driver alive. Commit runs only after NVS saved.
class IModuleRuntime {
public:
    virtual ~IModuleRuntime() = default;
    virtual bool prepare(const ModuleSlot& next, const ModuleSlot* previous) = 0;
    virtual void commit(const ModuleSlot& next, const ModuleSlot* previous) = 0;
    virtual void discard() = 0;
    virtual void remove(const ModuleSlot& previous) = 0;
};

class ModuleManager {
public:
    ModuleManager(ModuleStore& store, IModuleRuntime& runtime, const String& gatewayUid, int resetPin, bool psram)
        : _store(store), _runtime(runtime), _gatewayUid(gatewayUid), _resetPin(resetPin), _psram(psram) {}
    bool list(JsonObject& reply) const;
    bool add(const JsonObject& params, JsonObject& reply);
    bool update(const JsonObject& params, JsonObject& reply);
    bool remove(const JsonObject& params, JsonObject& reply);
private:
    ModuleStore& _store;
    IModuleRuntime& _runtime;
    String _gatewayUid;
    int _resetPin;
    bool _psram;
    bool validate(const ModuleSlot& slot, const char* exceptUid, JsonObject& reply) const;
};
