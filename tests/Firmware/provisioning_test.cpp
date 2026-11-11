#include <cassert>
#include <iostream>
#include "config_store.h"
#include "long_press.h"

int main() {
    LongPress button;
    assert(!button.update(true, 0));  // millis == 0 is a valid start
    assert(!button.update(true, 4999));
    assert(button.update(true, 5000));
    assert(!button.update(true, 9000));  // one shot
    assert(!button.update(false, 9010));
    assert(!button.update(true, 9020));
    assert(!button.update(false, 14019));  // release cancels a short press
    assert(!button.update(true, 15000));
    assert(button.update(true, 20000));
    LongPress wrap;
    assert(!wrap.update(true, UINT32_MAX - 1000));
    assert(!wrap.update(true, 3998));
    assert(wrap.update(true, 3999));

    ConfigStore legacy;
    legacy.begin();
    assert(legacy.wifiSsid() == "legacy-test-wifi" && legacy.hasMqtt());
    Preferences modules, ir;
    modules.begin("hg-mods", false); modules.putString("list", "keep-pins");
    ir.begin("ac_ir", false); ir.putString("proto", "KELON168");
    assert(legacy.registrationToken().length() == 64);
    Preferences::failWrites.insert("hg-reset/pending");
    assert(!ConfigStore::resetNetworkForRestart());  // do not reboot on marker failure
    Preferences::failWrites.clear();
    assert(ConfigStore::resetNetworkForRestart());
    legacy.saveMqtt("late.example", 1883, "old-user", "old-password");
    assert(!legacy.saveProvisioning("late-wifi", "old", "http://late.example", "old-code"));
    assert(legacy.registrationToken().empty());
    assert(modules.getString("list") == "keep-pins" && ir.getString("proto") == "KELON168");

    // Simulate reboot plus an interrupted erase / residual write from older code.
    Preferences stale;
    stale.begin("hg", false); stale.putString("ssid", "must-not-load");
    stale.putString("regtoken", std::string(64, 'a'));
    ConfigStore reset;
    reset.begin();
    assert(!reset.hasWifi() && !reset.hasMqtt() && !reset.allowsDefaults());
    assert(reset.serverUrl().empty() && reset.provisionCode().empty());
    assert(reset.gatewayUid() == "legacy-test-gateway");
    assert(stale.getString("regtoken").empty());
    ConfigStore rebootAgain;
    rebootAgain.begin();
    assert(!rebootAgain.hasWifi() && !rebootAgain.allowsDefaults());

    Preferences::failWrites.insert("hg/surl");
    assert(!rebootAgain.saveProvisioning("new-wifi", "new-password", "http://new.example", "NEWCODE"));
    Preferences::failWrites.clear();
    ConfigStore afterFailedSave;
    afterFailedSave.begin();
    assert(!afterFailedSave.hasWifi());  // pending flag survived partial save
    assert(afterFailedSave.saveProvisioning("new-wifi", "new-password", "http://new.example", "NEWCODE"));
    afterFailedSave.saveMqtt("new.example", 1883, "new-user", "new-mqtt");
    ConfigStore provisioned;
    provisioned.begin();
    assert(provisioned.wifiSsid() == "new-wifi" && provisioned.hasMqtt());
    assert(provisioned.serverUrl() == "http://new.example" && provisioned.provisionCode() == "NEWCODE");
    assert(!provisioned.allowsDefaults());
    stale.clear();  // even if the networking namespace is later empty, never reseed old config.h
    ConfigStore empty;
    empty.begin();
    assert(!empty.hasWifi() && !empty.hasMqtt() && !empty.allowsDefaults());
    std::cout << "BOOT timing, rollover, reset persistence, late writes, NVS failures and re-provisioning passed\n";
}
