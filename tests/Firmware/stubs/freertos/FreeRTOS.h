#pragma once
#include <Arduino.h>
#include <thread>
#define pdMS_TO_TICKS(ms) (ms)
#define pdPASS 1
inline std::thread testResetThread;
inline void vTaskDelay(uint32_t ms) { testMillis += ms; std::this_thread::yield(); }
