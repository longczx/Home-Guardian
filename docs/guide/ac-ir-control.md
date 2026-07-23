# 用 ESP32 红外控制空调（海信/美的/格力/海尔通用）

国产成品空调（海信等）是"云锁定"的——它的 WiFi 模块只连厂商云，没法直连你的 MQTT。
本方案绕开这层，用一个 **ESP32 + 红外发射管**假装成空调遥控器：Home Guardian 把
"开机/制冷/26℃/自动风"这样的完整状态经 MQTT 下发给 ESP32，ESP32 合成对应品牌的
红外帧发射出去。

- ✅ 完全复用现有 MQTT 架构，本地控制，无厂商云依赖
- ✅ 不挑品牌——换空调只改一个协议常量
- ⚠️ 红外是**开环**（只发不收）：发完不保证空调真执行了，也读不到空调真实温度。
  强烈建议同一块 ESP32 再接一个 DHT11，用真实室温做闭环展示与自动化。

---

## 一、准备硬件

| 器件 | 说明 |
|---|---|
| ESP32 开发板 | 任意 esp32dev |
| 红外发射管（940nm） | 淘宝几毛钱一个 |
| NPN 三极管（如 S8050）+ 100Ω/1kΩ 电阻 | **必须**，勿把红外管直连 GPIO——电流不够射程只有几厘米 |
| DHT11/DHT22（可选，强烈建议） | 做闭环用，测真实室温 |

**红外发射驱动电路**（射程可达数米）：

```
ESP32 GPIO25 ──[1kΩ]── B (三极管基极)
3V3 ──[100Ω]── 红外管(+) ── 红外管(-) ── C (集电极)
                                          E (发射极) ── GND
```

把红外管**对准空调的接收窗**（一般在室内机显示屏附近）。

---

## 二、识别你的空调红外协议

IRremoteESP8266 内置 50+ 品牌协议。先搞清楚你这台是哪种：

1. 用 Arduino/PlatformIO 烧录库自带例程 **`IRrecvDumpV3`**（接一个红外**接收**头，如 VS1838B 到 GPIO14）。
2. 拿实体遥控对着接收头按几个键，看串口输出的 `Protocol` 行，例如：

   ```
   Protocol  : COOLIX
   ```

3. 常见结果：海信多为 `COOLIX` 或 `HITACHI_AC` 系；美的 `MIDEA`；格力 `GREE`；海尔 `HAIER_AC`；TCL `TCL112AC`。

> 没有红外接收头也行——先按下一步用默认 `COOLIX` 试，不生效再回来识别。

---

## 三、烧录固件

固件已内置红外空调执行器，只需在 `firmware/include/config.h`（从 `config.example.h` 复制）里打开开关：

```cpp
#define AC_IR_ENABLED   1                        // 启用
#define IR_LED_PIN      25                       // 你接红外管的 GPIO
#define AC_PROTOCOL     decode_type_t::COOLIX    // 换成上一步识别到的协议
```

`platformio.ini` 已包含 `crankyoldgit/IRremoteESP8266` 依赖，直接编译烧录：

```bash
cd firmware && pio run -t upload && pio device monitor
```

WiFi/MQTT 凭证走两种方式（与传感器一致）：
- **自助配网**（推荐）：保持 `WIFI_SSID="YourWiFi"` 占位值，开机进 SoftAP，在 App 生成配对码配网自注册；
- **手动烧录**：直接把真实 WiFi + 后台建好的设备 uid/密码填进 `config.h`。

---

## 四、在后台/App 里把它变成"空调"

设备上线后，还要给它套上"空调"能力（这决定 App 上显示什么控件）：

1. 进**管理后台**或 **App → 设备管理**，找到这台设备；
2. 编辑设备，**能力模板**选内置的 **「空调」**（数据库已预置，`device_category=ac`、`control_mode=merge`）；
3. 建议把**设备类型**设成 `ac`（不要留 `gateway`，否则首页会按网关隐藏它）；
4. 保存。

现在 App 设备详情页会出现：**电源开关 · 模式(制冷/制热/除湿/送风) · 温度(−/＋ 步进) · 风速 · 扫风**。关机时其余控件自动置灰。

---

## 五、验证

1. App 里打开这台空调 → 点"电源"；
2. 看 ESP32 串口应打印 `[CMD] 收到指令: action=set_state` 和红外发射日志；
3. 空调响一声、执行 → 成功。

工作链路：

```
App 改任一项 → 后端 ActuatorService(merge) 合并出完整状态
   → MQTT home/downstream/{uid}/command/set {action:set_state, params:{power,mode,temp,fan,swing}}
   → ESP32 IRac 合成红外帧发射 → 空调
   → ESP32 回 command/reply + 上报 state/post（多端同步显示）
```

**若空调没反应**：多半是协议不对（串口会打印"协议 X 不受 IRac 支持"或空调无响应）。回到第二步重新识别 `AC_PROTOCOL`；仍不行则该机型需走"原始码学习"（见下）。

---

## 六、闭环（强烈建议）

红外发完不知道空调真实状态。给这块 ESP32 同时开启 DHT11（`SENSOR_DHT11_ENABLED 1`），
它会作为子传感器上报真实室温湿度。这样你可以：

- App 上看到**空调控制卡 + 真实室温**并排；
- 建**自动化**："室温 > 28℃ → 下发 空调 制冷 24℃"；建**告警规则**："室温持续 5 分钟 > 32℃ 报警"（配合刚上线的双向迟滞防抖，抖动不会刷屏）。

---

## 七、协议不支持时的兜底：原始码学习

极少数机型 IRac 不支持。此时用"录制—回放"：对每个按键（开、关、16℃…30℃、各模式）
用 `IRrecvDumpV3` 抓原始 `rawData[]`，存进固件按 `set_state` 的目标状态回放对应码。
这条路万能但繁琐（每个组合一条码），需要时再单独做一版学习型固件。

---

## 参考

- IRremoteESP8266：<https://github.com/crankyoldgit/IRremoteESP8266>（`SupportedProtocols.md` 有完整协议清单）
- 能力模板定义：`database/php-migrations/2026_06_18_000001_create_capability_templates_table.php`
- 指令流转：`app/service/ActuatorService.php`、固件 `firmware/src/ac_ir.cpp`
