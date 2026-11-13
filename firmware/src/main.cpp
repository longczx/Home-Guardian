/**
 * Home Guardian - ESP32 网关固件
 *
 * 支持两种上线方式：
 *   1. 自助配网（无凭证时）：SoftAP 配网页 → 填 WiFi + 配对码 → 自注册拿 MQTT 凭证 → 上线
 *   2. 手动烧录（config.h 填了真实凭证）：直接连接（向后兼容）
 *
 * 启动状态机（见 setup）：无 WiFi → 配网模式；有 WiFi 无 MQTT → 注册；齐全 → 正常运行。
 * 长按 BOOT 键 5 秒清除联网身份并进入配网（保留模块/红外设置）。
 *
 * 设备热插拔：挂载哪些传感器/执行器由 NVS 的模块清单（ModuleStore）在运行期决定，
 * App 下发 add_module / remove_module 即可增删，增删后上报 manifest 让平台同步，
 * 无需改 config.h 重新烧录。config.h 的开关仅用于首次开机播种默认清单。
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
#include "module_store.h"
#include "provisioning.h"
#include "registration.h"
#include "reset_button.h"
#include "module_manager.h"
#include "gateway_module_runtime.h"

// 模块由运行期清单决定，编译期无从得知接了什么，故各驱动一律编入
#include "sensor_dht11.h"
#include "sensor_sound.h"
#include "ac_ir.h"

#ifndef FACTORY_RESET_PIN
#define FACTORY_RESET_PIN 0   // BOOT 键
#endif

WifiManager  wifi;
MqttManager  mqtt;
SensorRegistry sensors;
CommandHandler commands;
ConfigStore  store;
ModuleStore  modules;
Provisioning provisioning;

// 红外空调执行器：清单里有 ac_ir 模块时才实例化。
// 它在后台是一台挂在本网关下的独立设备（type=ac），有自己的在线状态与控制卡。
AcIr* acIr = nullptr;
String acUid;
GatewayModuleRuntime moduleRuntime(sensors, acIr, FACTORY_RESET_PIN);
ModuleManager* moduleManager = nullptr;
unsigned long acSubscribeAt = 0;
uint8_t acSubscribeRetries = 0;

enum Mode { MODE_PROVISION, MODE_NORMAL };
Mode mode = MODE_NORMAL;

unsigned long lastTelemetry = 0;
unsigned long lastHeartbeat = 0;
unsigned long rebootAt = 0;
bool sensorsOnlinePublished = false;
bool mqttStarted = false;
unsigned long lastRegistration = 0;
bool registrationAttempted = false;

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
    response["module_count"] = modules.count();
    return true;
}

bool handleReboot(const JsonObject& params, JsonObject& response) {
    response["message"] = "rebooting in 1s";
    rebootAt = millis() + 1000;
    return true;
}

// 红外空调：全量状态发射红外，回执带当前状态，并主动上报 state/post
bool handleSetState(const JsonObject& params, JsonObject& response) {
    if (!acIr) {
        response["message"] = "no ac module";
        return false;
    }
    bool ok = acIr->apply(params, response);

    // 只在发送成功后上报网关最近发送的状态，不代表空调实测状态。
    if (ok && mqtt.isConnected()) {
        JsonDocument postDoc;
        postDoc["status"] = "online";
        JsonObject st = postDoc["state"].to<JsonObject>();
        acIr->fillState(st);
        char buf[256];
        serializeJson(postDoc, buf, sizeof(buf));
        mqtt.publishDeviceStateJson(acUid.c_str(), buf);
    }
    return ok;
}

// ─── 运行期模块装配（清单来自 NVS，可热插拔）──────────────

// 把一条清单记录变成活的驱动实例
static bool instantiate(const ModuleSlot& s) {
    if (s.type == "dht11") {
        sensors.registerSensor(new SensorDHT11(s.pin, s.uid.c_str()));
        return true;
    }
    if (s.type == "sound") {
        sensors.registerSensor(new SensorSound(s.pin, s.uid.c_str()));
        return true;
    }
    if (s.type == "ac_ir") {
        if (acIr) return false;          // 红外发射器只支持一路
        acIr = new AcIr(s.pin, AC_PROTOCOL);
        acIr->begin();
        acUid = s.uid;
        return true;
    }
    return false;
}

void buildModules() {
    for (uint8_t i = 0; i < modules.count(); i++) {
        const ModuleSlot* s = modules.get(i);
        if (s) instantiate(*s);
    }
    sensors.beginAll();
    Serial.printf("[Main] 已装配 %d 个传感器%s\n", sensors.count(), acIr ? " + 红外空调" : "");
}

// 子设备清单：注册与热插拔同步都用它，保证两条路径描述一致
void fillManifestDevices(JsonArray& arr) {
    for (uint8_t i = 0; i < sensors.count(); i++) {
        ISensor* s = sensors.get(i);
        if (!s) continue;
        JsonObject o = arr.add<JsonObject>();
        o["device_uid"] = s->uid();
        o["name"]       = s->name();
        o["type"]       = "sensor";
        JsonArray mf = o["metric_fields"].to<JsonArray>();
        s->describeFields(mf);
    }
    if (acIr) {
        JsonObject o = arr.add<JsonObject>();
        o["device_uid"] = acUid;
        o["name"]       = "空调";
        o["type"]       = "ac";
    }
}

// 上报当前挂载的子设备，平台据此自动增补/下线（热插拔的关键一步）
void publishManifest() {
    if (!mqtt.isConnected()) return;
    JsonDocument doc;
    JsonArray arr = doc["devices"].to<JsonArray>();
    fillManifestDevices(arr);

    String json;
    serializeJson(doc, json);
    mqtt.publishManifest(json.c_str());
    Serial.printf("[Main] 已上报子设备清单(%d 个)\n", arr.size());
}

// ─── 热插拔指令：运行期增删模块，免烧录 ────────────────────

bool handleListModules(const JsonObject&, JsonObject& response) {
    return moduleManager && moduleManager->list(response);
}

bool handleAddModule(const JsonObject& params, JsonObject& response) {
    if (!moduleManager || !moduleManager->add(params, response)) return false;
    String uid = response["uid"] | "";
    if (String(params["type"] | "") == "ac_ir") {
        acUid = uid;
        acSubscribeRetries = 8;
        acSubscribeAt = millis() + 1000;  // 平台清单处理后再订阅，重连时也会恢复。
    }
    publishManifest();
    mqtt.publishSensorState(uid.c_str(), true);
    return true;
}

bool handleUpdateModule(const JsonObject& params, JsonObject& response) {
    if (!moduleManager || !moduleManager->update(params, response)) return false;
    publishManifest();
    return true;
}

bool handleRemoveModule(const JsonObject& params, JsonObject& response) {
    String uid = params["uid"] | "";
    if (!moduleManager || !moduleManager->remove(params, response)) return false;
    if (uid == acUid) { mqtt.unsubscribeDevice(uid.c_str()); acUid = ""; acSubscribeAt = 0; }
    mqtt.publishSensorState(uid.c_str(), false);
    publishManifest();
    return true;
}

// ─── 正常运行启动：连 WiFi →（必要时注册）→ 连 MQTT ──────

void startNormal() {
    wifi.begin(store.wifiSsid().c_str(), store.wifiPass().c_str());

    // 联网重试在 loop 中分步进行，不再在 setup 等待或反复重启。
}

void serviceConnection() {
    if (!wifi.isConnected()) return;
    if (!store.hasMqtt()) {
        if (registrationAttempted && millis() - lastRegistration < 3000) return;
        registrationAttempted = true;
        const bool registered = Registration::run(store, sensors, FIRMWARE_VERSION,
                                   acIr ? acUid.c_str() : nullptr, "空调", "ac");
        lastRegistration = millis();
        watchdog_feed();
        if (!registered) {
            Serial.println("[Main] 注册失败，3 秒后重试；可长按 BOOT 重新配网");
            return;
        }
    }
    if (mqttStarted) { mqtt.loop(); return; }
    mqttStarted = true;
    mqtt.begin(store.mqttHost().c_str(), store.mqttPort(),
               store.gatewayUid().c_str(), store.mqttPass().c_str());
    if (acIr) {
        // 先登记，再持续恢复订阅，以覆盖平台清单处理晚于首次订阅的情况。
        mqtt.subscribeDevice(acUid.c_str());
        acSubscribeRetries = 8;
        acSubscribeAt = millis() + 1000;
    }
    mqtt.onCommand([](const char* targetUid, const char* payload, unsigned int len) {
        commands.handle(targetUid, payload, len, mqtt);
    });
}

// ─── setup / loop ────────────────────────────────────────

void setup() {
    Serial.begin(115200);
    delay(100);
    Serial.println("\n=============================");
    Serial.printf("Home Guardian Gateway v%s\n", FIRMWARE_VERSION);
    Serial.println("=============================\n");

    // 覆盖 SDK 的 DNS/TLS 最坏等待；BOOT 任务独立，响应不受该超时影响。
    watchdog_init(30000);

    store.begin();
    if (!beginResetButton(FACTORY_RESET_PIN)) {
        // 不能在缺少重置能力时继续联网。
        Serial.println("[Main] BOOT 任务创建失败，重启");
        ESP.restart();
        return;
    }
    Serial.printf("[Main] 网关 UID: %s\n", store.gatewayUid().c_str());

    if (!store.hasWifi()) {
        mode = MODE_PROVISION;
        Serial.println("[Main] 未配置 WiFi → 进入配网模式（模块配置保留，配网时不初始化驱动）");
        provisioning.begin(&store);
        return;
    }
    Serial.println("[Main] 已保存 WiFi → 正常联网；长按 BOOT 5 秒可重新配网");

    commands.registerAction("ping",     handlePing);
    commands.registerAction("get_info", handleGetInfo);
    commands.registerAction("reboot",   handleReboot);
    commands.registerAction("set_state", handleSetState);
    // 热插拔：App 可在运行期增删模块，无需重新烧录
    commands.registerAction("list_modules",  handleListModules, true);
    commands.registerAction("add_module",    handleAddModule, true);
    commands.registerAction("remove_module", handleRemoveModule, true);
    commands.registerAction("update_module", handleUpdateModule, true);

    // 模块清单来自 NVS；首次开机按 config.h 播种
    modules.begin(store.gatewayUid());
    buildModules();
    moduleManager = new ModuleManager(modules, moduleRuntime, store.gatewayUid(), FACTORY_RESET_PIN, psramFound());

    mode = MODE_NORMAL;
    startNormal();
    Serial.println("[Main] 初始化完成\n");
}

void loop() {
    watchdog_feed();
    delay(5);  // 让出 CPU 给系统/按键任务

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
    serviceConnection();
    if (acSubscribeAt && acIr && mqtt.isConnected() && (long)(millis() - acSubscribeAt) >= 0) {
        mqtt.subscribeDevice(acUid.c_str());
        if (acSubscribeRetries) --acSubscribeRetries;
        acSubscribeAt = millis() + (acSubscribeRetries ? 2000 : 30000);
    }

    // MQTT 连接成功后，先同步子设备清单，再逐个报在线
    if (mqtt.isConnected() && !sensorsOnlinePublished) {
        sensorsOnlinePublished = true;

        // 每次上线都同步一次清单：中途改过模块的，平台在这里自动对齐，
        // 无需重新配网（这正是热插拔不必恢复出厂的原因）
        publishManifest();

        for (uint8_t i = 0; i < sensors.count(); i++) {
            ISensor* s = sensors.get(i);
            if (s) {
                mqtt.publishSensorState(s->uid(), true);
                Serial.printf("[Main] 传感器上线: %s\n", s->uid());
            }
        }
        if (acIr) {
            // 空调子设备同样要独立报在线（网关离线时后端按 gateway_uid 批量下线它）
            mqtt.publishSensorState(acUid.c_str(), true);
            Serial.printf("[Main] 空调上线: %s\n", acUid.c_str());
        }
    }
    if (!mqtt.isConnected()) {
        sensorsOnlinePublished = false;
    }

    if (rebootAt && (long)(millis() - rebootAt) >= 0) ESP.restart();
    // 应用层心跳独立于 MQTT keepalive，避免无遥测设备被超时扫描误判离线。
    if (mqtt.isConnected() && millis() - lastHeartbeat >= 30000) {
        lastHeartbeat = millis();
        mqtt.publishGatewayState(true);
        if (acIr) mqtt.publishSensorState(acUid.c_str(), true);
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
