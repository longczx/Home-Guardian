#include <cassert>
#include <iostream>
#include <cstring>
#include <IRutils.h>
#include <IRrecv.h>
#include "ac_ir.h"
#include "ac_protocol_catalog.h"
#include "kelon168_state.h"

int main() {
    AcIr sender(25, decode_type_t::COOLIX);
    sender.begin();
    assert(!AcIr::supports(decode_type_t::YORK));  // Library advertises it, but has no sendAc branch.
    size_t protocols = 0;
    for (const auto& spec : AC_PROTOCOL_SPECS) {
        assert(AcIr::supports(spec.protocol));
        JsonDocument input, output;
        input["protocol"] = typeToString(spec.protocol);
        input["model"] = spec.defaultModel;
        input["power"] = true;
        input["temp"] = 25;
        input["mode"] = "cool";
        JsonObject outputState = output.to<JsonObject>();
        const bool accepted = sender.apply(input.as<JsonObject>(), outputState);
        if (!accepted) std::cerr << "Rejected protocol " << typeToString(spec.protocol) << ": " << (output["message"] | "unknown") << std::endl;
        assert(accepted);
        assert(std::string(output["protocol"].as<const char*>()) == typeToString(spec.protocol));
        protocols++;
    }
    JsonDocument before, invalid, result;
    JsonObject beforeState = before.to<JsonObject>();
    sender.fillState(beforeState);
    invalid["protocol"] = "NEC";
    JsonObject resultState = result.to<JsonObject>();
    assert(!sender.apply(invalid.as<JsonObject>(), resultState));
    JsonDocument after;
    JsonObject afterState = after.to<JsonObject>();
    sender.fillState(afterState);
    assert(std::string(before["protocol"].as<const char*>()) == after["protocol"].as<const char*>());
    invalid["protocol"] = "KELON168";
    invalid["model"] = 6;
    assert(!sender.apply(invalid.as<JsonObject>(), resultState));

    // Moving the transmitter preserves the selected protocol/model and current state.
    AcIr relocated(26, decode_type_t::COOLIX);
    relocated.begin();
    relocated.restoreRuntimeState(sender);
    JsonDocument relocatedState;
    JsonObject moved = relocatedState.to<JsonObject>();
    relocated.fillState(moved);
    String originalJson, relocatedJson;
    serializeJson(after, originalJson); serializeJson(relocatedState, relocatedJson);
    assert(originalJson == relocatedJson);

    IRKelon168Ac kelon(25);
    stdAc::state_t desired;
    desired.protocol = decode_type_t::KELON168; desired.model = 1;
    desired.power = true; desired.mode = stdAc::opmode_t::kCool; desired.degrees = 25;
    configureKelon168(kelon, desired, nullptr);
    Kelon168Protocol frame{};
    std::memcpy(frame.raw, kelon.getRaw(), kKelon168StateLength);
    assert(frame.Power && frame.On && frame.Cmd == kKelon168CommandPower);
    assert(IRKelon168Ac::validChecksum(frame.raw));
    auto previous = desired;
    desired.degrees = 26;
    configureKelon168(kelon, desired, &previous);
    std::memcpy(frame.raw, kelon.getRaw(), kKelon168StateLength);
    assert(!frame.Power && frame.On && frame.Cmd == kKelon168CommandTemp);
    assert(kelon.getTemp() == 26 && IRKelon168Ac::validChecksum(frame.raw));
    previous = desired; desired.power = false;
    configureKelon168(kelon, desired, &previous);
    std::memcpy(frame.raw, kelon.getRaw(), kKelon168StateLength);
    assert(frame.Power && !frame.On && frame.Cmd == kKelon168CommandPower);
    kelon.begin(); kelon.send(); kelon._irsend.makeDecodeResult();
    IRrecv receiver(0);
    const bool decoded = receiver.decodeKelon168(&kelon._irsend.capture);
    if (!decoded) std::cerr << "Kelon168 decoder rejected waveform; rawlen=" << kelon._irsend.capture.rawlen << std::endl;
    assert(decoded);
    assert(kelon._irsend.capture.decode_type == decode_type_t::KELON168);
    assert(kelon._irsend.capture.bits == 168);
    assert(std::memcmp(frame.raw, kelon._irsend.capture.state, kKelon168StateLength) == 0);
    std::cout << "IR firmware tests passed: " << protocols << " protocols; Kelon168 state, checksum and waveform decode" << std::endl;
}
