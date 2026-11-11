#include "ac_ir.h"
#include <Arduino.h>
#include <string.h>
#include <IRutils.h>   // typeToString()
#include "ac_protocol_catalog.h"
#include "kelon168_state.h"

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
    : _ac(irLedPin), _irLedPin(irLedPin), _protocol(defaultProtocol) {}

bool AcIr::supports(decode_type_t protocol) {
    if (!acProtocolSpec(protocol)) return false;
#if SEND_KELON168
    if (protocol == decode_type_t::KELON168) return true;
#endif
    return IRac::isProtocolSupported(protocol);
}

void AcIr::begin() {
    // 恢复上次在 App 里选定的协议（NVS）；没有则用 config.h 的默认值
    _prefs.begin("ac_ir", false);
    String saved = _prefs.getString("proto", "");
    if (saved.length()) {
        decode_type_t p = strToDecodeType(saved.c_str());
        if (supports(p)) {
            _protocol = p;
            Serial.printf("[AC-IR] 恢复已保存协议: %s\n", saved.c_str());
        }
    }

    // 初始默认状态：与后端"空调"能力模板的 default 对齐（关机 / 制冷 / 26℃ / 自动风）
    _state.protocol = _protocol;
    const AcProtocolSpec* spec = acProtocolSpec(_protocol);
    _state.model    = _prefs.getShort("model", spec ? spec->defaultModel : 1);
    if (!spec || _state.model < 0 || _state.model > 6 || !(spec->models & (1 << _state.model)))
        _state.model = spec ? spec->defaultModel : 1;
    _state.power    = false;
    _state.mode     = stdAc::opmode_t::kCool;
    _state.degrees  = 26;
    _state.celsius  = true;
    _state.fanspeed = stdAc::fanspeed_t::kAuto;
    _state.swingv   = stdAc::swingv_t::kOff;
    _state.swingh   = stdAc::swingh_t::kOff;
    _state.light    = false;
    _state.beep     = false;
    _state.econo    = false;
    _state.filter   = false;
    _state.turbo    = false;
    _state.quiet    = false;
    _state.clean    = false;
    _state.sleep    = -1;   // -1 = 不设置
    _state.clock    = -1;
    _hasLastSent = false;
    Serial.printf("[AC-IR] ready protocol=%s model=%d pin=%u\n", typeToString(_protocol).c_str(), _state.model, _irLedPin);
}

void AcIr::restoreRuntimeState(const AcIr& previous) {
    _protocol = previous._protocol;
    _state = previous._state;
    _lastSent = previous._lastSent;
    _hasLastSent = previous._hasLastSent;
}

bool AcIr::apply(const JsonObject& params, JsonObject& outState) {
    stdAc::state_t next = _state;
    if (params["protocol"].is<const char*>()) {
        decode_type_t p = strToDecodeType(params["protocol"].as<const char*>());
        if (!supports(p)) {
            outState["message"] = "unsupported IR protocol; update firmware or choose a supported protocol";
            return false;
        }
        if (p != next.protocol) next.model = acProtocolSpec(p)->defaultModel;
        next.protocol = p;
    }
    if (params["model"].is<int>()) next.model = params["model"].as<int>();
    const AcProtocolSpec* spec = acProtocolSpec(next.protocol);
    if (!supports(next.protocol) || !spec || next.model < 0 || next.model > 6 || !(spec->models & (1 << next.model))) {
        outState["message"] = "remote model is not supported by this IR protocol";
        return false;
    }
    if (params["power"].is<bool>())        next.power    = params["power"].as<bool>();
    if (params["mode"].is<const char*>())  next.mode     = parseMode(params["mode"].as<const char*>());
    if (params["temp"].is<float>())        next.degrees  = params["temp"].as<float>();
    if (params["fan"].is<const char*>())   next.fanspeed = parseFan(params["fan"].as<const char*>());
    if (params["swing"].is<bool>())
        next.swingv = params["swing"].as<bool>() ? stdAc::swingv_t::kAuto : stdAc::swingv_t::kOff;
    const stdAc::state_t* previous = _hasLastSent && _lastSent.protocol == next.protocol && _lastSent.model == next.model ? &_lastSent : nullptr;
    bool ok = false;
#if SEND_KELON168
    if (next.protocol == decode_type_t::KELON168) {
        IRKelon168Ac ac(_irLedPin);
        ac.begin();
        configureKelon168(ac, next, previous);
        ac.send();
        ok = true;
    } else
#endif
    { ok = _ac.sendAc(next, previous); }
    if (!ok) {
        outState["message"] = "IR sender rejected protocol";
        return false;
    }
    if (next.protocol != _protocol || next.model != _state.model) {
        _prefs.putString("proto", typeToString(next.protocol));
        _prefs.putShort("model", next.model);
    }
    _protocol = next.protocol;
    _state = next;
    _lastSent = next;
    _hasLastSent = true;
    Serial.printf("[AC-IR] tx protocol=%s model=%d pin=%u\n", typeToString(_protocol).c_str(), _state.model, _irLedPin);
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
    outState["model"]    = _state.model;
}
