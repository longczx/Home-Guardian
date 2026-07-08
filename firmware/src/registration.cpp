#include "registration.h"
#include <HTTPClient.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>

bool Registration::run(ConfigStore& store, SensorRegistry& sensors, const char* fw) {
    String url = store.serverUrl();
    if (url.length() == 0) {
        Serial.println("[注册] 未配置平台地址 server_url");
        return false;
    }
    String endpoint = url + "/api/provisioning/register";

    // ── 构建请求体：网关 + 子传感器自述
    JsonDocument doc;
    doc["provision_code"] = store.provisionCode();
    JsonObject gw = doc["gateway"].to<JsonObject>();
    gw["device_uid"]       = store.gatewayUid();
    gw["name"]             = "Home Guardian 网关";
    gw["model"]            = "esp32";
    gw["firmware_version"] = fw;

    JsonArray arr = doc["sensors"].to<JsonArray>();
    for (uint8_t i = 0; i < sensors.count(); i++) {
        ISensor* s = sensors.get(i);
        if (!s) continue;
        JsonObject o = arr.add<JsonObject>();
        o["device_uid"] = s->uid();   // 与后续遥测发布使用的 uid 一致
        o["name"]       = s->name();
        o["type"]       = "sensor";
    }

    String body;
    serializeJson(doc, body);

    // ── 发送（https 用 WiFiClientSecure；自签证书开发期 setInsecure）
    bool https = url.startsWith("https");
    HTTPClient http;
    WiFiClientSecure secure;
    WiFiClient plain;
    bool ok;
    if (https) {
        secure.setInsecure();  // 生产可换成 setCACert() 固定证书
        ok = http.begin(secure, endpoint);
    } else {
        ok = http.begin(plain, endpoint);
    }
    if (!ok) {
        Serial.println("[注册] HTTP begin 失败");
        return false;
    }
    http.addHeader("Content-Type", "application/json");
    http.setTimeout(10000);

    int status = http.POST(body);
    String resp = http.getString();
    http.end();

    Serial.printf("[注册] POST %s -> %d\n", endpoint.c_str(), status);
    Serial.println(resp);

    if (status != 200 && status != 201) {
        return false;
    }

    JsonDocument rd;
    if (deserializeJson(rd, resp)) {
        Serial.println("[注册] 响应 JSON 解析失败");
        return false;
    }
    if ((int)(rd["code"] | -1) != 0) {
        return false;
    }

    JsonObject mqtt = rd["data"]["mqtt"];
    String host    = mqtt["host"]     | "";
    uint16_t port  = mqtt["port"]     | 1883;
    String user    = mqtt["username"] | "";
    String pass    = mqtt["password"] | "";

    if (user.length() == 0 || pass.length() == 0) {
        Serial.println("[注册] 响应缺少 MQTT 凭证");
        return false;
    }

    store.saveMqtt(host, port, user, pass);
    Serial.printf("[注册] 成功: mqtt=%s@%s:%u\n", user.c_str(), host.c_str(), port);
    return true;
}
