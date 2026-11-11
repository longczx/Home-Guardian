#pragma once
#include <string>
class MqttManager {
public:
    std::string lastReply;
    bool isGatewayTarget(const char* uid) const { return uid && std::string(uid) == "gateway"; }
    bool publishCommandReplyFor(const char*, const char* json) { lastReply = json; return true; }
};
