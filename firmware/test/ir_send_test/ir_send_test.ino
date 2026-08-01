/*
 * 红外发射自检 —— 确认「发射管 + 接线 + 引脚」是否正常工作
 *
 * 每秒发送一个已知的 NEC 码。用任一方式验证发射管确实在发红外：
 *   1) 【最快】手机摄像头对准红外发射管：发射瞬间应看到淡紫色/白色闪烁。
 *      （人眼看不见红外，但手机 CMOS 能拍到。前置摄像头通常更灵敏。）
 *   2) 用配套的 ir_receive_test 接收自检：把本发射管对准接收头，
 *      接收端串口应持续打印收到 NEC 0xFFE01F。
 *
 * ⚠️ 关键：kIrLedPin 必须是「可输出」的 GPIO。ESP32 的 GPIO34/35/36/39 是
 *    只输入引脚，接发射管不会有任何输出——这是"完全没反应"最常见的原因。
 *    推荐用 GPIO25 / 26 / 27 / 4 / 17 等。
 *
 * 依赖库：IRremoteESP8266（Arduino IDE 库管理器搜 "IRremoteESP8266" 安装）
 */
#include <Arduino.h>
#include <IRremoteESP8266.h>
#include <IRsend.h>

const uint16_t kIrLedPin = 25;   // ← 改成你实际接红外发射管的 GPIO（须可输出）

IRsend irsend(kIrLedPin);

void setup() {
  Serial.begin(115200);
  delay(200);
  irsend.begin();
  Serial.println("\n[IR-SEND-TEST] 启动");
  Serial.printf("发射管接在 GPIO%d，每秒发一个 NEC 码 (0x00FFE01F)\n", kIrLedPin);
  Serial.println("验证：手机摄像头对准发射管，发射瞬间应见淡紫闪烁。");
}

void loop() {
  irsend.sendNEC(0x00FFE01F, 32);
  Serial.println("→ 已发送 NEC 0x00FFE01F");
  delay(1000);
}
