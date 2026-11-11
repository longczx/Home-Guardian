#pragma once
#include <map>
#include <string>
#include <set>
class Preferences {
    std::string ns;
    static std::map<std::string, std::string>& values() {
        static std::map<std::string, std::string> data;
        return data;
    }
public:
    static inline std::set<std::string> failWrites;
    bool begin(const char* name, bool) { ns = std::string(name) + "/"; return true; }
    std::string getString(const char* key, const char* fallback = "") {
        auto it = values().find(ns + key);
        return it == values().end() ? fallback : it->second;
    }
    size_t putString(const char* key, const std::string& value) {
        if (failWrites.count(ns + key)) return 0;
        values()[ns + key] = value; return value.size();
    }
    int16_t getShort(const char* key, int16_t fallback = 0) {
        auto value = getString(key);
        return value.empty() ? fallback : static_cast<int16_t>(std::stoi(value));
    }
    size_t putShort(const char* key, int16_t value) { values()[ns + key] = std::to_string(value); return sizeof(value); }
    uint16_t getUShort(const char* key, uint16_t fallback) { return getShort(key, fallback); }
    size_t putUShort(const char* key, uint16_t value) { return putShort(key, value); }
    bool getBool(const char* key, bool fallback) { return getShort(key, fallback) != 0; }
    size_t putBool(const char* key, bool value) {
        if (failWrites.count(ns + key)) return 0;
        return putShort(key, value);
    }
    bool remove(const char* key) { return values().erase(ns + key) != 0; }
    bool clear() {
        for (auto it = values().begin(); it != values().end();) {
            if (it->first.compare(0, ns.size(), ns) == 0) it = values().erase(it);
            else ++it;
        }
        return true;
    }
    void end() {}
};
