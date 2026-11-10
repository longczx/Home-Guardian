#pragma once
#include <map>
#include <string>
class Preferences {
    std::string ns;
    static std::map<std::string, std::string>& values() {
        static std::map<std::string, std::string> data;
        return data;
    }
public:
    bool begin(const char* name, bool) { ns = name; return true; }
    std::string getString(const char* key, const char* fallback = "") {
        auto it = values().find(ns + key);
        return it == values().end() ? fallback : it->second;
    }
    size_t putString(const char* key, const std::string& value) { values()[ns + key] = value; return value.size(); }
    int16_t getShort(const char* key, int16_t fallback = 0) {
        auto value = getString(key);
        return value.empty() ? fallback : static_cast<int16_t>(std::stoi(value));
    }
    size_t putShort(const char* key, int16_t value) { values()[ns + key] = std::to_string(value); return sizeof(value); }
};
