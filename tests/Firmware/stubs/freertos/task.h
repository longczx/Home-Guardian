#pragma once
#include "FreeRTOS.h"
#include <cassert>
inline int xTaskCreate(void (*task)(void*), const char*, uint32_t stack, void* arg, int priority, void*) {
    assert(stack >= 4096 && priority > 1);
    testResetThread = std::thread([=] { try { task(arg); } catch (TestRestart&) {} });
    return pdPASS;
}
