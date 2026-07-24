#include "ac_ir.h"
#include <Arduino.h>
#include <string.h>
#include <IRutils.h>   // typeToString()

// ─── 模式字符串 ⇄ IRac 枚举 ─────────────────────────────
static stdAc::opmode_t parseMode(const char* m) {
    if (!strcmp(m, "cool")) return stdAc::opmode_t::kCool;
    if (!strcmp(m, "heat")) return stdAc::opmode_t::kHeat;
    if (!strcmp(m, "dry"))  return stdAc::opmode_t::kDry;
    if (!strcmp(m, "fan"))  return stdAc::opmode_t::kFan;
    return stdAc::opmode_t::kAuto;
}
static const char* modeStr(stdAc::opmode_t m) {
    switch (m) {
        case stdAc::opmode_t::kCool: return "cool";
        case stdAc::opmode_t::kHeat: return "heat";
        case stdAc::opmode_t::kDry:  return "dry";
        case stdAc::opmode_t::kFan:  return "fan";
        default:                     return "auto";
    }
}

// ─── 风速字符串 ⇄ IRac 枚举 ─────────────────────────────
static stdAc::fanspeed_t parseFan(const char* f) {
    if (!strcmp(f, "low"))  return stdAc::fanspeed_t::kLow;
    if (!strcmp(f, "mid"))  return stdAc::fanspeed_t::kMedium;
    if (!strcmp(f, "high")) return stdAc::fanspeed_t::kHigh;
    return stdAc::fanspeed_t::kAuto;
}
static const char* fanStr(stdAc::fanspeed_t f) {
    switch (f) {
        case stdAc::fanspeed_t::kLow:    return "low";
        case stdAc::fanspeed_t::kMedium: return "mid";
        case stdAc::fanspeed_t::kHigh:   return "high";
        default:                         return "auto";
    }
}

AcIr::AcIr(uint16_t irLedPin, decode_type_t protocol)
    : _ac(irLedPin), _protocol(protocol) {}

void AcIr::begin() {
    // 初始默认状态：与后端"空调"能力模板的 default 对齐（关机 / 制冷 / 26℃ / 自动风）
    _state.protocol = _protocol;
    _state.model    = 1;
    _state.power    = false;
    _state.mode     = stdAc::opmode_t::kCool;
    _state.degrees  = 26;
    _state.celsius  = true;
    _state.fanspeed = stdAc::fanspeed_t::kAuto;
    _state.swingv   = stdAc::swingv_t::kOff;
    _state.swingh   = stdAc::swingh_t::kOff;
    _state.light    = true;
    _state.beep     = false;
    _state.econo    = false;
    _state.filter   = false;
    _state.turbo    = false;
    _state.quiet    = false;
    _state.clean    = false;
    _state.sleep    = -1;   // -1 = 不设置
    _state.clock    = -1;
}

bool AcIr::apply(const JsonObject& params, JsonObject& outState) {
    // merge：只覆盖本次带来的字段，其余沿用当前状态
    if (params["power"].is<bool>())        _state.power    = params["power"].as<bool>();
    if (params["mode"].is<const char*>())  _state.mode     = parseMode(params["mode"].as<const char*>());
    if (params["temp"].is<int>())          _state.degrees  = params["temp"].as<int>();
    if (params["fan"].is<const char*>())   _state.fanspeed = parseFan(params["fan"].as<const char*>());
    if (params["swing"].is<bool>())
        _state.swingv = params["swing"].as<bool>() ? stdAc::swingv_t::kAuto : stdAc::swingv_t::kOff;

    bool ok = _ac.sendAc(_state, nullptr);
    if (!ok) {
        Serial.printf("[AC-IR] 协议 %s 不受 IRac 支持，请改用原始码学习方案\n",
                      typeToString(_protocol).c_str());
    }
    fillState(outState);
    return ok;
}

void AcIr::fillState(JsonObject& outState) const {
    outState["power"] = _state.power;
    outState["mode"]  = modeStr(_state.mode);
    outState["temp"]  = _state.degrees;
    outState["fan"]   = fanStr(_state.fanspeed);
    outState["swing"] = (_state.swingv != stdAc::swingv_t::kOff);
}
