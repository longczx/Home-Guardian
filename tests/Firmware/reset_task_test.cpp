#include <cassert>
#include <chrono>
#include <iostream>
#include <WiFi.h>
#include <freertos/task.h>
#include "reset_button.h"
#include "config_store.h"

int main() {
    ConfigStore store;
    store.begin();
    assert(store.hasWifi() && store.hasMqtt());
    testPressed = true;
    assert(beginResetButton(0));
    // Deliberately never run main loop. Wait as if WiFi / HTTP / MQTT were blocked.
    std::unique_lock<std::mutex> lock(testRestartMutex);
    assert(testRestartSignal.wait_for(lock, std::chrono::seconds(2), [] { return testRestarted.load(); }));
    lock.unlock();
    testResetThread.join();
    assert(testMillis >= 5000 && testMillis <= 5020);
    assert(WiFi.eraseCount == 1);
    ConfigStore reboot;
    reboot.begin();
    assert(!reboot.hasWifi() && !reboot.hasMqtt() && !reboot.allowsDefaults());
    assert(reboot.gatewayUid() == store.gatewayUid());
    std::cout << "Actual reset task erased configuration and rebooted with main loop blocked\n";
}
