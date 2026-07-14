#include "provisioning.h"
#include <WiFi.h>
#include "config.h"

static const byte DNS_PORT = 53;

void Provisioning::begin(ConfigStore* store) {
    _store = store;
    _done = false;

    // 热点名带 MAC 后缀，便于区分多台设备
    String uid = ConfigStore::deviceUid();          // esp32-aabbcc
    String ap = "HG-Setup-" + uid.substring(uid.length() - 4);

    WiFi.mode(WIFI_AP);
    WiFi.softAP(ap.c_str());
    IPAddress ip = WiFi.softAPIP();  // 默认 192.168.4.1
    Serial.printf("[配网] 热点已开: %s  →  http://%s\n", ap.c_str(), ip.toString().c_str());

    // captive portal：所有域名解析到本机
    _dns.start(DNS_PORT, "*", ip);

    _server.on("/", HTTP_GET, [this]() { handleRoot(); });
    _server.on("/scan", HTTP_GET, [this]() { handleScan(); });
    _server.on("/provision", HTTP_POST, [this]() { handleProvision(); });
    // 常见系统探测路径 → 重定向到配网页，触发弹窗
    _server.onNotFound([this]() {
        _server.sendHeader("Location", "http://192.168.4.1/", true);
        _server.send(302, "text/plain", "");
    });
    _server.begin();
}

void Provisioning::loop() {
    _dns.processNextRequest();
    _server.handleClient();
}

void Provisioning::handleRoot() {
    String surl = _store->serverUrl();
#ifdef DEFAULT_SERVER_URL
    if (surl.length() == 0) surl = DEFAULT_SERVER_URL;
#endif

    String html =
        "<!doctype html><html><head><meta charset='utf-8'>"
        "<meta name='viewport' content='width=device-width,initial-scale=1'>"
        "<title>Home Guardian 配网</title>"
        "<style>body{font-family:sans-serif;max-width:420px;margin:0 auto;padding:20px;background:#f5f6f8}"
        "h2{text-align:center}label{display:block;margin:12px 0 4px;font-size:14px;color:#333}"
        "input,select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:8px;font-size:15px}"
        "button{width:100%;margin-top:18px;padding:12px;border:0;border-radius:8px;background:#3b82f6;color:#fff;font-size:16px}"
        ".hint{font-size:12px;color:#888;margin-top:14px;line-height:1.6}</style></head><body>"
        "<h2>设备配网</h2>"
        "<form method='POST' action='/provision'>"
        "<label>WiFi 名称</label><select name='ssid' id='ssid'><option>加载中...</option></select>"
        "<label>WiFi 密码</label><input type='password' name='pass' placeholder='家里 WiFi 密码'>"
        "<label>配对码</label><input name='code' placeholder='App 上生成的配对码' autocapitalize='characters'>"
        "<label>平台地址</label><input name='server' value='" + surl + "' placeholder='https://your-server'>"
        "<button type='submit'>连接并配网</button>"
        "</form>"
        "<div class='hint'>在 Home Guardian App「添加设备」生成配对码后粘贴到上面，填入家里 WiFi 即可。</div>"
        "<script>fetch('/scan').then(r=>r.json()).then(l=>{var s=document.getElementById('ssid');"
        "s.innerHTML='';(l||[]).forEach(function(n){var o=document.createElement('option');o.value=n;o.text=n;s.add(o);});"
        "if(!l||!l.length){var o=document.createElement('option');o.text='(未扫描到，手动输入)';s.add(o);}});</script>"
        "</body></html>";

    _server.send(200, "text/html", html);
}

void Provisioning::handleScan() {
    int n = WiFi.scanNetworks();
    String json = "[";
    for (int i = 0; i < n; i++) {
        if (i) json += ",";
        String ssid = WiFi.SSID(i);
        ssid.replace("\"", "\\\"");
        json += "\"" + ssid + "\"";
    }
    json += "]";
    WiFi.scanDelete();
    _server.send(200, "application/json", json);
}

void Provisioning::handleProvision() {
    String ssid   = _server.arg("ssid");
    String pass   = _server.arg("pass");
    String code   = _server.arg("code");
    String server = _server.arg("server");

    if (ssid.length() == 0) {
        _server.send(400, "text/html", "<h3>请选择 WiFi</h3>");
        return;
    }
    code.trim();
    server.trim();

    _store->saveProvisioning(ssid, pass, server, code);
    Serial.printf("[配网] 已保存: ssid=%s server=%s code=%s\n", ssid.c_str(), server.c_str(), code.c_str());

    _server.send(200, "text/html",
        "<!doctype html><meta charset='utf-8'><body style='font-family:sans-serif;text-align:center;padding:40px'>"
        "<h2>配网中…</h2><p>设备正在连接 WiFi 并注册，请回到 App 等待上线。</p></body>");

    _done = true;  // main 会在 loop 里看到并重启
}
