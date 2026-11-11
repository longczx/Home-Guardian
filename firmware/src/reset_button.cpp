#include "reset_button.h"
#include "config_store.h"
#include "long_press.h"
#include <Arduino.h>
#include <WiFi.h>
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>

static uint8_t resetPin;

// Higher priority than loop; network calls do not suspend sampling.
static void resetButtonTask(void*) {
    LongPress button;
    for (;;) {
        if (button.update(digitalRead(resetPin) == LOW, millis())) {
            Serial.println("[Main] BOOT 长按 5 秒：清除联网配置，重启进入配网");
            while (!ConfigStore::resetNetworkForRestart()) {
                Serial.println("[Main] 重置标记保存失败，稍后重试");
                vTaskDelay(pdMS_TO_TICKS(1000));
            }
            WiFi.eraseAP();  // Also remove SDK WiFi credentials.
            ESP.restart();
        }
        vTaskDelay(pdMS_TO_TICKS(20));
    }
}

bool beginResetButton(uint8_t pin) {
    resetPin = pin;
    pinMode(pin, INPUT_PULLUP);
    return xTaskCreate(resetButtonTask, "boot-reset", 4096, nullptr, 2, nullptr) == pdPASS;
}
