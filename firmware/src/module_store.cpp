#include "module_store.h"
#include "config.h"
#include <ArduinoJson.h>

static const char* NS  = "hg-mods";
static const char* KEY = "list";

void ModuleStore::begin(const String& gatewayUid) {
    load();
    if (_count == 0) {
        seedFromConfig(gatewayUid);
        save();
    }
    Serial.printf("[模块] 已加载 %d 个模块\n", _count);
    for (uint8_t i = 0; i < _count; i++) {
        Serial.printf("[模块]   %s pin=%d uid=%s\n",
                      _slots[i].type.c_str(), _slots[i].pin, _slots[i].uid.c_str());
    }
}

bool ModuleStore::isSupportedType(const String& type) {
    return type == "dht11" || type == "sound" || type == "ac_ir";
}

const ModuleSlot* ModuleStore::findByUid(const char* uid) const {
    if (!uid) return nullptr;
    for (uint8_t i = 0; i < _count; i++) {
        if (_slots[i].uid == uid) return &_slots[i];
    }
    return nullptr;
}

bool ModuleStore::add(const ModuleSlot& slot) {
    if (_count >= MAX_MODULES) return false;
    if (slot.uid.length() == 0 || !isSupportedType(slot.type)) return false;
    if (findByUid(slot.uid.c_str())) return false;   // uid 不可重复

    _slots[_count++] = slot;
    save();
    return true;
}

bool ModuleStore::remove(const char* uid) {
    if (!uid) return false;
    for (uint8_t i = 0; i < _count; i++) {
        if (_slots[i].uid != uid) continue;
        for (uint8_t j = i; j + 1 < _count; j++) {
            _slots[j] = _slots[j + 1];
        }
        _slots[--_count] = ModuleSlot{};
        save();
        return true;
    }
    return false;
}

void ModuleStore::load() {
    _prefs.begin(NS, true);
    String json = _prefs.getString(KEY, "");
    _prefs.end();

    _count = 0;
    if (json.length() == 0) return;

    JsonDocument doc;
    if (deserializeJson(doc, json)) {
        Serial.println("[模块] NVS 清单解析失败，按空处理");
        return;
    }
    for (JsonObject o : doc.as<JsonArray>()) {
        if (_count >= MAX_MODULES) break;
        ModuleSlot s;
        s.type = (const char*)(o["t"]   | "");
        s.pin  = (uint8_t)(o["pin"]     | 0);
        s.uid  = (const char*)(o["uid"] | "");
        s.name = (const char*)(o["n"]   | "");
        if (s.uid.length() == 0 || !isSupportedType(s.type)) continue;
        _slots[_count++] = s;
    }
}

void ModuleStore::save() {
    JsonDocument doc;
    JsonArray arr = doc.to<JsonArray>();
    for (uint8_t i = 0; i < _count; i++) {
        JsonObject o = arr.add<JsonObject>();
        o["t"]   = _slots[i].type;
        o["pin"] = _slots[i].pin;
        o["uid"] = _slots[i].uid;
        o["n"]   = _slots[i].name;
    }
    String json;
    serializeJson(doc, json);

    _prefs.begin(NS, false);
    _prefs.putString(KEY, json);
    _prefs.end();
}

// 首次开机：按 config.h 的编译期开关播种，保证升级后原有传感器不丢
void ModuleStore::seedFromConfig(const String& gatewayUid) {
#if SENSOR_DHT11_ENABLED
    {
        ModuleSlot s;
        s.type = "dht11";
        s.pin  = DHT11_PIN;
    #ifdef SENSOR_DHT11_UID
        s.uid = SENSOR_DHT11_UID;
    #else
        s.uid = gatewayUid + "-temp";
    #endif
        s.name = "温湿度";
        if (_count < MAX_MODULES) _slots[_count++] = s;
    }
#endif
#if SENSOR_SOUND_ENABLED
    {
        ModuleSlot s;
        s.type = "sound";
        s.pin  = SOUND_SENSOR_PIN;
    #ifdef SENSOR_SOUND_UID
        s.uid = SENSOR_SOUND_UID;
    #else
        s.uid = gatewayUid + "-sound";
    #endif
        s.name = "噪声";
        if (_count < MAX_MODULES) _slots[_count++] = s;
    }
#endif
#if AC_IR_ENABLED
    {
        ModuleSlot s;
        s.type = "ac_ir";
        s.pin  = IR_LED_PIN;
    #ifdef AC_IR_UID
        s.uid = AC_IR_UID;
    #else
        s.uid = gatewayUid + "-ac";
    #endif
        s.name = "空调";
        if (_count < MAX_MODULES) _slots[_count++] = s;
    }
#endif
    (void)gatewayUid;
}
