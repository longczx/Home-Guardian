#ifndef CONFIG_H
#define CONFIG_H

// =====================================================================
//  编译期配置（硬件 / 构建相关）
// =====================================================================

// ─── 固件版本 ─────────────────────────────────────────
#define FIRMWARE_VERSION       "1.1.0"

// ─── 上报间隔（毫秒）─────────────────────────────────
#define TELEMETRY_INTERVAL_MS  5000

// ─── 恢复出厂按键（长按 5 秒清空配置重新配网）────────
#define FACTORY_RESET_PIN      0    // BOOT 键

// ─── 平台地址：SoftAP 配网页会预填此值（用户可改）────
//  自助配网时设备用它调 /api/provisioning/register。
//  生产建议 https（注册响应含明文 MQTT 密码）。
#define DEFAULT_SERVER_URL     "https://your-server"

// ─── 传感器模块（引脚 / 使能，编译期决定接了哪些）────
#define SENSOR_DHT11_ENABLED   1
#define DHT11_PIN              4    // GPIO4

#define SENSOR_SOUND_ENABLED   1
#define SOUND_SENSOR_PIN       34   // GPIO34 (ADC1)

//  传感器 device_uid：默认由网关 UID 自动派生（如 esp32-aabbcc-temp）。
//  一般无需指定；仅当你要沿用后台已存在的固定 uid 时才取消注释：
// #define SENSOR_DHT11_UID    "sensor-temp-01"
// #define SENSOR_SOUND_UID    "sensor-sound-01"

// ─── 红外空调执行器（可选）──────────────────────────
//  接一个红外发射管到 IR_LED_PIN，即可把本设备变成"空调万能遥控器"：
//  收到后台/App 下发的 set_state 指令（全量 电源/模式/温度/风速/扫风）后，
//  用 IRremoteESP8266 合成对应品牌的红外帧发射出去。完整接入见
//  docs/guide/ac-ir-control.md。
//
//  ⚠️ 红外是开环（只发不收）——发完不保证空调真的执行了。建议本机同时接
//     DHT11（上面的传感器模块），用真实室温做闭环展示与自动化。
#define AC_IR_ENABLED          0                     // 置 1 启用红外空调执行器
#define IR_LED_PIN             25                    // 红外发射管 GPIO（需经三极管驱动，勿直连）
//  你的空调红外协议：先用库自带的 IRrecvDumpV3 例程对着实体遥控识别 Protocol，
//  再填到这里。常见：COOLIX / HITACHI_AC / HAIER_AC / MIDEA / GREE / TCL112AC …
//  海信机型多数落在 COOLIX 或 HITACHI 系；识别不出就走原始码学习（见文档）。
#define AC_PROTOCOL            decode_type_t::COOLIX

// =====================================================================
//  运行期配置（WiFi / MQTT 身份）
// =====================================================================
//
//  【推荐】自助配网：保持下面 WiFi 为占位值 "YourWiFi"，设备首次开机会进入
//   SoftAP 配网模式（热点 HG-Setup-xxxx），在手机 App「添加设备」生成配对码，
//   通过配网页填入即可自动注册上线，无需在此填写任何凭证。
//
//  【可选】手动烧录（向后兼容旧流程）：若在此填入真实 WiFi（及可选的 MQTT
//   凭证），设备将跳过配网直接连接。留占位值则走自助配网。
//   网关 UID 未指定时自动用 MAC 派生（esp32-<mac>）。

#define WIFI_SSID              "YourWiFi"
#define WIFI_PASSWORD          "YourPassword"

//  可选：连同 MQTT 凭证一起写死（则跳过自注册，需先在后台手动建设备）
#define MQTT_HOST              "192.168.1.100"
#define MQTT_PORT              1883
#define GATEWAY_UID            "gw-001"
#define MQTT_PASSWORD          "gateway_password"

#endif // CONFIG_H
