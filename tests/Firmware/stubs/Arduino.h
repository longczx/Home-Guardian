#pragma once
struct TestSerial {
    template<typename... Args> void printf(const char*, Args...) {}
};
static TestSerial Serial;
