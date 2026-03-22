#ifndef CONFIG_H
#define CONFIG_H

// ─── WiFi ─────────────────────────────────────────────
#define WIFI_SSID              "YourWiFi"
#define WIFI_PASSWORD          "YourPassword"

// ─── MQTT Broker ──────────────────────────────────────
#define MQTT_HOST              "192.168.1.100"
#define MQTT_PORT              1883

// ─── 网关标识（用于 MQTT 认证）────────────────────────
#define GATEWAY_UID            "gw-001"
#define MQTT_PASSWORD          "gateway_password"

// ─── 上报间隔（毫秒）─────────────────────────────────
#define TELEMETRY_INTERVAL_MS  5000

// ─── 传感器模块（每个传感器有独立的 device_uid）──────
#define SENSOR_DHT11_ENABLED   1
#define SENSOR_DHT11_UID       "sensor-temp-01"
#define DHT11_PIN              4    // GPIO4

#define SENSOR_SOUND_ENABLED   1
#define SENSOR_SOUND_UID       "sensor-sound-01"
#define SOUND_SENSOR_PIN       34   // GPIO34 (ADC1)

// ─── 固件版本 ─────────────────────────────────────────
#define FIRMWARE_VERSION       "1.0.0"

#endif // CONFIG_H
