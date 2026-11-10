#pragma once
#include <cstring>
#include <ir_Kelon.h>

// Build a complete frame, but tag the button that actually changed. In this
// library setPower() always sets the toggle bit, even for temperature updates.
inline void configureKelon168(IRKelon168Ac& ac, const stdAc::state_t& desired,
                             const stdAc::state_t* previous) {
    ac.stateReset();
    ac.setModel(static_cast<kelon168_ac_remote_model_t>(desired.model));
    ac.setMode(IRKelon168Ac::convertMode(desired.mode));
    ac.setTemp(static_cast<uint8_t>(desired.degrees));
    ac.setFan(IRKelon168Ac::convertFan(desired.fanspeed));
    ac.setSwing(desired.swingv != stdAc::swingv_t::kOff);
    ac.setLight(desired.light);
    ac.setPower(desired.power);
    uint8_t command = kKelon168CommandTemp;
    if (!previous || desired.power != previous->power) command = kKelon168CommandPower;
    else if (desired.mode != previous->mode) command = kKelon168CommandMode;
    else if (desired.degrees != previous->degrees) command = kKelon168CommandTemp;
    else if (desired.fanspeed != previous->fanspeed) command = kKelon168CommandFanSpeed;
    else if (desired.swingv != previous->swingv) command = kKelon168CommandSwing;
    Kelon168Protocol frame{};
    std::memcpy(frame.raw, ac.getRaw(false), kKelon168StateLength);
    frame.Power = !previous || desired.power != previous->power;
    frame.On = desired.power;
    ac.setRaw(frame.raw);
    ac.setCommand(command);
}
