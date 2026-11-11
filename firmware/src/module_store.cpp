#include "module_store.h"
#include "config.h"
#include <ArduinoJson.h>

static const char* NS  = "hg-mods";
static const char* KEY = "list";

void ModuleStore::begin(const String& gatewayUid) {
    int state = load();
    _healthy = state >= 0;
    if (state == 0) {
        seedFromConfig(gatewayUid);
        _healthy = save();
    }
    if (!_healthy) Serial.println("[模块] 配置读取/保存失败，保留原始数据并禁止修改");
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
    if (!_healthy || _count >= MAX_MODULES) return false;
    if (slot.uid.length() == 0 || !isSupportedType(slot.type)) return false;
    if (findByUid(slot.uid.c_str())) return false;   // uid 不可重复

    _slots[_count++] = slot;
    if (!save()) { _slots[--_count] = ModuleSlot{}; return false; }
    return true;
}

bool ModuleStore::remove(const char* uid) {
    if (!_healthy || !uid) return false;
    for (uint8_t i = 0; i < _count; i++) {
        if (_slots[i].uid != uid) continue;
        ModuleSlot previous = _slots[i];
        for (uint8_t j = i; j + 1 < _count; j++) {
            _slots[j] = _slots[j + 1];
        }
        _slots[--_count] = ModuleSlot{};
        if (!save()) {
            for (uint8_t j = _count; j > i; j--) _slots[j] = _slots[j - 1];
            _slots[i] = previous; ++_count;
            return false;
        }
        return true;
    }
    return false;
}

bool ModuleStore::update(const ModuleSlot& slot) {
    if (!_healthy) return false;
    for (uint8_t i = 0; i < _count; i++) {
        if (_slots[i].uid != slot.uid || _slots[i].type != slot.type) continue;
        ModuleSlot previous = _slots[i];
        _slots[i] = slot;
        if (!save()) { _slots[i] = previous; return false; }
        return true;
    }
    return false;
}

int ModuleStore::load() {
    _count = 0;
    if (!_prefs.begin(NS, true)) {
        // Preferences cannot open a namespace that has never been created.
        if (!_prefs.begin(NS, false)) return -1;
    }
    const bool exists = _prefs.isKey(KEY);
    String json = _prefs.getString(KEY, "");
    _prefs.end();
    if (!exists) return 0;
    JsonDocument doc;
    if (deserializeJson(doc, json) || !doc.is<JsonArray>()) return -1;
    for (JsonObject o : doc.as<JsonArray>()) {
        if (_count >= MAX_MODULES || !o["pin"].is<int>() || o["pin"].as<int>() < 0 || o["pin"].as<int>() > 39) {
            _count = 0; return -1;
        }
        ModuleSlot slot;
        slot.type = o["t"] | ""; slot.pin = o["pin"].as<int>();
        slot.uid = o["uid"] | ""; slot.name = o["n"] | "";
        if (slot.uid.length() == 0 || !isSupportedType(slot.type) || findByUid(slot.uid.c_str())) {
            _count = 0; return -1;
        }
        _slots[_count++] = slot;  // Preserve existing wiring, even outside the new safe pin picker.
    }
    return 1;  // [] is an intentional empty configuration, never seed it again.
}

bool ModuleStore::save() {
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

    if (!_prefs.begin(NS, false)) return false;
    bool saved = _prefs.putString(KEY, json) == json.length();
    _prefs.end();
    return saved;
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
