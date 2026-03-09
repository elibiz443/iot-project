import os
import json
import time
import socket
import base64
import signal
import logging
import hashlib
from datetime import datetime, timezone
from pathlib import Path

import paho.mqtt.client as mqtt

try:
  import cv2
except Exception:
  cv2 = None

try:
  from ultralytics import YOLO
except Exception:
  YOLO = None

try:
  import requests
except Exception:
  requests = None

try:
  from picamera2 import Picamera2
except Exception:
  Picamera2 = None


def env_str(key, default=""):
  v = os.getenv(key)
  return default if v is None else v


def env_int(key, default):
  try:
    return int(env_str(key, str(default)))
  except Exception:
    return default


def env_float(key, default):
  try:
    return float(env_str(key, str(default)))
  except Exception:
    return default


def env_bool(key, default=False):
  return env_str(key, "1" if default else "0").strip().lower() in ("1", "true", "yes", "on")


def now_iso():
  return datetime.now(timezone.utc).isoformat()


def hostname():
  try:
    return socket.gethostname()
  except Exception:
    return "iot-device"


def get_ip():
  try:
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.connect(("8.8.8.8", 80))
    ip = s.getsockname()[0]
    s.close()
    return ip
  except Exception:
    return ""


def read_cpu_temp_c():
  for p in ("/sys/class/thermal/thermal_zone0/temp", "/sys/devices/virtual/thermal/thermal_zone0/temp"):
    try:
      raw = Path(p).read_text().strip()
      v = float(raw)
      return round(v / 1000.0 if v > 1000 else v, 1)
    except Exception:
      pass
  return None


def disk_usage(path="/"):
  try:
    st = os.statvfs(path)
    total = st.f_frsize * st.f_blocks
    free = st.f_frsize * st.f_bfree
    used = total - free
    return {
      "total_mb": int(total / (1024 * 1024)),
      "used_mb": int(used / (1024 * 1024)),
      "free_mb": int(free / (1024 * 1024)),
      "used_pct": round((used / total) * 100.0, 1) if total else None,
    }
  except Exception:
    return None


def sha1(s):
  return hashlib.sha1(s.encode("utf-8")).hexdigest()


class Vision:
  def __init__(self, mode, camera_index, width, height, yolo_model_path, face_min_size):
    self.mode = mode
    self.camera_index = camera_index
    self.width = width
    self.height = height
    self.yolo_model_path = yolo_model_path
    self.face_min_size = face_min_size
    self.cap = None
    self.picam2 = None
    self.model = None
    self.face = None
    self.use_picamera2 = False

  def start(self):
    if cv2 is None:
      return False

    if Picamera2 is not None:
      try:
        self.picam2 = Picamera2()
        config = self.picam2.create_preview_configuration(
          main={"size": (self.width, self.height), "format": "RGB888"}
        )
        self.picam2.configure(config)
        self.picam2.start()
        time.sleep(1)
        self.use_picamera2 = True
      except Exception:
        self.picam2 = None
        self.use_picamera2 = False

    if not self.use_picamera2:
      try:
        self.cap = cv2.VideoCapture(self.camera_index, cv2.CAP_V4L2)
        if not self.cap.isOpened():
          self.cap.release()
          self.cap = None
          return False
        self.cap.set(cv2.CAP_PROP_FRAME_WIDTH, self.width)
        self.cap.set(cv2.CAP_PROP_FRAME_HEIGHT, self.height)
      except Exception:
        self.cap = None
        return False

    if self.mode == "yolo" and YOLO is not None and self.yolo_model_path:
      try:
        self.model = YOLO(self.yolo_model_path)
      except Exception:
        self.model = None

    try:
      cascade_path = cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
      self.face = cv2.CascadeClassifier(cascade_path)
    except Exception:
      self.face = None

    return True

  def stop(self):
    try:
      if self.picam2 is not None:
        self.picam2.stop()
    except Exception:
      pass

    try:
      if self.cap is not None:
        self.cap.release()
    except Exception:
      pass

  def read(self):
    if self.use_picamera2 and self.picam2 is not None:
      try:
        frame = self.picam2.capture_array()
        if frame is None:
          return None
        return cv2.cvtColor(frame, cv2.COLOR_RGB2BGR)
      except Exception:
        return None

    if self.cap is None:
      return None

    ok, frame = self.cap.read()
    return frame if ok else None

  def detect(self, frame, conf_thr):
    detections, faces = [], 0

    if self.face is not None:
      try:
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        found = self.face.detectMultiScale(
          gray,
          scaleFactor=1.1,
          minNeighbors=5,
          minSize=(self.face_min_size, self.face_min_size)
        )
        faces = 0 if found is None else len(found)
      except Exception:
        faces = 0

    if self.mode == "yolo" and self.model is not None:
      try:
        res = self.model(frame, verbose=False)[0]
        for b in getattr(res, "boxes", []) or []:
          conf = float(b.conf[0])
          if conf < conf_thr:
            continue
          cls = int(b.cls[0])
          label = str(self.model.names.get(cls, cls))
          xyxy = b.xyxy[0].tolist()
          detections.append({
            "label": label,
            "conf": round(conf, 3),
            "box": [int(x) for x in xyxy]
          })
      except Exception:
        pass

    return detections, faces


