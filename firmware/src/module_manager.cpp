#include "module_manager.h"
#include "module_pins.h"

static bool error(JsonObject& reply, const char* message) { reply["message"] = message; return false; }

bool ModuleManager::validate(const ModuleSlot& slot, const char* exceptUid, JsonObject& reply) const {
    if (!_store.healthy()) return error(reply, "模块存储异常，请检查设备配置");
    if (!ModuleStore::isSupportedType(slot.type)) return error(reply, "不支持的模块类型");
    if (!ModulePins::allowed(slot.type.c_str(), slot.pin, _resetPin, _psram))
        return error(reply, "该 GPIO 不适用于此模块或已被系统保留");
    if (!exceptUid) {
        if (slot.uid.length() == 0 || slot.uid.length() > 47 || slot.uid == _gatewayUid || slot.name.length() > 64)
            return error(reply, "模块标识或名称不合法");
        for (size_t i = 0; i < slot.uid.length(); i++) {
            char c = slot.uid[i];
            if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '-' || c == '_'))
                return error(reply, "模块标识仅支持字母、数字、横线和下划线");
        }
    }
    for (uint8_t i = 0; i < _store.count(); i++) {
        const ModuleSlot& other = *_store.get(i);
        if (exceptUid && other.uid == exceptUid) continue;
        if (other.uid == slot.uid) return error(reply, "模块标识已存在");
        if (other.pin == slot.pin) return error(reply, "GPIO 已被其他模块占用");
        if (slot.type == "ac_ir" && other.type == "ac_ir") return error(reply, "目前只支持一路红外发射模块");
    }
    return true;
}

bool ModuleManager::list(JsonObject& reply) const {
    if (!_store.healthy()) return error(reply, "模块存储异常，原始配置已保留，请检查设备");
    reply["module_config_version"] = 1;
    reply["board"] = "esp32dev";
    reply["max_modules"] = ModuleStore::MAX_MODULES;
    reply["reset_pin"] = _resetPin;
    JsonObject pins = reply["pins"].to<JsonObject>();
    for (const char* type : {"dht11", "sound", "ac_ir"}) {
        JsonArray available = pins[type].to<JsonArray>();
        for (int pin = 0; pin < 40; pin++) if (ModulePins::allowed(type, pin, _resetPin, _psram)) available.add(pin);
    }
    JsonArray modules = reply["modules"].to<JsonArray>();
    for (uint8_t i = 0; i < _store.count(); i++) {
        const ModuleSlot& slot = *_store.get(i);
        JsonObject item = modules.add<JsonObject>();
        item["type"] = slot.type; item["pin"] = slot.pin; item["uid"] = slot.uid; item["name"] = slot.name;
    }
    return true;
}

bool ModuleManager::add(const JsonObject& params, JsonObject& reply) {
    if (!params["pin"].is<int>() || params["pin"].as<int>() < 0 || params["pin"].as<int>() > 39)
        return error(reply, "GPIO 必须是有效整数");
    if (_store.count() >= ModuleStore::MAX_MODULES) return error(reply, "模块数量已达上限");
    ModuleSlot slot;
    slot.type = params["type"] | ""; slot.pin = params["pin"].as<int>();
    slot.uid = params["uid"] | ""; slot.name = params["name"] | "";
    if (slot.uid.length() == 0) {
        const char* suffix = slot.type == "dht11" ? "temp" : slot.type == "sound" ? "sound" : "ac";
        slot.uid = _gatewayUid + "-" + suffix;
        for (int n = 2; _store.findByUid(slot.uid.c_str()) && n <= 9; n++) {
            char tail[24]; snprintf(tail, sizeof(tail), "-%s-%d", suffix, n);
            slot.uid = _gatewayUid + tail;
        }
    }
    if (slot.name.length() == 0) slot.name = slot.type;
    if (!validate(slot, nullptr, reply)) return false;
    if (!_runtime.prepare(slot, nullptr)) { _runtime.discard(); return error(reply, "模块初始化失败，请检查接线；配置未变更"); }
    if (!_store.add(slot)) { _runtime.discard(); return error(reply, "配置保存失败，原配置已保留"); }
    _runtime.commit(slot, nullptr);
    reply["uid"] = slot.uid; reply["pin"] = slot.pin; reply["applied"] = true;
    return true;
}

bool ModuleManager::update(const JsonObject& params, JsonObject& reply) {
    String uid = params["uid"] | "";
    const ModuleSlot* found = _store.findByUid(uid.c_str());
    if (!found) return error(reply, "模块不存在");
    if (!params["pin"].is<int>() || params["pin"].as<int>() < 0 || params["pin"].as<int>() > 39)
        return error(reply, "GPIO 必须是有效整数");
    const ModuleSlot previous = *found;
    ModuleSlot next = previous;
    next.pin = params["pin"].as<int>();
    if (!validate(next, previous.uid.c_str(), reply)) return false;
    if (next.pin == previous.pin) { reply["uid"] = uid; reply["pin"] = next.pin; reply["applied"] = true; return true; }
    if (!_runtime.prepare(next, &previous)) { _runtime.discard(); return error(reply, "新引脚初始化失败，请检查接线；原配置已保留"); }
    if (!_store.update(next)) { _runtime.discard(); return error(reply, "配置保存失败，原配置已保留"); }
    _runtime.commit(next, &previous);
    reply["uid"] = uid; reply["pin"] = next.pin; reply["applied"] = true;
    return true;
}

bool ModuleManager::remove(const JsonObject& params, JsonObject& reply) {
    if (!_store.healthy()) return error(reply, "模块存储异常，请检查设备配置");
    String uid = params["uid"] | "";
    const ModuleSlot* found = _store.findByUid(uid.c_str());
    if (!found) return error(reply, "模块不存在");
    const ModuleSlot previous = *found;
    if (!_store.remove(uid.c_str())) return error(reply, "配置保存失败，原配置已保留");
    _runtime.remove(previous);
    reply["uid"] = uid; reply["applied"] = true;
    return true;
}
