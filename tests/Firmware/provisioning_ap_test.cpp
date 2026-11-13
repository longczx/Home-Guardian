#include <cassert>
#include <iostream>
#include <Preferences.h>
#include "provisioning_ap.h"

static void reset(uint32_t now = 0) {
    WiFi = TestWiFi{}; testMillis = now; testHttpStart = true; testDnsStart = true; testSerialOutput.clear();
}
static void advance(ProvisioningAp& ap, uint32_t ms) { testMillis += ms; ap.loop(); }
static void healthy(ProvisioningAp& ap, ProvisioningWebServer& server, DNSServer& dns) {
    assert(ap.ready() && server.listening() && dns.active);
    assert(WiFi.channel == 1 && WiFi.hidden == 0 && WiFi.clients == 4 && WiFi.open);
    assert(!WiFi.persistence && !WiFi.autoReconnect && WiFi.eraseCount == 0);
    assert(WiFi.ip == IPAddress(192,168,4,1) && WiFi.ssid == "HG-Setup-cdef");
}
int main() {
    Preferences modules, ir;
    modules.begin("hg-mods", false); modules.putString("list", "preserve-pin-25");
    ir.begin("ac_ir", false); ir.putString("proto", "KELON168"); ir.putShort("model", 1);
    reset();
    ProvisioningWebServer server(80); DNSServer dns; ProvisioningAp ap(server, dns);
    ap.begin("HG-Setup-cdef"); ap.loop();
    assert(!ap.ready() && WiFi.offCount == 1 && WiFi.apCalls == 0);
    advance(ap, 249); assert(WiFi.apCalls == 0);
    advance(ap, 1); healthy(ap, server, dns);
    assert((WiFi.calls == std::vector<String>{"off", "ap_mode", "ip_config", "soft_ap"}));
    advance(ap, 600000); healthy(ap, server, dns); assert(WiFi.offCount == 1);  // no clients is not a fault
    WiFi.currentMode = WIFI_AP_STA; advance(ap, 1000); healthy(ap, server, dns); // WiFi scanning may enable STA
    WiFi.currentMode = WIFI_STA; advance(ap, 1000);
    assert(!ap.ready() && !server.listening() && !dns.active);
    advance(ap, 3000); advance(ap, 250); healthy(ap, server, dns);
    server.stop(); advance(ap, 1000); assert(!ap.ready());
    advance(ap, 3000); advance(ap, 250); healthy(ap, server, dns);

    for (int failure = 0; failure < 8; ++failure) {
        reset();
        if (failure == 0) WiFi.failOff = 1;
        if (failure == 1) WiFi.failMode = 1;
        if (failure == 2) WiFi.failConfig = 1;
        if (failure == 3) WiFi.failAp = 1;
        if (failure == 4) WiFi.autoIp = false;
        if (failure == 5) testDnsStart = false;
        if (failure == 6) testHttpStart = false;
        if (failure == 7) WiFi.autoStarted = false;
        ProvisioningWebServer failedServer(80); DNSServer failedDns; ProvisioningAp failed(failedServer, failedDns);
        failed.begin("HG-Setup-cdef"); failed.loop();
        if (failure != 0) advance(failed, 250);
        if (failure == 4 || failure == 7) { advance(failed, 1999); assert(!failed.ready()); advance(failed, 1); }
        assert(!failed.ready() && !failedServer.listening() && !failedDns.active);
        assert(testSerialOutput.find("失败 stage=") != String::npos);
        assert(testSerialOutput.find("热点已开") == String::npos);
        WiFi.autoIp = true; WiFi.autoStarted = true; testDnsStart = true; testHttpStart = true;
        int before = WiFi.offCount;
        advance(failed, 2999); assert(WiFi.offCount == before);
        advance(failed, 1); assert(WiFi.offCount == before + 1);
        advance(failed, 250); healthy(failed, failedServer, failedDns);
    }
    reset(); WiFi.autoIp = false; WiFi.autoStarted = false;
    ProvisioningWebServer slowServer(80); DNSServer slowDns; ProvisioningAp slow(slowServer, slowDns);
    slow.begin("HG-Setup-cdef"); slow.loop(); advance(slow, 250);
    advance(slow, 1500); assert(!slow.ready() && slowDns.starts == 0 && slowServer.starts == 0);
    WiFi.ip = WiFi.configuredIp; WiFi.statusBits = AP_STARTED_BIT;
    advance(slow, 1); healthy(slow, slowServer, slowDns);
    reset(UINT32_MAX - 100);
    WiFi.failAp = 6;
    ProvisioningWebServer retryServer(80); DNSServer retryDns; ProvisioningAp retry(retryServer, retryDns);
    retry.begin("HG-Setup-cdef"); retry.loop();
    for (uint32_t wait : {3000,6000,12000,24000,30000,30000}) {
        advance(retry, 250); assert(!retry.ready());
        int before = WiFi.offCount;
        advance(retry, wait - 1); assert(WiFi.offCount == before);
        advance(retry, 1); assert(WiFi.offCount == before + 1);
    }
    advance(retry, 250); healthy(retry, retryServer, retryDns);
    assert(modules.getString("list") == "preserve-pin-25" && ir.getString("proto") == "KELON168" && ir.getShort("model") == 1);
    std::cout << "AP startup: mode/IP/SSID/DNS/HTTP validation, retry/backoff/rollover, health recovery and configuration preservation passed\n";
}