class DeviceAgent:
  def __init__(self):
    self.device_id = env_str("DEVICE_ID", hostname())
    self.http_base = env_str("HTTP_BASE_URL", "https://www.iot.ellyambet.com").rstrip("/")
    self.http_token = env_str("DEVICE_HTTP_TOKEN", "fb78ad63a5d1587dfc1c55507eccb29300a840c9d040afa3e99e2ec32cf3b4b7")
    self.http_enabled = bool(self.http_base and self.http_token and requests is not None)
    self.http_timeout = env_int("HTTP_TIMEOUT_SEC", 10)

    self.mqtt_enabled = env_bool("MQTT_ENABLED", False)
    self.broker = env_str("MQTT_BROKER", "localhost")
    self.port = env_int("MQTT_PORT", 1883)
    self.username = env_str("MQTT_USERNAME", "")
    self.password = env_str("MQTT_PASSWORD", "")
    self.keepalive = env_int("MQTT_KEEPALIVE", 60)
    self.topic_base = env_str("MQTT_TOPIC_BASE", f"home/iot/{self.device_id}")
    self.topic_events = f"{self.topic_base}/events"
    self.topic_telemetry = f"{self.topic_base}/telemetry"
    self.topic_online = f"{self.topic_base}/status/online"
    self.client = None
    self.connected = False

    self.heartbeat_sec = env_int("HEARTBEAT_SEC", 30)
    self.capture_sec = env_int("CAPTURE_SEC", 2)
    self.live_frame_sec = env_float("LIVE_FRAME_SEC", 1.0)
    self.command_poll_sec = env_int("COMMAND_POLL_SEC", 3)
    self.event_cooldown_sec = env_int("EVENT_COOLDOWN_SEC", 8)
    self.conf_thr = env_float("CONFIDENCE_THRESHOLD", 0.45)
    self.vision_mode = env_str("VISION_MODE", "face").strip().lower()
    self.camera_index = env_int("CAMERA_INDEX", 0)
    self.cam_w = env_int("CAMERA_WIDTH", 320)
    self.cam_h = env_int("CAMERA_HEIGHT", 240)
    self.yolo_model = env_str("YOLO_MODEL", "")
    self.face_min_size = env_int("FACE_MIN_SIZE", 60)

    self.snapshot_dir = Path(env_str("SNAPSHOT_DIR", "/var/tmp/iot_snapshots"))
    self.snapshot_dir.mkdir(parents=True, exist_ok=True)
    self.snapshot_jpeg_quality = env_int("SNAPSHOT_JPEG_QUALITY", 85)
    self.send_snapshot_base64 = env_bool("SEND_SNAPSHOT_BASE64", False)
    self.snapshot_base64_max_kb = env_int("SNAPSHOT_BASE64_MAX_KB", 200)

    self.vision = Vision(
      self.vision_mode,
      self.camera_index,
      self.cam_w,
      self.cam_h,
      self.yolo_model,
      self.face_min_size
    )
    self.log = logging.getLogger("iot-agent")
    self.stop_flag = False
    self.start_ts = time.time()
    self.last_heartbeat = 0.0
    self.last_capture = 0.0
    self.last_live_frame = 0.0
    self.last_command_poll = 0.0
    self.last_event = 0.0
    self.last_sig = ""
    self.last_frame = None
    self.vision_enabled = True

  def setup_logging(self):
    lvl = env_str("LOG_LEVEL", "INFO").upper()
    logging.basicConfig(
      level=getattr(logging, lvl, logging.INFO),
      format="%(asctime)s %(levelname)s %(message)s"
    )

  def build_mqtt(self):
    try:
      c = mqtt.Client(
        mqtt.CallbackAPIVersion.VERSION2,
        client_id=self.device_id,
        protocol=mqtt.MQTTv311
      )
    except Exception:
      c = mqtt.Client(client_id=self.device_id, protocol=mqtt.MQTTv311)

    if self.username:
      c.username_pw_set(self.username, self.password or None)
    c.will_set(self.topic_online, payload="0", qos=1, retain=True)
    c.on_connect = self.on_connect
    c.on_disconnect = self.on_disconnect
    return c

  def on_connect(self, client, userdata, flags, rc, properties=None):
    self.connected = int(getattr(rc, "value", rc)) == 0
    if self.connected:
      self.publish_mqtt(self.topic_online, "1", qos=1, retain=True)

  def on_disconnect(self, client, userdata, rc, properties=None, reasonCode=None):
    self.connected = False

  def publish_mqtt(self, topic, payload, qos=1, retain=False):
    if not self.client:
      return False
    if isinstance(payload, (dict, list)):
      payload = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
    try:
      info = self.client.publish(topic, payload=payload, qos=qos, retain=retain)
      info.wait_for_publish(timeout=3)
      return info.rc == mqtt.MQTT_ERR_SUCCESS
    except Exception:
      return False

  def headers(self):
    return {"X-Device-Token": self.http_token} if self.http_enabled else {}

  def http_json(self, api, body=None, method="POST", params=None):
    if not self.http_enabled:
      return None
    try:
      url = f"{self.http_base}/admin/controllers/device_api.php?api={api}"
      if method == "GET":
        r = requests.get(
          url,
          headers=self.headers(),
          params=params,
          timeout=self.http_timeout
        )
      else:
        r = requests.post(
          url,
          headers={**self.headers(), "Content-Type": "application/json"},
          json=body,
          timeout=self.http_timeout
        )
      if 200 <= r.status_code < 300:
        return r.json()
      self.log.warning(f"HTTP {api} status={r.status_code} body={r.text[:300]}")
    except Exception as e:
      self.log.warning(f"HTTP {api} failed: {e}")
    return None

  def http_file(self, api, image_bytes):
    if not self.http_enabled:
      return None
    try:
      url = f"{self.http_base}/admin/controllers/device_api.php?api={api}"
      files = {"image": ("frame.jpg", image_bytes, "image/jpeg")}
      data = {"device_id": self.device_id}
      r = requests.post(
        url,
        headers=self.headers(),
        data=data,
        files=files,
        timeout=self.http_timeout
      )
      if 200 <= r.status_code < 300:
        return r.json()
      self.log.warning(f"HTTP upload {api} status={r.status_code} body={r.text[:300]}")
    except Exception as e:
      self.log.warning(f"HTTP upload {api} failed: {e}")
    return None

  def encode_jpeg(self, frame):
    if cv2 is None or frame is None:
      return None
    ok, buf = cv2.imencode(
      ".jpg",
      frame,
      [int(cv2.IMWRITE_JPEG_QUALITY), int(self.snapshot_jpeg_quality)]
    )
    return bytes(buf) if ok else None

  def telemetry_payload(self):
    return {
      "ts": now_iso(),
      "device_id": self.device_id,
      "ip": get_ip(),
      "uptime_s": int(time.time() - self.start_ts),
      "cpu_temp_c": read_cpu_temp_c(),
      "disk": disk_usage("/")
    }

  def event_payload(self, detections, faces, snapshot_path=None, snapshot_b64=None, uploaded_url=None):
    return {
      "ts": now_iso(),
      "device_id": self.device_id,
      "ip": get_ip(),
      "faces": int(faces),
      "labels": [d["label"] for d in detections],
      "detections": detections,
      "snapshot_path": snapshot_path,
      "snapshot_b64": snapshot_b64,
      "snapshot_url": uploaded_url
    }

  def signature(self, detections, faces):
    counts = {}
    for d in detections:
      counts[d["label"]] = counts.get(d["label"], 0) + 1
    return sha1("|".join(
      [f"faces:{int(faces)}"] +
      [f"{k}:{counts[k]}" for k in sorted(counts)]
    ))

  def emit_telemetry(self):
    payload = self.telemetry_payload()
    if self.http_enabled:
      self.http_json("ingest", {
        "kind": "telemetry",
        "device_id": self.device_id,
        "payload": payload
      })
    if self.mqtt_enabled:
      self.publish_mqtt(self.topic_telemetry, payload, qos=1, retain=False)

  def emit_online(self, on):
    body = {
      "kind": "online",
      "device_id": self.device_id,
      "online": bool(on),
      "ip": get_ip()
    }
    if self.http_enabled:
      self.http_json("ingest", body)
    if self.mqtt_enabled:
      self.publish_mqtt(self.topic_online, "1" if on else "0", qos=1, retain=True)

  def emit_event(self, frame, detections, faces):
    sig = self.signature(detections, faces)
    if not detections and faces == 0:
      self.last_sig = sig
      return

    now = time.time()
    if sig == self.last_sig and (now - self.last_event) < self.event_cooldown_sec:
      return

    self.last_sig = sig
    self.last_event = now

    image_bytes = self.encode_jpeg(frame)
    snapshot_path = None
    snapshot_b64 = None
    snapshot_url = None

    if image_bytes:
      fname = f"{self.device_id}_{datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')}.jpg"
      local_path = self.snapshot_dir / fname
      try:
        local_path.write_bytes(image_bytes)
        snapshot_path = str(local_path)
      except Exception:
        snapshot_path = None

      if self.send_snapshot_base64 and len(image_bytes) / 1024.0 <= self.snapshot_base64_max_kb:
        snapshot_b64 = base64.b64encode(image_bytes).decode("ascii")

      resp = self.http_file("upload_snapshot", image_bytes)
      if resp and resp.get("ok"):
        snapshot_url = resp.get("url")

    payload = self.event_payload(
      detections,
      faces,
      snapshot_path=snapshot_path,
      snapshot_b64=snapshot_b64,
      uploaded_url=snapshot_url
    )

    if self.http_enabled:
      self.http_json("ingest", {
        "kind": "event",
        "device_id": self.device_id,
        "payload": payload
      })
    if self.mqtt_enabled:
      self.publish_mqtt(self.topic_events, payload, qos=1, retain=False)

  def emit_live_frame(self, frame):
    image_bytes = self.encode_jpeg(frame)
    if image_bytes:
      self.http_file("upload_live_frame", image_bytes)

  def handle_command(self, cmd):
    name = (cmd.get("command_name") or "").strip().lower()
    payload = cmd.get("command_payload") or {}
    result = {"ok": True, "command": name, "payload": payload}
    status = "ack"

    try:
      if name == "capture_now":
        frame = self.last_frame if self.last_frame is not None else self.vision.read()
        if frame is not None:
          det, faces = self.vision.detect(frame, self.conf_thr) if self.vision_enabled else ([], 0)
          self.emit_event(frame, det, faces)
          self.emit_live_frame(frame)
          result["captured"] = True
        else:
          result.update({"ok": False, "captured": False, "reason": "no frame available"})
          status = "failed"

      elif name == "refresh_status":
        self.emit_telemetry()
        result["refreshed"] = True

      elif name == "vision_off":
        self.vision_enabled = False
        result["vision_enabled"] = False

      elif name == "vision_on":
        self.vision_enabled = True
        result["vision_enabled"] = True

      else:
        result.update({"ok": False, "reason": f"unknown command: {name}"})
        status = "failed"

    except Exception as e:
      result = {"ok": False, "reason": str(e), "command": name}
      status = "failed"

    self.http_json("command_ack", {
      "device_id": self.device_id,
      "command_id": cmd.get("id"),
      "status": status,
      "result_payload": result
    })

  def poll_commands(self):
    res = self.http_json(
      "commands_poll",
      method="GET",
      params={"device_id": self.device_id}
    )
    for cmd in (res or {}).get("commands", []):
      self.handle_command(cmd)

  def run(self):
    self.setup_logging()

    if self.vision_mode in ("face", "yolo") and cv2 is not None:
      if self.vision_mode == "yolo" and (YOLO is None or not self.yolo_model):
        self.log.warning("YOLO unavailable, falling back to face mode")
        self.vision.mode = "face"

      if not self.vision.start():
        self.log.warning("Camera unavailable, running telemetry-only mode")
        self.vision_enabled = False
      else:
        self.log.info("Camera started successfully")
    else:
      self.vision_enabled = False
      self.log.warning("Vision disabled because OpenCV is unavailable or mode is unsupported")

    if self.mqtt_enabled:
      self.client = self.build_mqtt()
      try:
        self.client.connect(self.broker, self.port, self.keepalive)
        self.client.loop_start()
      except Exception as e:
        self.log.warning(f"MQTT connect failed: {e}")

    signal.signal(signal.SIGINT, lambda *_: setattr(self, "stop_flag", True))
    signal.signal(signal.SIGTERM, lambda *_: setattr(self, "stop_flag", True))

    self.emit_online(True)

    while not self.stop_flag:
      now = time.time()

      if now - self.last_heartbeat >= self.heartbeat_sec:
        self.last_heartbeat = now
        self.emit_telemetry()

      if now - self.last_command_poll >= self.command_poll_sec:
        self.last_command_poll = now
        if self.http_enabled:
          self.poll_commands()

      camera_ready = self.vision_enabled and (self.vision.use_picamera2 or self.vision.cap is not None)
      if camera_ready:
        frame = self.vision.read()
        if frame is not None:
          self.last_frame = frame

          if now - self.last_live_frame >= self.live_frame_sec and self.http_enabled:
            self.last_live_frame = now
            self.emit_live_frame(frame)

          if now - self.last_capture >= self.capture_sec:
            self.last_capture = now
            detections, faces = self.vision.detect(frame, self.conf_thr)
            self.emit_event(frame, detections, faces)

      time.sleep(0.05)

    self.emit_online(False)

    try:
      self.vision.stop()
    except Exception:
      pass

    try:
      if self.client:
        self.client.disconnect()
        self.client.loop_stop()
    except Exception:
      pass


if __name__ == "__main__":
  DeviceAgent().run()