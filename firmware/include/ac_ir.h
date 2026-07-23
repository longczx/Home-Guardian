#ifndef AC_IR_H
#define AC_IR_H

#include <ArduinoJson.h>
#include <IRremoteESP8266.h>
#include <IRac.h>

/**
 * 红外空调执行器
 *
 * 用 IRremoteESP8266 的 IRac 抽象层，把一份通用状态（电源/模式/温度/风速/扫风）
 * 合成为具体品牌的红外帧发射出去。品牌差异由 IRac 内部按 protocol 处理，
 * 因此本类与品牌无关——换空调只需改 config.h 里的 AC_PROTOCOL。
 *
 * 与后端约定（merge 模式）：每次 set_state 携带全量参数，缺省的沿用上次状态。
 */
class AcIr {
public:
    AcIr(uint16_t irLedPin, decode_type_t protocol);

    void begin();

    // 合并 params 到当前状态、发射红外；把当前完整状态写回 outState。
    // 返回 IRac 是否支持该协议并成功发送。
    bool apply(const JsonObject& params, JsonObject& outState);

    // 把当前状态序列化为后端约定的字段（power/mode/temp/fan/swing）
    void fillState(JsonObject& outState) const;

private:
    IRac _ac;
    decode_type_t _protocol;
    stdAc::state_t _state;
};

#endif
