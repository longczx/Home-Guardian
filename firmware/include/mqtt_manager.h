#ifndef MQTT_MANAGER_H
#define MQTT_MANAGER_H

#include <Arduino.h>
#include <WiFiClient.h>
#include <PubSubClient.h>

class MqttManager {
public:
    // targetUid：指令投递给哪台设备（从主题里解出），网关自身或其下子设备
    typedef void (*CommandCallback)(const char* targetUid, const char* payload, unsigned int length);

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

    // 订阅子设备的指令主题：子设备不直连 MQTT，由网关代收
    // （后端 ACL 已放行网关订阅其下子设备的 downstream，见 DeviceService::checkMqttAcl）
    void subscribeDevice(const char* uid);

    // 子设备完整状态上报（子执行器用）：home/upstream/{uid}/state/post
    bool publishDeviceStateJson(const char* uid, const char* json);

    // 子设备清单上报（设备热插拔）：home/upstream/{网关uid}/manifest/post
    // 平台据此自动增补/下线子设备，免去重新配网
    bool publishManifest(const char* json);

    // 指令回复（网关级）
    bool publishCommandReply(const char* json);

    // 指令回复（发到指定设备的 reply 主题，子执行器用）
    bool publishCommandReplyFor(const char* uid, const char* json);

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

    // 代收指令的子设备 uid（重连后需重新订阅，故须留存）
    static constexpr uint8_t MAX_SUB_DEVICES = 4;
    String _subUids[MAX_SUB_DEVICES];
    uint8_t _subCount = 0;

    void subscribeCommandTopic(const char* uid);

    void connect();
    static MqttManager* _instance;
    static void _mqttCallback(char* topic, uint8_t* payload, unsigned int length);
};

#endif
