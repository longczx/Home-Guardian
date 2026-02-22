"""
Home Guardian - IoT 设备模拟器

使用方式:
    pip install paho-mqtt
    python simulator.py

每台虚拟设备会:
  1. 连接 EMQX，携带 LWT（遗嘱消息），断线时自动上报 offline
  2. 发布 state/post → {"status": "online"}
  3. 每隔 TELEMETRY_INTERVAL 秒发布一次随机遥测数据
  4. 优雅退出时发布 state/post → {"status": "offline"}

前提: 在 Admin 后台先创建好设备，记录 device_uid 和 mqtt_password。
"""

import json
import math
import os
import random
import signal
import threading
import time

import paho.mqtt.client as mqtt

# ─────────────────────────── 配置 ───────────────────────────────────────────

MQTT_HOST = os.getenv("MQTT_HOST", "127.0.0.1")
MQTT_PORT = int(os.getenv("MQTT_PORT", "1883"))

# 遥测上报间隔（秒）
TELEMETRY_INTERVAL = int(os.getenv("TELEMETRY_INTERVAL", "5"))

# 虚拟设备列表
# 每项字段:
#   uid      — 与 Admin 后台创建时填写的 device_uid 一致
#   password — 与 Admin 后台填写的 mqtt_password 一致（明文）
#   type     — 决定模拟哪些指标（见 generate_telemetry）
#   location — 仅用于日志可读性，不影响协议
DEVICES = [
    {"uid": "sensor-001", "password": "pass123", "type": "temperature_sensor", "location": "客厅"},
    {"uid": "sensor-002", "password": "pass123", "type": "temperature_sensor", "location": "卧室"},
    {"uid": "door-001",   "password": "pass123", "type": "door_sensor",        "location": "大门"},
    {"uid": "plug-001",   "password": "pass123", "type": "smart_plug",         "location": "厨房"},
    {"uid": "cam-001",    "password": "pass123", "type": "camera",             "location": "走廊"},
]

# ─────────────────────────── 遥测数据生成 ───────────────────────────────────

# 每台设备维持各自的内部状态，使数据看起来连续自然
_device_state: dict[str, dict] = {}


def _state(uid: str) -> dict:
    if uid not in _device_state:
        _device_state[uid] = {
            "temp_base":     random.uniform(18, 30),
            "humi_base":     random.uniform(40, 70),
            "door_open":     False,
            "door_timer":    0,
            "power_watts":   random.uniform(50, 300),
            "motion":        False,
            "motion_timer":  0,
            "tick":          0,
        }
    return _device_state[uid]


def generate_telemetry(device: dict) -> dict:
    uid  = device["uid"]
    kind = device["type"]
    s    = _state(uid)
    s["tick"] += 1
    t = s["tick"]

    if kind == "temperature_sensor":
        # 正弦波叠加随机噪声，模拟温度随时间缓慢波动
        temp = s["temp_base"] + 3 * math.sin(t / 20) + random.uniform(-0.3, 0.3)
        humi = s["humi_base"] + 5 * math.sin(t / 30 + 1) + random.uniform(-1, 1)
        return {
            "temperature": round(temp, 2),
            "humidity":    round(max(0, min(100, humi)), 2),
        }

    if kind == "door_sensor":
        # 随机触发开门事件，保持一段时间后自动关闭
        s["door_timer"] = max(0, s["door_timer"] - 1)
        if s["door_timer"] == 0 and random.random() < 0.05:
            s["door_open"]  = not s["door_open"]
            s["door_timer"] = random.randint(3, 10)
        return {
            "door_open":    int(s["door_open"]),
            "battery_level": random.randint(60, 100),
        }

    if kind == "smart_plug":
        # 功率在基准值附近随机抖动
        s["power_watts"] += random.uniform(-10, 10)
        s["power_watts"]  = max(0, min(3000, s["power_watts"]))
        return {
            "power_state": 1,
            "power_watts": round(s["power_watts"], 1),
            "voltage":     round(random.uniform(218, 222), 1),
        }

    if kind == "camera":
        # 偶尔触发移动检测
        s["motion_timer"] = max(0, s["motion_timer"] - 1)
        if s["motion_timer"] == 0 and random.random() < 0.08:
            s["motion"]       = True
            s["motion_timer"] = random.randint(2, 6)
        else:
            s["motion"] = s["motion_timer"] > 0
        return {
            "motion_detected": int(s["motion"]),
            "fps":             24,
            "storage_used_gb": round(random.uniform(10, 500), 1),
        }

    # 通用兜底
    return {"value": round(random.uniform(0, 100), 2)}


