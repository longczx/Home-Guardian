#pragma once
struct TestWiFi {
    int eraseCount = 0;
    bool eraseAP() { ++eraseCount; return true; }
};
inline TestWiFi WiFi;
