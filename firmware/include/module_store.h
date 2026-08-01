#ifndef MODULE_STORE_H
#define MODULE_STORE_H

#include <Arduino.h>
#include <Preferences.h>

/**
 * 运行期模块清单（ESP32 NVS / Preferences）
 *
 * 把"这台网关接了哪些传感器/执行器"从编译期(config.h 的 #if)搬到运行期，
 * 支撑设备热插拔：App 下发 add_module / remove_module 即可增删，存 NVS 后
 * 掉电重启沿用，无需改 config.h 重新烧录。
 *
 * 首次开机 NVS 为空时，按 config.h 里的开关播种一份，保证老用户升级后
 * 原有传感器不丢；此后一切以 NVS 为准。
 */
struct ModuleSlot {
    String  type;   // "dht11" | "sound" | "ac_ir"
    uint8_t pin = 0;
    String  uid;    // 子设备的 device_uid
    String  name;   // 展示名
};

class ModuleStore {
public:
    static constexpr uint8_t MAX_MODULES = 8;

    // gatewayUid 用于首次播种时派生子设备 uid
    void begin(const String& gatewayUid);

    uint8_t count() const { return _count; }
    const ModuleSlot* get(uint8_t i) const { return (i < _count) ? &_slots[i] : nullptr; }
    const ModuleSlot* findByUid(const char* uid) const;

    // 增删后立即落盘；uid 重复或已满返回 false
    bool add(const ModuleSlot& slot);
    bool remove(const char* uid);

    // 校验模块类型是否受支持
    static bool isSupportedType(const String& type);

private:
    Preferences _prefs;
    ModuleSlot  _slots[MAX_MODULES];
    uint8_t     _count = 0;

    void load();
    void save();
    void seedFromConfig(const String& gatewayUid);
};

#endif // MODULE_STORE_H
