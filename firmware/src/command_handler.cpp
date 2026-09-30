#include "command_handler.h"
#include <Arduino.h>
#include <Preferences.h>

void CommandHandler::registerAction(const char* action, ActionHandler handler) {
    if (_count < MAX_ACTIONS) {
        _actions[_count++] = { action, handler };
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
    Preferences cache;
    if (!cache.begin("hg_commands", false)) return;
    // 持久化最近 16 条回执，MQTT QoS1 重投或重启后不重复操作硬件。
    for (uint8_t i = 0; i < 16; i++) {
        String key = "c" + String(i);
        String saved = cache.getString(key.c_str(), "");
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
    String cacheKey = "c" + String(slot);
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
    if (!cache.putString(cacheKey.c_str(), saved) || !cache.putUChar("next", (slot + 1) % 16)) {
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
        reply["message"] = "action failed";
    }

    String response;
    serializeJson(reply, response);
    entry["reply"] = response;
    saved = "";
    serializeJson(entry, saved);
    cache.putString(cacheKey.c_str(), saved);
    cache.end();
    mqtt.publishCommandReplyFor(targetUid, response.c_str());
    Serial.printf("[CMD] → 回复: %s\n", response.c_str());
}
