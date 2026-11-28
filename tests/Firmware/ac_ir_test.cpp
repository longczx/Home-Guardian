#include <cassert>
#include <iostream>
#include <cstring>
#include <Arduino.h>
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
    configureKelon168(kelon, desired, kKelon168CommandPower);
    Kelon168Protocol frame{};
    std::memcpy(frame.raw, kelon.getRaw(), kKelon168StateLength);
    assert(frame.Power && frame.On && frame.Cmd == kKelon168CommandPower);
    assert(IRKelon168Ac::validChecksum(frame.raw));
    desired.degrees = 26;
    configureKelon168(kelon, desired, kKelon168CommandTemp);
    std::memcpy(frame.raw, kelon.getRaw(), kKelon168StateLength);
    assert(!frame.Power && frame.On && frame.Cmd == kKelon168CommandTemp);
    assert(kelon.getTemp() == 26 && IRKelon168Ac::validChecksum(frame.raw));
    desired.power = false;
    configureKelon168(kelon, desired, kKelon168CommandPower);
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

    AcIr hisense(25, decode_type_t::KELON168);
    hisense.begin();
    JsonDocument input;
    input["protocol"] = "KELON168";
    input["model"] = 1;
    input["power"] = true;  // Snapshot may differ from the ESP32's state after a restart.
    input["temp"] = 27;
    input["swing"] = false;
    auto applyAndCheck = [&](const char* expected) {
        testSerialOutput.clear();
        assert(hisense.apply(input.as<JsonObject>(), resultState));
        assert(testSerialOutput.find(expected) != std::string::npos);
    };
    applyAndCheck("cmd=0x02 power_toggle=0");  // Old server, first temperature change.
    auto controls = input["_controls"].to<JsonArray>();
    controls.add("temp");
    input["power"] = false;  // Non-power edits never take a stale power value as a button press.
    input["temp"] = 28;
    applyAndCheck("cmd=0x02 power_toggle=0");
    controls[0] = "fan";
    input["fan"] = "high";
    applyAndCheck("cmd=0x11 power_toggle=0 swing=0 fan=5");
    applyAndCheck("cmd=0x11 power_toggle=0 swing=0 fan=5");
    controls[0] = "swing";
    input["swing"] = true;
    applyAndCheck("cmd=0x07 power_toggle=0 swing=1");
    input["swing"] = false;
    applyAndCheck("cmd=0x07 power_toggle=0 swing=0");
    applyAndCheck("cmd=0x07 power_toggle=0 swing=0");
    controls[0] = "power";
    input["power"] = true;
    applyAndCheck("cmd=0x01 power_toggle=1");
    std::cout << "IR firmware tests passed: " << protocols << " protocols; Kelon168 state, checksum and waveform decode" << std::endl;
}
