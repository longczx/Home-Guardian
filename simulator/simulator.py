"""
Home Guardian - IoT 设备模拟器

使用方式:
    pip install -r requirements.txt
    python simulator.py                         # 使用下方 DEVICES 列表
    python simulator.py --api                   # 从 API 自动发现设备

每台虚拟设备会:
  1. 连接 EMQX，携带 LWT（遗嘱消息），断线时自动上报 offline
  2. 发布 state/post → {"status": "online"}
  3. 每隔 TELEMETRY_INTERVAL 秒发布一次随机遥测数据
  4. 优雅退出时发布 state/post → {"status": "offline"}

--api 模式:
  从 REST API 获取设备列表及其 metric_fields 配置，
  自动为每个指标生成逼真的模拟数据（正弦波 + 噪声）。
  需要设置 API_BASE_URL 和 API_TOKEN 环境变量，或者
  API_USERNAME / API_PASSWORD 自动登录获取 Token。

前提: 在 Admin 后台先创建好设备，记录 device_uid 和 mqtt_password。
"""

import argparse
import json
import math
import os
import random
import signal
import threading
import time

import paho.mqtt.client as mqtt
import requests

# ─────────────────────────── 配置 ───────────────────────────────────────────

MQTT_HOST = os.getenv("MQTT_HOST", "127.0.0.1")
MQTT_PORT = int(os.getenv("MQTT_PORT", "1883"))

# 遥测上报间隔（秒）
TELEMETRY_INTERVAL = int(os.getenv("TELEMETRY_INTERVAL", "5"))

# API 配置（--api 模式使用）
API_BASE_URL = os.getenv("API_BASE_URL", "http://127.0.0.1:8787/api")
API_TOKEN    = os.getenv("API_TOKEN", "")
API_USERNAME = os.getenv("API_USERNAME", "admin")
API_PASSWORD = os.getenv("API_PASSWORD", "admin123")

# 所有设备统一使用的 MQTT 密码（--api 模式下需要设置）
DEFAULT_MQTT_PASSWORD = os.getenv("DEFAULT_MQTT_PASSWORD", "pass123")

# 虚拟设备列表（手动模式使用）
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

# ─────────────────────────── API 自动发现 ─────────────────────────────────────

def api_login() -> str:
    """通过 API 登录获取 access_token"""
    url = f"{API_BASE_URL}/auth/login"
    resp = requests.post(url, json={"username": API_USERNAME, "password": API_PASSWORD}, timeout=10)
    resp.raise_for_status()
    data = resp.json()
    if data.get("code") != 0:
        raise RuntimeError(f"登录失败: {data.get('message', '未知错误')}")
    return data["data"]["access_token"]


def api_fetch_devices(token: str) -> list[dict]:
    """从 API 获取设备列表（含 metric_fields）"""
    url = f"{API_BASE_URL}/devices"
    headers = {"Authorization": f"Bearer {token}"}
    resp = requests.get(url, headers=headers, params={"per_page": 200}, timeout=10)
    resp.raise_for_status()
    data = resp.json()
    if data.get("code") != 0:
        raise RuntimeError(f"获取设备列表失败: {data.get('message')}")
    return data["data"]["items"]


def api_fetch_metric_definitions(token: str) -> list[dict]:
    """从 API 获取全局指标定义"""
    url = f"{API_BASE_URL}/metric-definitions"
    headers = {"Authorization": f"Bearer {token}"}
    resp = requests.get(url, headers=headers, timeout=10)
    resp.raise_for_status()
    data = resp.json()
    if data.get("code") != 0:
        return []
    return data["data"]


