#include <cassert>
#include <iostream>
#include "ac_ir_kelon168.h"

static Kelon168Protocol frame(IRKelon168Ac& ac) {
    Kelon168Protocol result{};
    std::memcpy(result.raw, ac.getRaw(), kKelon168StateLength);
    assert(IRKelon168Ac::validChecksum(result.raw));
    return result;
}

int main() {
    IRKelon168Ac ac(25);
    stdAc::state_t state{};
    state.protocol = decode_type_t::KELON168;
    state.power = true;
    state.mode = stdAc::opmode_t::kCool;
    state.degrees = 25;
    state.fanspeed = stdAc::fanspeed_t::kAuto;
    state.swingv = stdAc::swingv_t::kOff;
    prepareKelon168(ac, state, nullptr);
    auto first = frame(ac);
    assert(first.raw[0] == 0x83 && first.raw[1] == 0x06);
    assert(first.Power && first.On);
    assert(ac.getCommand() == kKelon168CommandPower);
    assert(ac.getTemp() == 25 && ac.getMode() == kKelon168Cool);
    ac.begin();
    ac.send();
    assert(ac._irsend.outputStr().find("f38000") == 0);

    auto previous = state;
    state.degrees = 26;
    prepareKelon168(ac, state, &previous);
    auto temperature = frame(ac);
    assert(!temperature.Power && temperature.On);
    assert(ac.getCommand() == kKelon168CommandTemp && ac.getTemp() == 26);

    previous = state;
    state.fanspeed = stdAc::fanspeed_t::kLow;
    prepareKelon168(ac, state, &previous);
    assert(!frame(ac).Power);
    assert(ac.getFan() == IRKelon168Ac::convertFan(state.fanspeed));
    assert(ac.getCommand() == kKelon168CommandFanSpeed);

    previous = state;
    state.swingv = stdAc::swingv_t::kAuto;
    prepareKelon168(ac, state, &previous);
    assert(!frame(ac).Power && ac.getSwing());
    assert(ac.getCommand() == kKelon168CommandSwing);

    previous = state;
    state.mode = stdAc::opmode_t::kHeat;
    prepareKelon168(ac, state, &previous);
    assert(!frame(ac).Power && ac.getMode() == kKelon168Heat);
    assert(ac.getCommand() == kKelon168CommandMode);

    previous = state;
    state.power = false;
    prepareKelon168(ac, state, &previous);
    auto off = frame(ac);
    assert(off.Power && !off.On);
    assert(ac.getCommand() == kKelon168CommandPower);
    previous = state;
    prepareKelon168(ac, state, &previous);
    assert(!frame(ac).Power && !frame(ac).On);
    prepareKelon168(ac, state, nullptr);
    assert(!frame(ac).Power && !frame(ac).On);
    std::cout << "Kelon168 frame, checksum, carrier, mode, fan, swing and power-transition checks passed\n";
}
