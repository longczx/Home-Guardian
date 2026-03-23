/**
 * Home Guardian - ESP32 网关固件
 *
 * ESP32 作为网关，代其下挂载的传感器设备上报遥测数据和状态。
 * 每个传感器有独立的 device_uid，通过网关的 MQTT 连接发布。
 */

#include <Arduino.h>
#include <ArduinoJson.h>
#include <WiFi.h>
#include "config.h"
#include "watchdog.h"
#include "wifi_manager.h"
#include "mqtt_manager.h"
#include "sensor_registry.h"
#include "command_handler.h"

#if SENSOR_DHT11_ENABLED
#include "sensor_dht11.h"
#endif
#if SENSOR_SOUND_ENABLED
#include "sensor_sound.h"
#endif

WifiManager wifi;
MqttManager mqtt;
SensorRegistry sensors;
CommandHandler commands;

unsigned long lastTelemetry = 0;
bool sensorsOnlinePublished = false;

// ─── 内置指令处理器 ──────────────────────────────────────

bool handlePing(const JsonObject& params, JsonObject& response) {
    return true;
}

bool handleGetInfo(const JsonObject& params, JsonObject& response) {
    response["firmware"]    = FIRMWARE_VERSION;
    response["gateway_uid"] = GATEWAY_UID;
    response["uptime_s"]    = (long)(millis() / 1000);
    response["free_heap"]   = (long)ESP.getFreeHeap();
    response["wifi_rssi"]   = (long)WiFi.RSSI();
    response["sensor_count"] = sensors.count();
    return true;
}

bool handleReboot(const JsonObject& params, JsonObject& response) {
    response["message"] = "rebooting in 1s";
    delay(1000);
    ESP.restart();
    return true;
}

// ─── setup / loop ────────────────────────────────────────

void setup() {
    Serial.begin(115200);
    delay(100);
    Serial.println("\n=============================");
    Serial.printf("Home Guardian Gateway v%s\n", FIRMWARE_VERSION);
    Serial.printf("Gateway UID: %s\n", GATEWAY_UID);
    Serial.println("=============================\n");

    // 1. 看门狗
    watchdog_init(8000);

    // 2. WiFi
    wifi.begin(WIFI_SSID, WIFI_PASSWORD);

    // 3. 注册传感器（每个传感器带独立 UID）
    #if SENSOR_DHT11_ENABLED
    {
        static SensorDHT11 dht(DHT11_PIN, SENSOR_DHT11_UID);
        sensors.registerSensor(&dht);
    }
    #endif
    #if SENSOR_SOUND_ENABLED
    {
        static SensorSound sound(SOUND_SENSOR_PIN, SENSOR_SOUND_UID);
        sensors.registerSensor(&sound);
    }
    #endif
    sensors.beginAll();
    Serial.printf("[Main] 已注册 %d 个传感器\n", sensors.count());

    // 4. 指令
    commands.registerAction("ping",     handlePing);
    commands.registerAction("get_info", handleGetInfo);
    commands.registerAction("reboot",   handleReboot);

    // 5. MQTT（用网关 UID 认证）
    mqtt.begin(MQTT_HOST, MQTT_PORT, GATEWAY_UID, MQTT_PASSWORD);
    mqtt.onCommand([](const char* payload, unsigned int len) {
        commands.handle(payload, len, mqtt);
    });

    Serial.println("[Main] 初始化完成\n");
}

void loop() {
    watchdog_feed();
    wifi.loop();
    mqtt.loop();

    // MQTT 连接成功后，为所有传感器发一次 online 状态
    if (mqtt.isConnected() && !sensorsOnlinePublished) {
        sensorsOnlinePublished = true;
        for (uint8_t i = 0; i < sensors.count(); i++) {
            ISensor* s = sensors.get(i);
            if (s) {
                mqtt.publishSensorState(s->uid(), true);
                Serial.printf("[Main] 传感器上线: %s\n", s->uid());
            }
        }
    }
    if (!mqtt.isConnected()) {
        sensorsOnlinePublished = false;
    }

    // 定时遥测：逐传感器独立发布
    if (mqtt.isConnected() && (millis() - lastTelemetry >= TELEMETRY_INTERVAL_MS)) {
        lastTelemetry = millis();

        for (uint8_t i = 0; i < sensors.count(); i++) {
            ISensor* s = sensors.get(i);
            if (!s) continue;

            JsonDocument doc;
            JsonObject obj = doc.to<JsonObject>();

            if (s->read(obj)) {
                char buffer[256];
                serializeJson(doc, buffer, sizeof(buffer));
                mqtt.publishSensorTelemetry(s->uid(), buffer);
                Serial.printf("[%s] → %s\n", s->uid(), buffer);
            } else {
                // 传感器读取失败 → 标记该传感器离线
                mqtt.publishSensorState(s->uid(), false);
                Serial.printf("[%s] 读取失败，标记离线\n", s->uid());
            }
        }
    }
}
