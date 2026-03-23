#include "watchdog.h"
#include <esp_task_wdt.h>

void watchdog_init(uint32_t timeout_ms) {
    // 将毫秒转为秒，因为旧版 API 接收的是秒
    uint32_t timeout_s = timeout_ms / 1000;
    if (timeout_s < 1) timeout_s = 1;

    // 这是旧版 ESP32 的标准写法，简单直接
    esp_task_wdt_init(timeout_s, true); 
    esp_task_wdt_add(NULL); // 将当前主任务加入看门狗监控
}

void watchdog_feed() {
    esp_task_wdt_reset();
}