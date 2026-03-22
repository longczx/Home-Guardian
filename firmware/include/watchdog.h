#ifndef WATCHDOG_H
#define WATCHDOG_H

#include <cstdint>

void watchdog_init(uint32_t timeout_ms);
void watchdog_feed();

#endif
