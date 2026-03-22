#include "command_handler.h"
#include <Arduino.h>

void CommandHandler::registerAction(const char* action, ActionHandler handler) {
    if (_count < MAX_ACTIONS) {
        _actions[_count++] = { action, handler };
    }
}

void CommandHandler::handle(const char* payload, unsigned int length, MqttManager& mqtt) {
    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, payload, length);
    if (err) {
        Serial.printf("[CMD] JSON 解析失败: %s\n", err.c_str());
        return;
    }

    const char* action    = doc["action"] | "";
    const char* requestId = doc["request_id"] | "";
    JsonObject params     = doc["params"].as<JsonObject>();

    Serial.printf("[CMD] 收到指令: action=%s, request_id=%s\n", action, requestId);

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

    char buf[256];
    serializeJson(reply, buf, sizeof(buf));
    mqtt.publishCommandReply(buf);
    Serial.printf("[CMD] → 回复: %s\n", buf);
}
