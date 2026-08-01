/*
 * 红外接收自检 / 空调协议识别 —— 一箭双雕
 *
 *  用途 A（识别协议）：对着接收头按海信空调【实体遥控】的任意键，
 *     串口打印的 `Protocol:` 就是你要用的协议名（填进 config.h 的 AC_PROTOCOL，
 *     或直接在 App「红外协议」里选同名项）。常见：COOLIX / HITACHI_AC / GREE …
 *
 *  用途 B（确认发射管）：把跑着 ir_send_test 的板子对准本接收头，
 *     串口应持续收到 NEC 0xFFE01F —— 收到即证明发射管+接线正常。
 *
 * 接收头（如 VS1838B）：VCC→3V3，GND→GND，DATA→kRecvPin。
 *   接收头可以用只输入引脚（如 GPIO14/GPIO34 都行）。
 *
 * 依赖库：IRremoteESP8266
 */
#include <Arduino.h>
#include <IRremoteESP8266.h>
#include <IRrecv.h>
#include <IRutils.h>

const uint16_t kRecvPin = 14;    // ← 改成你接接收头 DATA 的 GPIO

IRrecv irrecv(kRecvPin, 1024, 50, true);
decode_results results;

void setup() {
  Serial.begin(115200);
  delay(200);
  irrecv.enableIRIn();
  Serial.printf("\n[IR-RECV-TEST] 接收头接在 GPIO%d，等待红外信号...\n", kRecvPin);
  Serial.println("对着接收头按空调实体遥控 → 看下面的 Protocol 行。");
}

void loop() {
  if (irrecv.decode(&results)) {
    Serial.println("---------------- 收到红外 ----------------");
    Serial.print("Protocol : ");
    Serial.println(typeToString(results.decode_type, results.repeat));  // ← 这行就是 AC_PROTOCOL
    Serial.print("Code     : ");
    serialPrintUint64(results.value, HEX);
    Serial.println();
    Serial.print("Bits     : ");
    Serial.println(results.bits);
    Serial.println(resultToHumanReadableBasic(&results));
    irrecv.resume();
  }
}
