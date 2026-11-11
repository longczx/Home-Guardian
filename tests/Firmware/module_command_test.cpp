#include <cassert>
#include <iostream>
#include "command_handler.h"
#include <Preferences.h>

int writes = 0, reads = 0;
bool mutate(const JsonObject&, JsonObject& reply) { ++writes; reply["applied"] = true; return true; }
bool list(const JsonObject&, JsonObject& reply) {
    ++reads;
    JsonArray modules = reply["modules"].to<JsonArray>();
    for (int i = 0; i < 8; i++) {
        JsonObject module = modules.add<JsonObject>();
        module["uid"] = std::string(45, 'a') + std::to_string(i);
        module["name"] = std::string(64, 'b'); module["pin"] = i + 18; module["type"] = "dht11";
    }
    return true;
}
int main() {
    CommandHandler handler;
    handler.registerAction("list_modules", list, true);
    handler.registerAction("update_module", mutate, true);
    MqttManager mqtt;
    const char* query = R"({"action":"list_modules","request_id":"read","params":{}})";
    for (int i = 0; i < 40; i++) handler.handle("gateway", query, strlen(query), mqtt);
    assert(reads == 40 && mqtt.lastReply.size() > 512 && mqtt.lastReply.size() < 4096);
    Preferences cache; cache.begin("hg_commands", false);
    assert(!cache.isKey("next"));  // snapshots do not consume limited NVS cache space
    handler.handle("child-ac", query, strlen(query), mqtt);
    assert(reads == 40);
    JsonDocument denied; assert(!deserializeJson(denied, mqtt.lastReply));
    assert(denied["status"] == "error");
    const char* update = R"({"action":"update_module","request_id":"write","params":{"uid":"ac","pin":26}})";
    handler.handle("gateway", update, strlen(update), mqtt);
    handler.handle("gateway", update, strlen(update), mqtt);
    assert(writes == 1 && cache.isKey("next"));
    JsonDocument result; assert(!deserializeJson(result, mqtt.lastReply));
    assert(result["status"] == "ok" && result["applied"] == true);
    std::cout << "Module command ACKs: large lists, no read cache growth, gateway scope and mutation deduplication passed\n";
}