def discover_devices() -> list[dict]:
    """自动发现设备，返回适配模拟器的设备配置列表"""
    token = API_TOKEN
    if not token:
        print(f"正在登录 API ({API_BASE_URL}) ...")
        token = api_login()
        print("✓ 登录成功")

    print("正在获取设备列表 ...")
    api_devices = api_fetch_devices(token)
    print(f"✓ 获取到 {len(api_devices)} 台设备")

    print("正在获取全局指标定义 ...")
    global_defs = api_fetch_metric_definitions(token)
    print(f"✓ 获取到 {len(global_defs)} 个全局指标定义")

    # 构建全局指标 key 列表（作为 metric_fields=null 时的默认值）
    global_metric_keys = [d["metric_key"] for d in global_defs]

    devices = []
    for d in api_devices:
        metric_fields = d.get("metric_fields")

        # 确定该设备上报哪些指标 key
        if isinstance(metric_fields, list) and len(metric_fields) > 0:
            metric_keys = [f["key"] for f in metric_fields]
        elif isinstance(metric_fields, list) and len(metric_fields) == 0:
            # 显式配置为空，跳过
            print(f"  [{d['device_uid']}] metric_fields=[], 跳过")
            continue
        else:
            # NULL → 使用全局定义
            metric_keys = global_metric_keys if global_metric_keys else ["temperature", "humidity"]

        devices.append({
            "uid":          d["device_uid"],
            "password":     DEFAULT_MQTT_PASSWORD,
            "type":         d.get("type", "sensor"),
            "location":     d.get("location", ""),
            "metric_keys":  metric_keys,
        })
        print(f"  [{d['device_uid']}] 指标: {', '.join(metric_keys)}")

    return devices

# ─────────────────────────── 遥测数据生成 ───────────────────────────────────

# 每种指标的模拟参数: 基准值、振幅、噪声幅度
METRIC_PROFILES: dict[str, dict] = {
    "temperature":  {"base": 23.0,   "amplitude": 5.0,   "noise": 0.5},
    "humidity":     {"base": 55.0,   "amplitude": 15.0,  "noise": 2.0},
    "pressure":     {"base": 1013.0, "amplitude": 10.0,  "noise": 1.0},
    "co2":          {"base": 500.0,  "amplitude": 200.0, "noise": 20.0},
    "light":        {"base": 300.0,  "amplitude": 250.0, "noise": 15.0},
    "pm25":         {"base": 35.0,   "amplitude": 20.0,  "noise": 3.0},
    "battery_level": {"base": 80.0,  "amplitude": 5.0,   "noise": 1.0},
    "power_watts":  {"base": 150.0,  "amplitude": 80.0,  "noise": 10.0},
    "voltage":      {"base": 220.0,  "amplitude": 2.0,   "noise": 0.5},
}

DEFAULT_PROFILE = {"base": 50.0, "amplitude": 10.0, "noise": 2.0}

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
            "phase_offset":  random.uniform(0, 2 * math.pi),
        }
    return _device_state[uid]


def generate_telemetry_by_keys(device: dict) -> dict:
    """根据 metric_keys 列表动态生成遥测数据"""
    uid = device["uid"]
    keys = device.get("metric_keys", [])
    s = _state(uid)
    s["tick"] += 1
    t = s["tick"]
    phase = s["phase_offset"]

    result = {}
    for key in keys:
        profile = METRIC_PROFILES.get(key, DEFAULT_PROFILE)
        base      = profile["base"]
        amplitude = profile["amplitude"]
        noise     = profile["noise"]

        # 正弦波 + 随机噪声，每个指标不同周期使曲线错开
        period_ticks = 200 + hash(key) % 100  # 各指标周期略有不同
        sin_val = math.sin(2 * math.pi * t / period_ticks + phase)
        value = base + amplitude * sin_val + random.uniform(-noise, noise)
        result[key] = round(value, 2)

    return result


def generate_telemetry(device: dict) -> dict:
    """生成遥测数据：有 metric_keys 则按配置生成，否则按 type 走原有逻辑"""
    if "metric_keys" in device:
        return generate_telemetry_by_keys(device)

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
    parser = argparse.ArgumentParser(description="Home Guardian IoT 设备模拟器")
    parser.add_argument("--api", action="store_true",
                        help="从 REST API 自动发现设备及其 metric_fields 配置")
    args = parser.parse_args()

    print("Home Guardian 设备模拟器")
    print(f"MQTT Broker : {MQTT_HOST}:{MQTT_PORT}")
    print(f"上报间隔    : {TELEMETRY_INTERVAL}s")

    if args.api:
        print(f"模式        : API 自动发现")
        print("-" * 50)
        devices = discover_devices()
        if not devices:
            print("没有可模拟的设备，请先在 Admin 后台创建设备并配置指标")
            return
    else:
        devices = DEVICES
        print(f"模式        : 手动配置 ({len(devices)} 台)")

    print("-" * 50)
    print(f"启动 {len(devices)} 台模拟设备...")

    simulators = [DeviceSimulator(d) for d in devices]
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
