#include "provisioning_ap.h"

static IPAddress portalIp() { return IPAddress(192, 168, 4, 1); }

void ProvisioningAp::begin(const char* ssid) {
    _server.stop(); _dns.stop();
    snprintf(_ssid, sizeof(_ssid), "%s", ssid ? ssid : "");
    _state = State::Retry;
    _since = millis(); _wait = 0; _backoff = 3000; _attempt = 0;
    Serial.printf("[配网/AP] 准备开启热点 ssid=%s channel=1 hidden=0\n", _ssid);
}

bool ProvisioningAp::radioReady() {
    return (WiFi.getMode() & WIFI_AP) && (WiFi.getStatusBits() & AP_STARTED_BIT)
        && WiFi.softAPIP() == portalIp() && WiFi.softAPSSID() == _ssid;
}

void ProvisioningAp::fail(const char* stage) {
    Serial.printf("[配网/AP] 失败 stage=%s attempt=%u mode=%d bits=%d ip=%s heap=%u；%u ms 后重试\n",
                  stage, _attempt, int(WiFi.getMode()), WiFi.getStatusBits(), WiFi.softAPIP().toString().c_str(),
                  (unsigned)ESP.getFreeHeap(), (unsigned)_backoff);
    _server.stop(); _dns.stop();
    _state = State::Retry;
    _since = millis(); _wait = _backoff;
    _backoff = _backoff < 15000 ? _backoff * 2 : 30000;
}

void ProvisioningAp::loop() {
    uint32_t now = millis();
    if (_state == State::Stopped) return;
    if (_state == State::Ready) {
        if (uint32_t(now - _healthAt) >= 1000) {
            _healthAt = now;
            if (!radioReady()) fail("hotspot_lost");
            else if (!_server.listening()) fail("http_listener_lost");
        }
        return;
    }
    if (_state == State::Retry) {
        if (uint32_t(now - _since) < _wait) return;
        ++_attempt;
        WiFi.persistent(false);  // Retry must not overwrite NVS network/module settings.
        WiFi.setAutoReconnect(false);
        if (!WiFi.mode(WIFI_OFF)) { fail("radio_off"); return; }
        _state = State::RadioOff; _since = millis();
        return;  // Let SDK stop events complete without blocking loop/BOOT.
    }
    if (_state == State::RadioOff) {
        if (uint32_t(now - _since) < 250) return;
        if (!WiFi.mode(WIFI_AP)) { fail("ap_mode"); return; }
        if (!WiFi.softAPConfig(portalIp(), portalIp(), IPAddress(255, 255, 255, 0))) {
            fail("ap_ip_config"); return;
        }
        // Explicit open, visible 2.4GHz channel 1 hotspot, maximum four clients.
        if (!WiFi.softAP(_ssid, nullptr, 1, 0, 4)) { fail("soft_ap"); return; }
        _state = State::WaitIp; _since = millis();
    }
    if (_state == State::WaitIp) {
        if (!radioReady()) {
            if (uint32_t(millis() - _since) >= 2000) fail("ap_interface_not_ready");
            return;
        }
        if (!_dns.start(53, "*", portalIp())) { fail("dns_start"); return; }
        _server.begin();
        if (!_server.listening()) { fail("http_start"); return; }
        _state = State::Ready; _healthAt = millis(); _backoff = 3000;
        Serial.printf("[配网] 热点已开且门户就绪: %s → http://%s/ channel=1 attempt=%u\n",
                      _ssid, portalIp().toString().c_str(), _attempt);
    }
}
