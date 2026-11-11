#pragma once
#include <stdint.h>

// Independent of Arduino/RTOS so timing, release and millis rollover are testable.
class LongPress {
public:
    bool update(bool pressed, uint32_t now) {
        if (!pressed) { _pressed = false; _fired = false; return false; }
        if (!_pressed) { _pressed = true; _since = now; }
        if (!_fired && uint32_t(now - _since) >= 5000) {
            _fired = true;
            return true;
        }
        return false;
    }
private:
    bool _pressed = false, _fired = false;
    uint32_t _since = 0;
};
