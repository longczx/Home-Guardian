#include "command_handler.h"
#include <Arduino.h>
#include <Preferences.h>

void CommandHandler::registerAction(const char* action, ActionHandler handler, bool gatewayOnly) {
    if (_count < MAX_ACTIONS) {
        _actions[_count++] = { action, handler, gatewayOnly };
    }
}

void CommandHandler::handle(const char* targetUid, const char* payload, unsigned int length, MqttManager& mqtt) {
    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, payload, length);
    if (err) {
        Serial.printf("[CMD] JSON 解析失败: %s\n", err.c_str());
        return;
    }

    const char* action    = doc["action"] | "";
    const char* requestId = doc["request_id"] | "";
    JsonObject params     = doc["params"].as<JsonObject>();

    Serial.printf("[CMD] 收到指令: action=%s, request_id=%s, target=%s\n", action, requestId, targetUid);

    if (!requestId[0] || strlen(requestId) > 96) return;
    for (uint8_t i = 0; i < _count; i++) {
        if (!strcmp(_actions[i].action, action) && _actions[i].gatewayOnly && !mqtt.isGatewayTarget(targetUid)) {
            JsonDocument denied;
            denied["request_id"] = requestId; denied["status"] = "error";
            denied["message"] = "module management requires gateway target";
            String json; serializeJson(denied, json);
            mqtt.publishCommandReplyFor(targetUid, json.c_str());
            return;
        }
    }
    // Read-only snapshots must not fill the small NVS partition with cached module lists.
    if (!strcmp(action, "list_modules")) {
        JsonDocument reply;
        JsonObject response = reply.to<JsonObject>();
        bool ok = false;
        for (uint8_t i = 0; i < _count; i++) {
            if (!strcmp(_actions[i].action, action)) { ok = _actions[i].handler(params, response); break; }
        }
        reply["request_id"] = requestId;
        reply["status"] = ok ? "ok" : "error";
        if (!ok && !reply["message"].is<const char*>()) reply["message"] = "list modules failed";
        String json; serializeJson(reply, json);
        mqtt.publishCommandReplyFor(targetUid, json.c_str());
        return;
    }
    Preferences cache;
    if (!cache.begin("hg_commands", false)) return;
    // 持久化最近 16 条回执，MQTT QoS1 重投或重启后不重复操作硬件。
    for (uint8_t i = 0; i < 16; i++) {
        char key[8]; snprintf(key, sizeof(key), "c%u", i);
        String saved = cache.getString(key, "");
        JsonDocument entry;
        if (deserializeJson(entry, saved)) continue;
        if (String(entry["id"] | "") == requestId && String(entry["target"] | "") == targetUid) {
            String previous = entry["reply"] | "";
            cache.end();
            mqtt.publishCommandReplyFor(targetUid, previous.c_str());
            return;
        }
    }
    uint8_t slot = cache.getUChar("next", 0) % 16;
    char cacheKey[8]; snprintf(cacheKey, sizeof(cacheKey), "c%u", slot);
    JsonDocument entry;
    entry["id"] = requestId;
    entry["target"] = targetUid;
    JsonDocument interrupted;
    interrupted["request_id"] = requestId;
    interrupted["status"] = "error";
    interrupted["message"] = "execution interrupted; inspect device before retry";
    String tombstone;
    serializeJson(interrupted, tombstone);
    entry["reply"] = tombstone;
    String saved;
    serializeJson(entry, saved);
    // 先记执行标记；掉电后返回未知结果，避免再次执行非幂等动作。
    if (!cache.putString(cacheKey, saved) || !cache.putUChar("next", (slot + 1) % 16)) {
        cache.end();
        mqtt.publishCommandReplyFor(targetUid, tombstone.c_str());
        return;
    }

    // 查找已注册的处理器
    bool found = false;
    bool success = false;
    JsonDocument replyDoc;
    JsonObject replyObj = replyDoc.to<JsonObject>();

    for (uint8_t i = 0; i < _count; i++) {
        if (strcmp(_actions[i].action, action) == 0) {
            found = true;
            success = _actions[i].handler(params, replyObj);
            break;
        }
    }

    // 构建回复
    JsonDocument reply;
    reply["request_id"] = requestId;

    if (!found) {
        reply["status"] = "error";
        reply["message"] = "unknown action";
    } else if (success) {
        reply["status"] = "ok";
        // 合并 handler 返回的附加数据
        for (JsonPair kv : replyObj) {
            reply[kv.key()] = kv.value();
        }
    } else {
        reply["status"] = "error";
        reply["message"] = replyObj["message"] | "action failed";
    }

    String response;
    serializeJson(reply, response);
    entry["reply"] = response;
    saved = "";
    serializeJson(entry, saved);
    cache.putString(cacheKey, saved);
    cache.end();
    mqtt.publishCommandReplyFor(targetUid, response.c_str());
    Serial.printf("[CMD] → 回复: %s\n", response.c_str());
}
