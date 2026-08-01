#ifndef COMMAND_HANDLER_H
#define COMMAND_HANDLER_H

#include "mqtt_manager.h"
#include <ArduinoJson.h>

class CommandHandler {
public:
    typedef bool (*ActionHandler)(const JsonObject& params, JsonObject& response);

    void registerAction(const char* action, ActionHandler handler);

    // targetUid：指令的目标设备（网关自身或其下子设备），回复按它发到对应的 reply 主题
    void handle(const char* targetUid, const char* payload, unsigned int length, MqttManager& mqtt);

private:
    static constexpr uint8_t MAX_ACTIONS = 16;
    struct ActionEntry {
        const char* action;
        ActionHandler handler;
    };
    ActionEntry _actions[MAX_ACTIONS] = {};
    uint8_t _count = 0;
};

#endif
