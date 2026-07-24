#ifndef MQTT_MANAGER_H
#define MQTT_MANAGER_H

#include <Arduino.h>
#include <WiFiClient.h>
#include <PubSubClient.h>

class MqttManager {
public:
    typedef void (*CommandCallback)(const char* payload, unsigned int length);

    bool begin(const char* host, uint16_t port,
               const char* gatewayUid, const char* password);
    void loop();
    bool isConnected();

    // 网关自身状态
    bool publishGatewayState(bool online);

    // 网关级完整状态上报（执行器用）：发布到 home/upstream/{uid}/state/post，
    // 后端 state 字段落库并推 WS（reported=true）。json 形如 {"status":"online","state":{...}}
    bool publishGatewayStateJson(const char* json);

    // 传感器级发布（用传感器自己的 device_uid 构建主题）
    bool publishSensorTelemetry(const char* sensorUid, const char* json);
    bool publishSensorState(const char* sensorUid, bool online);

    // 指令回复（网关级）
    bool publishCommandReply(const char* json);

    void onCommand(CommandCallback cb);

private:
    WiFiClient _wifiClient;
    PubSubClient _mqtt;
    char _gatewayStateTopic[80];
    char _gatewayCommandSub[80];
    char _gatewayCommandReply[80];
    // 存 String 拷贝：凭证来自运行期(NVS)的临时 buffer，不能存裸指针
    String _host;
    uint16_t _port = 1883;
    String _gatewayUid;
    String _password;
    unsigned long _lastReconnect = 0;
    unsigned long _reconnectInterval = 5000;
    CommandCallback _commandCb = nullptr;

    void connect();
    static MqttManager* _instance;
    static void _mqttCallback(char* topic, uint8_t* payload, unsigned int length);
};

#endif
