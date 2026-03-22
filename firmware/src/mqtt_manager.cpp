#include "mqtt_manager.h"
#include <Arduino.h>

MqttManager* MqttManager::_instance = nullptr;

bool MqttManager::begin(const char* host, uint16_t port,
                        const char* gatewayUid, const char* password) {
    _instance = this;
    _host = host;
    _port = port;
    _gatewayUid = gatewayUid;
    _password = password;

    // 网关自身的主题
    snprintf(_gatewayStateTopic,   sizeof(_gatewayStateTopic),   "home/upstream/%s/state/post",    gatewayUid);
    snprintf(_gatewayCommandSub,   sizeof(_gatewayCommandSub),   "home/downstream/%s/command/set", gatewayUid);
    snprintf(_gatewayCommandReply, sizeof(_gatewayCommandReply), "home/upstream/%s/command/reply",  gatewayUid);

    _mqtt.setClient(_wifiClient);
    _mqtt.setServer(host, port);
    _mqtt.setCallback(_mqttCallback);
    _mqtt.setBufferSize(512);

    connect();
    return _mqtt.connected();
}

void MqttManager::connect() {
    Serial.printf("[MQTT] 连接 %s:%d (gateway=%s)...\n", _host, _port, _gatewayUid);

    bool ok = _mqtt.connect(
        _gatewayUid,              // client id
        _gatewayUid,              // username (网关的 device_uid)
        _password,                // password
        _gatewayStateTopic,       // will topic (网关的 state)
        1,                        // will QoS
        false,                    // will retain
        "{\"status\":\"offline\"}" // LWT payload
    );

    if (ok) {
        Serial.println("[MQTT] 已连接");
        _reconnectInterval = 5000;

        // 网关上线
        _mqtt.publish(_gatewayStateTopic, "{\"status\":\"online\"}", true);

        // 订阅网关级指令
        _mqtt.subscribe(_gatewayCommandSub, 1);
        Serial.printf("[MQTT] 已订阅 %s\n", _gatewayCommandSub);
    } else {
        Serial.printf("[MQTT] 连接失败, rc=%d\n", _mqtt.state());
    }
}

void MqttManager::loop() {
    if (_mqtt.connected()) {
        _mqtt.loop();
        return;
    }

    unsigned long now = millis();
    if (now - _lastReconnect >= _reconnectInterval) {
        _lastReconnect = now;
        connect();
        if (!_mqtt.connected()) {
            _reconnectInterval = min(_reconnectInterval * 2, (unsigned long)60000);
        }
    }
}

bool MqttManager::isConnected() {
    return _mqtt.connected();
}

bool MqttManager::publishGatewayState(bool online) {
    const char* payload = online ? "{\"status\":\"online\"}" : "{\"status\":\"offline\"}";
    return _mqtt.publish(_gatewayStateTopic, payload, true);
}

bool MqttManager::publishSensorTelemetry(const char* sensorUid, const char* json) {
    char topic[80];
    snprintf(topic, sizeof(topic), "home/upstream/%s/telemetry/post", sensorUid);
    return _mqtt.publish(topic, json);
}

bool MqttManager::publishSensorState(const char* sensorUid, bool online) {
    char topic[80];
    snprintf(topic, sizeof(topic), "home/upstream/%s/state/post", sensorUid);
    const char* payload = online ? "{\"status\":\"online\"}" : "{\"status\":\"offline\"}";
    return _mqtt.publish(topic, payload, true);
}

bool MqttManager::publishCommandReply(const char* json) {
    return _mqtt.publish(_gatewayCommandReply, json);
}

void MqttManager::onCommand(CommandCallback cb) {
    _commandCb = cb;
}

void MqttManager::_mqttCallback(char* topic, uint8_t* payload, unsigned int length) {
    if (_instance && _instance->_commandCb) {
        char buf[512];
        unsigned int len = min(length, (unsigned int)(sizeof(buf) - 1));
        memcpy(buf, payload, len);
        buf[len] = '\0';
        Serial.printf("[MQTT] ← 指令: %s\n", buf);
        _instance->_commandCb(buf, len);
    }
}
