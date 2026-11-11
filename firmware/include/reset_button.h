#pragma once
#include <stdint.h>

// Must start before any potentially blocking network operation.
bool beginResetButton(uint8_t pin);
