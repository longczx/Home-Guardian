#pragma once
inline bool testHttpStart = true;
struct TestListener {
    bool active = false;
    explicit operator bool() { return active; }
};
class WebServer {
public:
    explicit WebServer(int) {}
    void begin() { ++starts; _server.active = testHttpStart; }
    void stop() { ++stops; _server.active = false; }
    int starts = 0, stops = 0;
protected:
    TestListener _server;
};
