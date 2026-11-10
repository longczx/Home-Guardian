#ifndef AC_IR_H
#define AC_IR_H

#include <ArduinoJson.h>
#include <IRremoteESP8266.h>
#include <IRac.h>
#include <Preferences.h>

/**
 * 红外空调执行器
 *
 * 通用状态由 IRac 发送；KELON168 使用专用驱动。可选协议与型号来自共享目录，
 * 不支持的输入明确失败，不会继续使用旧协议发送。
 *
 * 协议可运行时切换：set_state 携带 protocol 字段（如 "COOLIX"/"GREE"）即切换并
 * 校验并发送成功后持久化协议和型号到 NVS；协议名称仍需与原遥控器匹配。
 * config.h 的 AC_PROTOCOL 仅作首次开机默认值。
 *
 * 与后端约定（merge 模式）：每次 set_state 携带全量参数，缺省的沿用上次状态。
 */
class AcIr {
public:
    AcIr(uint16_t irLedPin, decode_type_t defaultProtocol);

    void begin();

    // 合并 params 到当前状态、发射红外；把当前完整状态写回 outState。
    // 返回发送实现是否接受指令；不代表空调实际收到或执行。
    bool apply(const JsonObject& params, JsonObject& outState);
    static bool supports(decode_type_t protocol);

    // 把当前状态序列化为后端约定的字段（power/mode/temp/fan/swing/protocol）
    void fillState(JsonObject& outState) const;

private:
    IRac _ac;
    uint16_t _irLedPin;
    decode_type_t _protocol;
    stdAc::state_t _state;
    stdAc::state_t _lastSent;
    bool _hasLastSent = false;
    Preferences _prefs;
};

#endif
