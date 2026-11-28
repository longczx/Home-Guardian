#pragma once
#include <cstring>
#include <ir_Kelon.h>

// Encode the requested button independently of the full desired state.
inline void configureKelon168(IRKelon168Ac& ac, const stdAc::state_t& desired,
                             uint8_t command) {
    ac.stateReset();
    ac.setModel(static_cast<kelon168_ac_remote_model_t>(desired.model));
    ac.setMode(IRKelon168Ac::convertMode(desired.mode));
    ac.setTemp(static_cast<uint8_t>(desired.degrees));
    ac.setFan(desired.fanspeed == stdAc::fanspeed_t::kHigh
        ? kKelon168FanMax : IRKelon168Ac::convertFan(desired.fanspeed));
    ac.setSwing(desired.swingv != stdAc::swingv_t::kOff);
    ac.setLight(desired.light);
    Kelon168Protocol frame{};
    std::memcpy(frame.raw, ac.getRaw(false), kKelon168StateLength);
    frame.Power = command == kKelon168CommandPower;
    frame.On = desired.power;
    ac.setRaw(frame.raw);
    ac.setCommand(command);
}
