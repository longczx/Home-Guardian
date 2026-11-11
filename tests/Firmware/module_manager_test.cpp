#include <cassert>
#include <iostream>
#include "module_manager.h"
#include "module_pins.h"

struct Runtime : IModuleRuntime {
    int commits = 0, discards = 0, removes = 0;
    bool fail = false;
    bool prepare(const ModuleSlot&, const ModuleSlot*) override { return !fail; }
    void commit(const ModuleSlot&, const ModuleSlot*) override { ++commits; }
    void discard() override { ++discards; }
    void remove(const ModuleSlot&) override { ++removes; }
};

int main() {
    Preferences prefs, ir;
    prefs.begin("hg-mods", false);
    // Pre-existing installation, including a pin newly disallowed by the safe picker.
    prefs.putString("list", R"([{"t":"dht11","pin":2,"uid":"existing-temp","n":"room"},{"t":"ac_ir","pin":25,"uid":"existing-ac","n":"AC"}])");
    ir.begin("ac_ir", false); ir.putString("proto", "KELON168"); ir.putShort("model", 1);
    ModuleStore store; store.begin("gw");
    assert(store.healthy() && store.count() == 2 && store.get(0)->pin == 2);
    Runtime runtime;
    ModuleManager manager(store, runtime, "gw", 0, false);
    JsonDocument input, output;
    auto call = [&](auto method, const char* json) {
        input.clear(); output.clear(); assert(!deserializeJson(input, json));
        JsonObject response = output.to<JsonObject>();
        return (manager.*method)(input.as<JsonObject>(), response);
    };
    const String old = prefs.getString("list");
    for (int pin : {-1,0,1,2,3,5,6,11,12,15,20,24,28,31,34,35,36,37,38,39,40,260}) {
        std::string json = "{\"uid\":\"existing-ac\",\"pin\":" + std::to_string(pin) + "}";
        assert(!call(&ModuleManager::update, json.c_str()));
        assert(prefs.getString("list") == old);
    }
    assert(!call(&ModuleManager::update, R"({"uid":"existing-ac","pin":"26"})"));
    assert(!call(&ModuleManager::update, R"({"uid":"existing-ac","pin":26.5})"));
    assert(!call(&ModuleManager::add, R"({"type":"dht11","pin":25})"));
    assert(!call(&ModuleManager::add, R"({"type":"sound","pin":27})"));
    assert(!call(&ModuleManager::add, R"({"type":"ac_ir","pin":26})"));
    assert(!call(&ModuleManager::add, R"({"type":"unknown","pin":26})"));
    runtime.fail = true;
    assert(!call(&ModuleManager::update, R"({"uid":"existing-ac","pin":26})"));
    assert(store.findByUid("existing-ac")->pin == 25 && runtime.commits == 0);
    runtime.fail = false;
    Preferences::failWrites.insert("hg-mods/list");
    assert(!call(&ModuleManager::update, R"({"uid":"existing-ac","pin":26})"));
    assert(!call(&ModuleManager::add, R"({"type":"sound","pin":34})"));
    assert(!call(&ModuleManager::remove, R"({"uid":"existing-ac"})"));
    assert(store.count() == 2 && store.findByUid("existing-ac")->pin == 25);
    assert(runtime.commits == 0 && runtime.removes == 0 && prefs.getString("list") == old);
    Preferences::failWrites.clear();
    assert(call(&ModuleManager::update, R"({"uid":"existing-ac","pin":26})"));
    assert(store.findByUid("existing-ac")->pin == 26 && runtime.commits == 1);
    assert(ir.getString("proto") == "KELON168" && ir.getShort("model") == 1);
    assert(call(&ModuleManager::add, R"({"type":"sound","pin":34})"));
    assert(call(&ModuleManager::add, R"({"type":"sound","pin":35})"));
    assert(store.findByUid("gw-sound") && store.findByUid("gw-sound-2"));
    ModuleStore reboot; reboot.begin("gw");
    assert(reboot.count() == 4 && reboot.findByUid("existing-ac")->pin == 26);
    JsonDocument snapshot; JsonObject reply = snapshot.to<JsonObject>();
    assert(manager.list(reply) && reply["modules"].size() == 4 && reply["module_config_version"] == 1);
    while (store.count()) {
        std::string json = "{\"uid\":\"" + store.get(0)->uid + "\"}";
        assert(call(&ModuleManager::remove, json.c_str()));
    }
    assert(prefs.getString("list") == "[]");
    ModuleStore empty; empty.begin("gw"); assert(empty.healthy() && empty.count() == 0);
    for (int pin : {4,13,14,16,17,18,19,21}) {
        std::string json = "{\"type\":\"dht11\",\"pin\":" + std::to_string(pin) + "}";
        assert(call(&ModuleManager::add, json.c_str()));
    }
    assert(store.count() == 8);
    const int committed = runtime.commits;
    assert(!call(&ModuleManager::add, R"({"type":"dht11","pin":22})"));
    assert(store.count() == 8 && runtime.commits == committed);
    assert(!ModulePins::allowed("sound", 34, 34, false));  // custom BOOT pin
    assert(!ModulePins::allowed("ac_ir", 16, 0, true));   // PSRAM board
    assert(ModulePins::allowed("ac_ir", 16, 0, false));
    assert(ModulePins::allowed("sound", 39, 0, false));
    prefs.putString("list", "broken-json");
    ModuleStore corrupt; corrupt.begin("gw");
    assert(!corrupt.healthy() && prefs.getString("list") == "broken-json");
    ModuleManager corruptManager(corrupt, runtime, "gw", 0, false);
    assert(!corruptManager.list(reply));
    std::cout << "Module management: migration, safe pins, conflicts, rollback, UID preservation and intentional empty list passed\n";
}
