#include "ac_ir.h"
#include <Arduino.h>
#include <string.h>
#include <IRutils.h>   // typeToString()
#include "ac_ir_kelon168.h"

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

AcIr::AcIr(uint16_t irLedPin, decode_type_t defaultProtocol)
    : _ac(irLedPin), _pin(irLedPin), _kelon168(irLedPin), _protocol(defaultProtocol) {}

void AcIr::begin() {
    _kelon168.begin();
    // 恢复上次在 App 里选定的协议（NVS）；没有则用 config.h 的默认值
    _prefs.begin("ac_ir", false);
    String saved = _prefs.getString("proto", "");
    if (saved.length()) {
        decode_type_t p = strToDecodeType(saved.c_str());
        if (p == decode_type_t::KELON168 || IRac::isProtocolSupported(p)) {
            _protocol = p;
            Serial.printf("[AC-IR] 恢复已保存协议: %s\n", saved.c_str());
        }
    }

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

void AcIr::setProtocol(decode_type_t p, bool persist) {
    _protocol = p;
    _state.protocol = p;
    _hasPrevious = false;
    if (persist) {
        _prefs.putString("proto", typeToString(p));
    }
    Serial.printf("[AC-IR] 切换协议 → %s\n", typeToString(p).c_str());
}

bool AcIr::apply(const JsonObject& params, JsonObject& outState) {
    // 运行时切协议：先切再发，本次即用新协议（App 里逐个试的关键）
    decode_type_t protocol = _protocol;
    if (params["protocol"].is<const char*>())
        protocol = strToDecodeType(params["protocol"].as<const char*>());
    else if (!params["protocol"].isNull()) {
        outState["message"] = "IR protocol must be a string";
        return false;
    }
    if (protocol != decode_type_t::KELON168 && !IRac::isProtocolSupported(protocol)) {
        outState["message"] = "unsupported IR protocol; update firmware or select a supported protocol";
        return false;
    }
    // Validate before persisting a new protocol or mutating the last sent state.
    if ((!params["temp"].isNull() && (!params["temp"].is<int>() || params["temp"].as<int>() < 16 || params["temp"].as<int>() > 30)) ||
        (!params["power"].isNull() && !params["power"].is<bool>()) ||
        (!params["swing"].isNull() && !params["swing"].is<bool>())) {
        outState["message"] = "invalid IR state parameter";
        return false;
    }
    if (!params["mode"].isNull()) {
        const char* value = params["mode"].is<const char*>() ? params["mode"].as<const char*>() : "";
        if (strcmp(value,"auto") && strcmp(value,"cool") && strcmp(value,"heat") && strcmp(value,"dry") && strcmp(value,"fan")) {
            outState["message"] = "invalid IR mode";
            return false;
        }
    }
    if (!params["fan"].isNull()) {
        const char* value = params["fan"].is<const char*>() ? params["fan"].as<const char*>() : "";
        if (strcmp(value,"auto") && strcmp(value,"low") && strcmp(value,"mid") && strcmp(value,"high")) {
            outState["message"] = "invalid IR fan speed";
            return false;
        }
    }
    if (protocol != _protocol) setProtocol(protocol, true);

    // merge：只覆盖本次带来的字段，其余沿用当前状态
    if (params["power"].is<bool>())        _state.power    = params["power"].as<bool>();
    if (params["mode"].is<const char*>())  _state.mode     = parseMode(params["mode"].as<const char*>());
    if (params["temp"].is<int>())          _state.degrees  = params["temp"].as<int>();
    if (params["fan"].is<const char*>())   _state.fanspeed = parseFan(params["fan"].as<const char*>());
    if (params["swing"].is<bool>())
        _state.swingv = params["swing"].as<bool>() ? stdAc::swingv_t::kAuto : stdAc::swingv_t::kOff;

    bool ok;
    if (_protocol == decode_type_t::KELON168) {
        prepareKelon168(_kelon168, _state, _hasPrevious ? &_previous : nullptr);
        _kelon168.send();
        ok = true;
    } else {
        ok = _ac.sendAc(_state, _hasPrevious ? &_previous : nullptr);
    }
    if (!ok) {
        Serial.printf("[AC-IR] 协议 %s 不受 IRac 支持，请改用原始码学习方案\n",
                      typeToString(_protocol).c_str());
    }
    if (ok) {
        _previous = _state;
        _hasPrevious = true;
        Serial.printf("[AC-IR] 发射完成 protocol=%s pin=%u power=%d temp=%.0f；未确认空调接收\n",
                      typeToString(_protocol).c_str(), _pin, _state.power, _state.degrees);
    }
    fillState(outState);
    return ok;
}

void AcIr::fillState(JsonObject& outState) const {
    outState["power"]    = _state.power;
    outState["mode"]     = modeStr(_state.mode);
    outState["temp"]     = _state.degrees;
    outState["fan"]      = fanStr(_state.fanspeed);
    outState["swing"]    = (_state.swingv != stdAc::swingv_t::kOff);
    outState["protocol"] = typeToString(_protocol);
}