# ─────────────────────────── 单台设备线程 ────────────────────────────────────

class DeviceSimulator(threading.Thread):
    def __init__(self, device: dict):
        super().__init__(daemon=True)
        self.device   = device
        self.uid      = device["uid"]
        self.location = device.get("location", "")
        self._stop    = threading.Event()

        topic_prefix  = f"home/upstream/{self.uid}"
        self.t_telem  = f"{topic_prefix}/telemetry/post"
        self.t_state  = f"{topic_prefix}/state/post"

        self.client = mqtt.Client(client_id=f"sim_{self.uid}", clean_session=True)
        self.client.username_pw_set(device["uid"], device["password"])

        # LWT：连接异常中断时 EMQX 自动发布 offline
        self.client.will_set(
            self.t_state,
            payload=json.dumps({"status": "offline"}),
            qos=1,
            retain=False,
        )

        self.client.on_connect    = self._on_connect
        self.client.on_disconnect = self._on_disconnect
        self.client.on_message    = self._on_message

    # ── MQTT 回调 ────────────────────────────────────────────────────────────

    def _on_connect(self, client, userdata, flags, rc):
        if rc == 0:
            print(f"[{self.uid}] ✓ 已连接（{self.location}）")
            # 上线状态
            client.publish(self.t_state, json.dumps({"status": "online"}), qos=1)
            # 订阅下行指令（可扩展为实际处理）
            client.subscribe(f"home/downstream/{self.uid}/#", qos=1)
        else:
            codes = {
                1: "协议版本不支持", 2: "Client ID 被拒", 3: "服务不可用",
                4: "用户名或密码错误", 5: "无授权",
            }
            print(f"[{self.uid}] ✗ 连接失败: {codes.get(rc, f'rc={rc}')}")

    def _on_disconnect(self, client, userdata, rc):
        if rc != 0:
            print(f"[{self.uid}] 连接中断，将自动重连...")

    def _on_message(self, client, userdata, msg):
        """处理来自服务端的下行指令（仅打印，可按需扩展）"""
        try:
            payload = json.loads(msg.payload)
            print(f"[{self.uid}] ← 收到指令 [{msg.topic}]: {payload}")
            # 模拟指令执行成功，回复
            request_id = payload.get("request_id", "")
            if request_id:
                reply_topic = f"home/upstream/{self.uid}/command/reply"
                reply = {"request_id": request_id, "status": "ok"}
                self.client.publish(reply_topic, json.dumps(reply), qos=1)
                print(f"[{self.uid}] → 已回复指令 {request_id}")
        except Exception as e:
            print(f"[{self.uid}] 指令解析失败: {e}")

    # ── 线程主循环 ───────────────────────────────────────────────────────────

    def run(self):
        try:
            self.client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
            self.client.loop_start()
        except Exception as e:
            print(f"[{self.uid}] 无法连接到 {MQTT_HOST}:{MQTT_PORT} — {e}")
            return

        while not self._stop.is_set():
            if self.client.is_connected():
                data = generate_telemetry(self.device)
                self.client.publish(self.t_telem, json.dumps(data), qos=0)
                print(f"[{self.uid}] → 遥测: {data}")
            self._stop.wait(TELEMETRY_INTERVAL)

        # 优雅退出：主动发 offline
        if self.client.is_connected():
            self.client.publish(self.t_state, json.dumps({"status": "offline"}), qos=1)
            time.sleep(0.3)
        self.client.loop_stop()
        self.client.disconnect()
        print(f"[{self.uid}] 已断开")

    def stop(self):
        self._stop.set()


# ─────────────────────────── 入口 ───────────────────────────────────────────

def main():
    print(f"Home Guardian 设备模拟器")
    print(f"MQTT Broker : {MQTT_HOST}:{MQTT_PORT}")
    print(f"上报间隔    : {TELEMETRY_INTERVAL}s")
    print(f"模拟设备数  : {len(DEVICES)}")
    print("-" * 40)

    simulators = [DeviceSimulator(d) for d in DEVICES]
    for s in simulators:
        s.start()
        time.sleep(0.2)  # 错开连接时间，避免同时握手

    # 捕获 Ctrl+C / SIGTERM，优雅停止
    stop_event = threading.Event()

    def _shutdown(signum, frame):
        print("\n正在停止所有模拟器...")
        for s in simulators:
            s.stop()
        stop_event.set()

    signal.signal(signal.SIGINT,  _shutdown)
    signal.signal(signal.SIGTERM, _shutdown)

    stop_event.wait()
    for s in simulators:
        s.join(timeout=3)
    print("模拟器已全部停止。")


if __name__ == "__main__":
    main()
