/**
 * Home Guardian - ESP32 网关固件
 *
 * 支持两种上线方式：
 *   1. 自助配网（无凭证时）：SoftAP 配网页 → 填 WiFi + 配对码 → 自注册拿 MQTT 凭证 → 上线
 *   2. 手动烧录（config.h 填了真实凭证）：直接连接（向后兼容）
 *
 * 启动状态机（见 setup）：无 WiFi → 配网模式；有 WiFi 无 MQTT → 注册；齐全 → 正常运行。
 * 长按 BOOT 键 5 秒恢复出厂（清空 NVS 重新配网）。
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
#include "config_store.h"
#include "provisioning.h"
#include "registration.h"

#if SENSOR_DHT11_ENABLED
#include "sensor_dht11.h"
#endif
#if SENSOR_SOUND_ENABLED
#include "sensor_sound.h"
#endif

#ifndef FACTORY_RESET_PIN
#define FACTORY_RESET_PIN 0   // BOOT 键
#endif

WifiManager  wifi;
MqttManager  mqtt;
SensorRegistry sensors;
CommandHandler commands;
ConfigStore  store;
Provisioning provisioning;

enum Mode { MODE_PROVISION, MODE_NORMAL };
Mode mode = MODE_NORMAL;

unsigned long lastTelemetry = 0;
bool sensorsOnlinePublished = false;
unsigned long btnPressStart = 0;

// ─── 内置指令处理器 ──────────────────────────────────────

bool handlePing(const JsonObject& params, JsonObject& response) {
    return true;
}

bool handleGetInfo(const JsonObject& params, JsonObject& response) {
    response["firmware"]     = FIRMWARE_VERSION;
    response["gateway_uid"]  = store.gatewayUid();
    response["uptime_s"]     = (long)(millis() / 1000);
    response["free_heap"]    = (long)ESP.getFreeHeap();
    response["wifi_rssi"]    = (long)WiFi.RSSI();
    response["sensor_count"] = sensors.count();
    return true;
}

bool handleReboot(const JsonObject& params, JsonObject& response) {
    response["message"] = "rebooting in 1s";
    delay(1000);
    ESP.restart();
    return true;
}

// ─── 传感器注册（uid 优先用 config.h 指定，否则由网关 uid 派生）──────

void registerSensors() {
    String gw = store.gatewayUid();

#if SENSOR_DHT11_ENABLED
    {
    #ifdef SENSOR_DHT11_UID
        static String dhtUid = SENSOR_DHT11_UID;
    #else
        static String dhtUid = gw + "-temp";
    #endif
        static SensorDHT11 dht(DHT11_PIN, dhtUid.c_str());
        sensors.registerSensor(&dht);
    }
#endif
#if SENSOR_SOUND_ENABLED
    {
    #ifdef SENSOR_SOUND_UID
        static String soundUid = SENSOR_SOUND_UID;
    #else
        static String soundUid = gw + "-sound";
    #endif
        static SensorSound sound(SOUND_SENSOR_PIN, soundUid.c_str());
        sensors.registerSensor(&sound);
    }
#endif

    sensors.beginAll();
    Serial.printf("[Main] 已注册 %d 个传感器\n", sensors.count());
}

// ─── 正常运行启动：连 WiFi →（必要时注册）→ 连 MQTT ──────

void startNormal() {
    wifi.begin(store.wifiSsid().c_str(), store.wifiPass().c_str());

    // 无 MQTT 凭证 → 先自注册
    if (!store.hasMqtt()) {
        Serial.println("[Main] 无 MQTT 凭证，等待 WiFi 后自注册...");
        unsigned long t = millis();
        while (!wifi.isConnected() && millis() - t < 30000) {
            delay(500);
            watchdog_feed();
        }
        if (!wifi.isConnected()) {
            Serial.println("[Main] WiFi 连接失败，重启重试");
            delay(2000);
            ESP.restart();
        }

        int tries = 0;
        while (!store.hasMqtt() && tries < 5) {
            if (Registration::run(store, sensors, FIRMWARE_VERSION)) break;
            tries++;
            Serial.printf("[Main] 注册失败(%d/5)，3 秒后重试\n", tries);
            delay(3000);
            watchdog_feed();
        }
        if (!store.hasMqtt()) {
            Serial.println("[Main] 多次注册失败，重启重试");
            delay(2000);
            ESP.restart();
        }
    }

    mqtt.begin(store.mqttHost().c_str(), store.mqttPort(),
               store.gatewayUid().c_str(), store.mqttPass().c_str());
    mqtt.onCommand([](const char* payload, unsigned int len) {
        commands.handle(payload, len, mqtt);
    });
}

// ─── 恢复出厂：长按 BOOT 键 5 秒 ──────

void checkFactoryReset() {
    if (digitalRead(FACTORY_RESET_PIN) == LOW) {
        if (btnPressStart == 0) {
            btnPressStart = millis();
        } else if (millis() - btnPressStart > 5000) {
            Serial.println("[Main] 恢复出厂：清空配置并重启");
            store.clearAll();
            delay(500);
            ESP.restart();
        }
    } else {
        btnPressStart = 0;
    }
}

// ─── setup / loop ────────────────────────────────────────

void setup() {
    Serial.begin(115200);
    delay(100);
    Serial.println("\n=============================");
    Serial.printf("Home Guardian Gateway v%s\n", FIRMWARE_VERSION);
    Serial.println("=============================\n");

    watchdog_init(8000);
    pinMode(FACTORY_RESET_PIN, INPUT_PULLUP);

    store.begin();
    Serial.printf("[Main] 网关 UID: %s\n", store.gatewayUid().c_str());

    commands.registerAction("ping",     handlePing);
    commands.registerAction("get_info", handleGetInfo);
    commands.registerAction("reboot",   handleReboot);

    registerSensors();

    if (!store.hasWifi()) {
        mode = MODE_PROVISION;
        provisioning.begin(&store);
        Serial.println("[Main] 未配置 WiFi → 进入配网模式");
    } else {
        mode = MODE_NORMAL;
        startNormal();
        Serial.println("[Main] 初始化完成\n");
    }
}

void loop() {
    watchdog_feed();
    checkFactoryReset();

    // ── 配网模式：只跑门户 ──
    if (mode == MODE_PROVISION) {
        provisioning.loop();
        if (provisioning.isDone()) {
            Serial.println("[Main] 配网信息已保存，重启进入注册\n");
            delay(1000);
            ESP.restart();
        }
        return;
    }

    // ── 正常模式 ──
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
                mqtt.publishSensorState(s->uid(), false);
                Serial.printf("[%s] 读取失败，标记离线\n", s->uid());
            }
        }
    }
}
