#include "watchdog.h"
#include <esp_task_wdt.h>

void watchdog_init(uint32_t timeout_ms) {
    esp_task_wdt_config_t config = {
        .timeout_ms = timeout_ms,
        .idle_core_mask = 0,
        .trigger_panic = true,
    };
    esp_task_wdt_init(&config);
    esp_task_wdt_add(NULL);
}

void watchdog_feed() {
    esp_task_wdt_reset();
}
