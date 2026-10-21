# 海信红外固件 1.2.0：刷入与现场验收

本版本接入 IRremoteESP8266 2.9.0 的原生 KELON168 编码器，候选遥控器为 DG11R2-01 / RCH-R0Y3。
品牌相同不代表协议相同；尚未在你的空调上验证，不能保证匹配。App 的选项更新不能代替刷固件。

## 刷入 ESP32

仅适用于本项目的经典 ESP32 esp32dev；不是 ESP32-C3 / S3 固件。包内不含 Wi-Fi / MQTT 密码。
已有网关按以下地址刷写不会擦除 NVS 配网凭证和模块清单。不要执行 erase_flash，不要长按 BOOT 五秒恢复出厂。

1. USB 连接 ESP32，用 `python3 -m serial.tools.list_ports` 查找串口。
2. 安装工具：`python3 -m pip install 'esptool==4.11.0' pyserial`。
3. 在固件包目录执行 `sha256sum -c SHA256SUMS`。
4. 将 `/dev/ttyUSB0` 替换为实际端口后刷写：

```bash
python3 -m esptool --chip esp32 --port /dev/ttyUSB0 --baud 460800 \
  --before default_reset --after hard_reset write_flash -z \
  --flash_mode dio --flash_freq 40m --flash_size detect \
  0x1000 bootloader.bin 0x8000 partitions.bin \
  0xe000 boot_app0.bin 0x10000 firmware.bin
```

烧录失败时可按住 BOOT，开始连接后松开。串口监视波特率为 115200。
首次配置使用 App 生成配对码；已有网关应保留 NVS。若无红外子设备，在网关模块清单添加 `ac_ir`，GPIO 与实际接线一致。
升级后的 `get_info` 回执包含 `firmware=1.2.0`、`ir.pin`、`ir.protocol`；回执和状态均不代表空调真实状态。

## 逐步测试，区分协议和硬件

1. 等网关和空调子设备在线，确认连接的是当前服务器。
2. 用原装遥控器关闭空调，之后测试期间不要混用遥控器。KELON168 有电源切换位，重启/换协议后固件按“物理空调已关闭”建立起点。
3. 在 App 选择 KELON168，设制冷、25℃、自动风；对准接收窗，在约 0.5～1 米距离点开机，观察蜂鸣/显示。
4. 若开机响应，再调到 26℃、调风、扫风，最后关机。调温不应再次触发电源开关。
5. 串口应看到 `[AC-IR] 发射完成 protocol=KELON168 pin=...`；指令回执含 `ir_transmitted=true` 和 `physical_state_confirmed=false`。

如果原装遥控能用，但模块没反应：用同一摄像头先观察原装遥控，再观察模块。
原装可见而模块不可见，检查 GPIO、极性、共地、模块供电和驱动；两者都可见，只能证明有红外光，仍需确认载波与协议。
有些摄像头过滤红外，不能仅凭拍不到就判定 LED 坏。不要把裸红外管直接接 GPIO；成品模块是否带驱动需查看规格。

仍无反应时记录空调/遥控器型号及接线照片；有接收头时用上游 `IRrecvDumpV3` 识别协议。当前固件不提供原始码学习，也不通过云端自动扫描协议或反复开关空调。

## 复现编码测试与构建

```bash
cd firmware
pio run
cd ..
bash scripts/test-ir-encoder.sh
HG_FIRMWARE_SERVER_URL=http://104.233.207.163 bash scripts/package-ir-firmware.sh
```

软件检查覆盖 168 位帧校验和、38kHz 载波、温度/模式/风速/扫风字段及电源变化；它不能检测实物发射管或接收窗。

参考：[上游支持机型](https://github.com/crankyoldgit/IRremoteESP8266/blob/v2.9.0/src/ir_Kelon.h)、[排障说明](https://github.com/crankyoldgit/IRremoteESP8266/wiki/Troubleshooting-Guide)。
