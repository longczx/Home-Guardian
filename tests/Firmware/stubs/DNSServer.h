#pragma once
#include <WiFi.h>
inline bool testDnsStart = true;
class DNSServer {
public:
    bool active = false;
    int starts = 0, stops = 0;
    bool start(int port, const String& domain, const IPAddress& ip) {
        assert(port == 53 && domain == "*" && ip == IPAddress(192,168,4,1));
        ++starts; active = testDnsStart; return active;
    }
    void stop() { ++stops; active = false; }
};
