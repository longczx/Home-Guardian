#ifndef CONFIG_H
#define CONFIG_H

// =====================================================================
//  编译期配置（硬件 / 构建相关）
// =====================================================================

// ─── 固件版本 ─────────────────────────────────────────
#define FIRMWARE_VERSION       "1.4.1"

// ─── 上报间隔（毫秒）─────────────────────────────────
#define TELEMETRY_INTERVAL_MS  5000

// ─── 恢复出厂按键（长按 5 秒清空联网配置重新配网，保留模块/红外设置）────────
#define FACTORY_RESET_PIN      0    // BOOT 键

// ─── 平台地址：默认留空，在设备配网页填写 ────────────
//  从 App「添加设备」复制当前服务器平台地址，不含 /app 或 /api。
//  自助配网时设备用它调 /api/provisioning/register；BOOT 重置后不再预填此值。
//  生产建议 https（注册响应含明文 MQTT 密码）。
#define DEFAULT_SERVER_URL     ""

// ─── 首次默认模块：全关闭，上线后在 App 动态添加 ─────
//  已保存的模块清单优先；升级不会覆盖已有开关、引脚和红外协议。
#define SENSOR_DHT11_ENABLED   0
#define DHT11_PIN              4    // GPIO4

#define SENSOR_SOUND_ENABLED   0
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
//  AC_PROTOCOL 只是【首次开机默认协议】。协议可在 App 里运行时切换并持久化到 NVS——
//  无需接收器识别、也无需反复烧录：在 App 空调控制页逐个试协议，空调有反应即选定。
//  海信 RCH-R0Y3 系列优先验证 KELON168；品牌不代表协议，请按原遥控器匹配。
//  完整协议/型号清单见 resources/ac-ir-protocols.json，需使用配套新版固件。
#define AC_PROTOCOL            decode_type_t::COOLIX
//  空调在后台是一台挂在本网关下的【独立设备】(type=ac)，与传感器一样有自己的
//  在线状态和控制卡。uid 默认由网关 UID 派生（如 esp32-aabbcc-ac）；
//  仅当要沿用后台已存在的固定 uid 时才取消注释：
// #define AC_IR_UID           "ac-living-01"

// =====================================================================
//  运行期配置（WiFi / MQTT 身份）
// =====================================================================
//
//  【默认】App 配对码配网：下面联网字段均留空，无已保存 WiFi 时进入
//   SoftAP 配网模式（热点 HG-Setup-xxxx），在手机 App「添加设备」生成配对码，
//   通过配网页填入即可自动注册上线，无需在此填写任何凭证。
//
//  【可选】手动烧录（向后兼容旧流程）：若在此填入真实 WiFi（及可选的 MQTT
//   凭证），设备将跳过配网直接连接。默认留空则走自助配网。
//   BOOT 重置后永久禁用此播种流程，只接受配网页重新提交的信息。
//   网关 UID 未指定时自动用 MAC 派生（esp32-<mac>）。

#define WIFI_SSID              ""
#define WIFI_PASSWORD          ""

//  可选：连同 MQTT 凭证一起写死（则跳过自注册，需先在后台手动建设备）
#define MQTT_HOST              ""
#define MQTT_PORT              1883  // 标准端口默认值；自注册后使用平台返回的端口
#define GATEWAY_UID            ""    // 默认按 MAC 派生；已有 NVS 身份保持不变
#define MQTT_PASSWORD          ""

#endif // CONFIG_H
