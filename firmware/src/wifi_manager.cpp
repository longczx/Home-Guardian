#include "wifi_manager.h"
#include <WiFi.h>
#include <Arduino.h>

void WifiManager::begin(const char* ssid, const char* password) {
    _ssid = ssid;
    _password = password;

    WiFi.mode(WIFI_STA);
    WiFi.setAutoReconnect(true);
    WiFi.begin(ssid, password);

    Serial.printf("[WiFi] 正在连接 %s", ssid);

    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 30000) {
        delay(500);
        Serial.print(".");
    }

    if (WiFi.status() == WL_CONNECTED) {
        Serial.printf("\n[WiFi] 已连接, IP: %s\n", WiFi.localIP().toString().c_str());
    } else {
        Serial.println("\n[WiFi] 连接超时，将在后台重试");
    }
}

void WifiManager::loop() {
    if (millis() - _lastCheck < CHECK_INTERVAL_MS) return;
    _lastCheck = millis();

    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[WiFi] 断线，尝试重连...");
        WiFi.reconnect();
    }
}

bool WifiManager::isConnected() {
    return WiFi.status() == WL_CONNECTED;
}
