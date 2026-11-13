#pragma once
#include <Arduino.h>
#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>

// Arduino 2.x WebServer::begin returns void; inspect the underlying listener.
class ProvisioningWebServer : public WebServer {
public:
    using WebServer::WebServer;
    bool listening() { return static_cast<bool>(_server); }
};

class ProvisioningAp {
public:
    ProvisioningAp(ProvisioningWebServer& server, DNSServer& dns) : _server(server), _dns(dns) {}
    void begin(const char* ssid);
    void loop();
    bool ready() const { return _state == State::Ready; }
private:
    enum class State { Stopped, Retry, RadioOff, WaitIp, Ready };
    State _state = State::Stopped;
    ProvisioningWebServer& _server;
    DNSServer& _dns;
    char _ssid[33] = {};
    uint32_t _since = 0, _wait = 0, _backoff = 3000, _healthAt = 0;
    unsigned _attempt = 0;
    void fail(const char* stage);
    bool radioReady();
};
