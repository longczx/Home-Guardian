#pragma once
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <string>
#include <atomic>
#include <condition_variable>
struct TestRestart {};
inline std::atomic<bool> testPressed{false}, testRestarted{false};
inline std::atomic<uint32_t> testMillis{0};
inline std::condition_variable testRestartSignal;
inline std::mutex testRestartMutex;
#ifndef LOW
#define LOW 0
#endif
#define INPUT_PULLUP 2
inline void pinMode(uint8_t, int) {}
inline int digitalRead(uint8_t pin) { return pin == 0 && testPressed ? LOW : 1; }
inline uint32_t millis() { return testMillis; }
using String = std::string;
struct TestESP {
    uint64_t getEfuseMac() { return 0x123456abcdefULL; }
    void restart() {
        { std::lock_guard<std::mutex> lock(testRestartMutex); testRestarted = true; }
        testRestartSignal.notify_all();
        throw TestRestart{};
    }
};
static TestESP ESP;
inline uint32_t esp_random() { return 0x12345678; }
struct TestSerial {
    template<typename... Args> void printf(const char*, Args...) {}
    void println(const char*) {}
};
static TestSerial Serial;
