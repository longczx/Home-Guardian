#include "mqtt_manager.h"
#include <Arduino.h>

MqttManager* MqttManager::_instance = nullptr;

bool MqttManager::begin(const char* host, uint16_t port,
                        const char* gatewayUid, const char* password) {
    _instance = this;
    _host = host;              // String 拷贝
    _port = port;
    _gatewayUid = gatewayUid;
    _password = password;

    // 网关自身的主题
    snprintf(_gatewayStateTopic,   sizeof(_gatewayStateTopic),   "home/upstream/%s/state/post",    gatewayUid);
    snprintf(_gatewayCommandSub,   sizeof(_gatewayCommandSub),   "home/downstream/%s/command/set", gatewayUid);
    snprintf(_gatewayCommandReply, sizeof(_gatewayCommandReply), "home/upstream/%s/command/reply",  gatewayUid);

    _mqtt.setClient(_wifiClient);
    _mqtt.setServer(_host.c_str(), port);
    _mqtt.setCallback(_mqttCallback);
    _mqtt.setBufferSize(512);

    connect();
    return _mqtt.connected();
}

void MqttManager::connect() {
    Serial.printf("[MQTT] 连接 %s:%d (gateway=%s)...\n", _host.c_str(), _port, _gatewayUid.c_str());

    bool ok = _mqtt.connect(
        _gatewayUid.c_str(),      // client id
        _gatewayUid.c_str(),      // username (网关的 device_uid)
        _password.c_str(),        // password
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

        // 代其下子设备订阅指令（重连后同样要恢复）
        for (uint8_t i = 0; i < _subCount; i++) {
            subscribeCommandTopic(_subUids[i].c_str());
        }
    } else {
        Serial.printf("[MQTT] 连接失败, rc=%d\n", _mqtt.state());
    }
}

void MqttManager::subscribeCommandTopic(const char* uid) {
    char topic[80];
    snprintf(topic, sizeof(topic), "home/downstream/%s/command/set", uid);
    _mqtt.subscribe(topic, 1);
    Serial.printf("[MQTT] 已订阅 %s\n", topic);
}

void MqttManager::subscribeDevice(const char* uid) {
    if (!uid || !*uid) return;
    if (_subCount >= MAX_SUB_DEVICES) {
        Serial.printf("[MQTT] 子设备订阅已满，忽略 %s\n", uid);
        return;
    }
    for (uint8_t i = 0; i < _subCount; i++) {
        if (_subUids[i] == uid) return;   // 已登记
    }
    _subUids[_subCount++] = uid;

    // 已连接则立即生效；未连接时留待下次 connect() 统一订阅
    if (_mqtt.connected()) {
        subscribeCommandTopic(uid);
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

bool MqttManager::publishGatewayStateJson(const char* json) {
    return _mqtt.publish(_gatewayStateTopic, json);
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

bool MqttManager::publishDeviceStateJson(const char* uid, const char* json) {
    char topic[80];
    snprintf(topic, sizeof(topic), "home/upstream/%s/state/post", uid);
    return _mqtt.publish(topic, json);
}

bool MqttManager::publishManifest(const char* json) {
    char topic[80];
    snprintf(topic, sizeof(topic), "home/upstream/%s/manifest/post", _gatewayUid.c_str());
    return _mqtt.publish(topic, json);
}

bool MqttManager::publishCommandReply(const char* json) {
    return _mqtt.publish(_gatewayCommandReply, json);
}

bool MqttManager::publishCommandReplyFor(const char* uid, const char* json) {
    if (!uid || !*uid || _gatewayUid == uid) {
        return publishCommandReply(json);
    }
    char topic[80];
    snprintf(topic, sizeof(topic), "home/upstream/%s/command/reply", uid);
    return _mqtt.publish(topic, json);
}

void MqttManager::onCommand(CommandCallback cb) {
    _commandCb = cb;
}

void MqttManager::_mqttCallback(char* topic, uint8_t* payload, unsigned int length) {
    if (_instance && _instance->_commandCb) {
        // 从 home/downstream/{uid}/command/set 解出目标设备 uid，
        // 以区分指令是给网关自己还是给其下某个子设备（如红外空调）
        char uid[48] = {0};
        static const char* PREFIX = "home/downstream/";
        const size_t PREFIX_LEN = strlen(PREFIX);
        if (topic && strncmp(topic, PREFIX, PREFIX_LEN) == 0) {
            const char* start = topic + PREFIX_LEN;
            const char* slash = strchr(start, '/');
            size_t n = slash ? (size_t)(slash - start) : strlen(start);
            if (n >= sizeof(uid)) n = sizeof(uid) - 1;
            memcpy(uid, start, n);
            uid[n] = '\0';
        }
        if (uid[0] == '\0') {
            strncpy(uid, _instance->_gatewayUid.c_str(), sizeof(uid) - 1);
        }

        char buf[512];
        unsigned int len = min(length, (unsigned int)(sizeof(buf) - 1));
        memcpy(buf, payload, len);
        buf[len] = '\0';
        Serial.printf("[MQTT] ← 指令(%s): %s\n", uid, buf);
        _instance->_commandCb(uid, buf, len);
    }
}
