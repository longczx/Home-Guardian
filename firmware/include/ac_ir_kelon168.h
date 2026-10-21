#ifndef AC_IR_KELON168_H
#define AC_IR_KELON168_H

#include <cstring>
#include <ir_Kelon.h>

// IRac 2.9.0 does not dispatch KELON168. Use its native encoder instead.
// This protocol carries a power-toggle bit: temperature changes must not toggle
// power again. After a reboot/protocol switch, assume the physical unit is off;
// the operator must establish that baseline with the original remote.
inline void prepareKelon168(IRKelon168Ac& ac, const stdAc::state_t& next,
                           const stdAc::state_t* previous) {
    ac.stateReset();
    ac.setModel(DG11R201);
    ac.setMode(IRKelon168Ac::convertMode(next.mode));
    ac.setTemp(static_cast<uint8_t>(next.degrees));
    ac.setFan(IRKelon168Ac::convertFan(next.fanspeed));
    ac.setSwing(next.swingv != stdAc::swingv_t::kOff);
    ac.setLight(next.light);
    ac.setPower(next.power);

    const bool toggle = next.power != (previous ? previous->power : false);
    Kelon168Protocol frame{};
    std::memcpy(frame.raw, ac.getRaw(false), kKelon168StateLength);
    frame.Power = toggle;
    ac.setRaw(frame.raw);

    // Every setter changes the button code, so select it after all state fields.
    uint8_t command = kKelon168CommandTemp;
    if (toggle || !previous) command = kKelon168CommandPower;
    else if (next.mode != previous->mode) command = kKelon168CommandMode;
    else if (next.fanspeed != previous->fanspeed) command = kKelon168CommandFanSpeed;
    else if (next.swingv != previous->swingv) command = kKelon168CommandSwing;
    ac.setCommand(command);
}

#endif
