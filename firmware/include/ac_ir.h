#ifndef AC_IR_H
#define AC_IR_H

#include <ArduinoJson.h>
#include <IRremoteESP8266.h>
#include <IRac.h>
#include <Preferences.h>
#include <ir_Kelon.h>

/**
 * 红外空调执行器
 *
 * 用 IRremoteESP8266 的 IRac 抽象层，把一份通用状态（电源/模式/温度/风速/扫风）
 * 合成为具体协议的红外帧发射出去。KELON168 使用库的原生编码器，
 * 其他协议由 IRac 分派。发射器没有空调接收反馈。
 *
 * 协议可运行时切换：set_state 携带 protocol 字段（如 "COOLIX"/"GREE"）即切换并
 * 持久化到 NVS——无需接收器识别，也无需反复烧录，在 App 里逐个试到空调有反应即可。
 * config.h 的 AC_PROTOCOL 仅作首次开机默认值。
 *
 * 与后端约定（merge 模式）：每次 set_state 携带全量参数，缺省的沿用上次状态。
 */
class AcIr {
public:
    AcIr(uint16_t irLedPin, decode_type_t defaultProtocol);

    void begin();

    // 合并 params 到当前状态、发射红外；把当前完整状态写回 outState。
    // 返回编码器是否尝试发射；不代表空调已接收或执行。
    bool apply(const JsonObject& params, JsonObject& outState);

    // 把当前状态序列化为后端约定的字段（power/mode/temp/fan/swing/protocol）
    void fillState(JsonObject& outState) const;
    uint16_t pin() const { return _pin; }

private:
    // 切换协议；persist=true 时写入 NVS，掉电重启后沿用
    void setProtocol(decode_type_t p, bool persist);

    IRac _ac;
    uint16_t _pin;
    IRKelon168Ac _kelon168;
    decode_type_t _protocol;
    stdAc::state_t _state{};
    stdAc::state_t _previous{};
    bool _hasPrevious = false;
    Preferences _prefs;
};

#endif
