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
  v = env_str(key, "1" if default else "0").strip().lower()
  return v in ("1", "true", "yes", "y", "on")


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
  paths = [
    "/sys/class/thermal/thermal_zone0/temp",
    "/sys/devices/virtual/thermal/thermal_zone0/temp",
  ]
  for p in paths:
    try:
      raw = Path(p).read_text().strip()
      val = float(raw)
      if val > 1000:
        val = val / 1000.0
      return round(val, 1)
    except Exception:
      continue
  return None


def disk_usage(path="/"):
  try:
    st = os.statvfs(path)
    total = st.f_frsize * st.f_blocks
    free = st.f_frsize * st.f_bfree
    used = total - free
    if total <= 0:
      return None
    return {
      "total_mb": int(total / (1024 * 1024)),
      "used_mb": int(used / (1024 * 1024)),
      "free_mb": int(free / (1024 * 1024)),
      "used_pct": round((used / total) * 100.0, 1),
    }
  except Exception:
    return None


def sha1(s):
  return hashlib.sha1(s.encode("utf-8")).hexdigest()


class Spool:
  def __init__(self, path, max_bytes):
    self.path = Path(path)
    self.path.parent.mkdir(parents=True, exist_ok=True)
    self.max_bytes = max_bytes

  def append(self, record):
    try:
      line = json.dumps(record, separators=(",", ":")) + "\n"
      self.path.open("a", encoding="utf-8").write(line)
      self._trim_if_needed()
      return True
    except Exception:
      return False

  def flush(self, publish_fn, max_records=500):
    if not self.path.exists():
      return 0
    try:
      lines = self.path.read_text(encoding="utf-8").splitlines()
    except Exception:
      return 0
    if not lines:
      try:
        self.path.unlink(missing_ok=True)
      except Exception:
        pass
      return 0
    kept = []
    sent = 0
    for i, line in enumerate(lines):
      if i >= max_records:
        kept.extend(lines[i:])
        break
      try:
        rec = json.loads(line)
      except Exception:
        continue
      ok = publish_fn(rec["topic"], rec["payload"], rec.get("qos", 1), rec.get("retain", False))
      if ok:
        sent += 1
      else:
        kept.append(line)
    try:
      if kept:
        self.path.write_text("\n".join(kept) + "\n", encoding="utf-8")
      else:
        self.path.unlink(missing_ok=True)
    except Exception:
      pass
    return sent

  def _trim_if_needed(self):
    try:
      if not self.path.exists():
        return
      size = self.path.stat().st_size
      if size <= self.max_bytes:
        return
      lines = self.path.read_text(encoding="utf-8").splitlines()
      lines = lines[-5000:]
      self.path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    except Exception:
      pass


class Vision:
  def __init__(self, mode, camera_index, width, height, yolo_model_path, face_min_size):
    self.mode = mode
    self.camera_index = camera_index
    self.width = width
    self.height = height
    self.yolo_model_path = yolo_model_path
    self.face_min_size = face_min_size
    self.cap = None
    self.model = None
    self.face = None

  def start(self):
    if cv2 is None:
      return False
    self.cap = cv2.VideoCapture(self.camera_index)
    if not self.cap.isOpened():
      return False
    self.cap.set(cv2.CAP_PROP_FRAME_WIDTH, self.width)
    self.cap.set(cv2.CAP_PROP_FRAME_HEIGHT, self.height)
    if self.mode == "yolo" and YOLO is not None and self.yolo_model_path:
      try:
        self.model = YOLO(self.yolo_model_path)
      except Exception:
        self.model = None
    if self.mode in ("face", "yolo") and cv2 is not None:
      try:
        cascade_path = cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
        self.face = cv2.CascadeClassifier(cascade_path)
      except Exception:
        self.face = None
    return True

  def stop(self):
    try:
      if self.cap is not None:
        self.cap.release()
    except Exception:
      pass
    self.cap = None

  def read(self):
    if self.cap is None:
      return None
    ok, frame = self.cap.read()
    if not ok:
      return None
    return frame

  def detect(self, frame, conf_thr):
    detections = []
    faces = 0

    if self.face is not None:
      try:
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        fs = self.face.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(self.face_min_size, self.face_min_size))
        faces = 0 if fs is None else len(fs)
      except Exception:
        faces = 0

    if self.mode == "yolo" and self.model is not None:
      try:
        res = self.model(frame, verbose=False)[0]
        if res is not None and getattr(res, "boxes", None) is not None:
          for b in res.boxes:
            c = float(b.conf[0])
            if c < conf_thr:
              continue
            cls = int(b.cls[0])
            label = str(self.model.names.get(cls, cls))
            xyxy = b.xyxy[0].tolist()
            detections.append({
              "label": label,
              "conf": round(c, 3),
              "box": [int(xyxy[0]), int(xyxy[1]), int(xyxy[2]), int(xyxy[3])],
            })
      except Exception:
        pass

    return detections, faces


class IotPublisher:
  def __init__(self):
    self.device_id = env_str("DEVICE_ID", hostname())
    self.broker = env_str("MQTT_BROKER", "localhost")
    self.port = env_int("MQTT_PORT", 1883)
    self.username = env_str("MQTT_USERNAME", "")
    self.password = env_str("MQTT_PASSWORD", "")
    self.keepalive = env_int("MQTT_KEEPALIVE", 60)

    self.tls = env_bool("MQTT_TLS", False)
    self.tls_ca = env_str("MQTT_TLS_CA", "")
    self.tls_insecure = env_bool("MQTT_TLS_INSECURE", False)

    self.topic_base = env_str("MQTT_TOPIC_BASE", f"home/iot/{self.device_id}")
    self.topic_events = f"{self.topic_base}/events"
    self.topic_telemetry = f"{self.topic_base}/telemetry"
    self.topic_online = f"{self.topic_base}/status/online"

    self.heartbeat_sec = env_int("HEARTBEAT_SEC", 30)
    self.capture_sec = env_int("CAPTURE_SEC", 2)
    self.event_cooldown_sec = env_int("EVENT_COOLDOWN_SEC", 8)
    self.conf_thr = env_float("CONFIDENCE_THRESHOLD", 0.45)

    self.vision_mode = env_str("VISION_MODE", "yolo").strip().lower()
    self.camera_index = env_int("CAMERA_INDEX", 0)
    self.cam_w = env_int("CAMERA_WIDTH", 640)
    self.cam_h = env_int("CAMERA_HEIGHT", 480)
    self.yolo_model = env_str("YOLO_MODEL", "")
    self.face_min_size = env_int("FACE_MIN_SIZE", 60)

    self.snapshot_dir = Path(env_str("SNAPSHOT_DIR", "/var/tmp/iot_snapshots"))
    self.snapshot_dir.mkdir(parents=True, exist_ok=True)
    self.snapshot_jpeg_quality = env_int("SNAPSHOT_JPEG_QUALITY", 85)
    self.send_snapshot_base64 = env_bool("SEND_SNAPSHOT_BASE64", False)
    self.snapshot_base64_max_kb = env_int("SNAPSHOT_BASE64_MAX_KB", 200)

    self.http_upload_url = env_str("HTTP_UPLOAD_URL", "")
    self.http_upload_token = env_str("HTTP_UPLOAD_TOKEN", "")
    self.http_timeout_sec = env_int("HTTP_TIMEOUT_SEC", 10)

    self.spool = Spool(env_str("SPOOL_FILE", "/var/tmp/iot_spool/spool.jsonl"), env_int("SPOOL_MAX_BYTES", 5_000_000))

    self.client = None
    self.connected = False
    self.stop_flag = False

    self.vision = Vision(self.vision_mode, self.camera_index, self.cam_w, self.cam_h, self.yolo_model, self.face_min_size)

    self.last_heartbeat = 0.0
    self.last_capture = 0.0
    self.last_event = 0.0
    self.last_sig = ""
    self.start_ts = time.time()

  def setup_logging(self):
    lvl = env_str("LOG_LEVEL", "INFO").upper()
    logging.basicConfig(level=getattr(logging, lvl, logging.INFO), format="%(asctime)s %(levelname)s %(message)s")
    self.log = logging.getLogger("iot")

  def build_client(self):
    try:
      c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=self.device_id, protocol=mqtt.MQTTv311)
    except Exception:
      c = mqtt.Client(client_id=self.device_id, protocol=mqtt.MQTTv311)

    if self.username:
      c.username_pw_set(self.username, self.password if self.password else None)

    if self.tls:
      try:
        if self.tls_ca:
          c.tls_set(ca_certs=self.tls_ca)
        else:
          c.tls_set()
        c.tls_insecure_set(self.tls_insecure)
      except Exception as e:
        self.log.error(f"TLS setup failed: {e}")

    c.will_set(self.topic_online, payload="0", qos=1, retain=True)
    c.on_connect = self.on_connect
    c.on_disconnect = self.on_disconnect
    c.reconnect_delay_set(min_delay=1, max_delay=60)
    c.max_inflight_messages_set(20)
    c.enable_logger(self.log)
    return c

  def on_connect(self, client, userdata, flags, rc, properties=None):
    ok = False
    try:
      code = int(rc)
      ok = (code == 0)
    except Exception:
      ok = str(rc) in ("Success", "0")
    self.connected = ok
    if ok:
      self.publish(self.topic_online, "1", qos=1, retain=True)
      flushed = self.spool.flush(self.publish)
      if flushed:
        self.log.info(f"Flushed {flushed} spooled messages")
      self.log.info("MQTT connected")
    else:
      self.log.error(f"MQTT connect failed: {rc}")

  def on_disconnect(self, client, userdata, rc, properties=None, reasonCode=None):
    self.connected = False
    self.log.warning(f"MQTT disconnected: {rc}")

  def publish(self, topic, payload, qos=1, retain=False):
    if self.client is None:
      return False
    if isinstance(payload, (dict, list)):
      payload = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
    try:
      info = self.client.publish(topic, payload=payload, qos=qos, retain=retain)
      info.wait_for_publish(timeout=3)
      return info.rc == mqtt.MQTT_ERR_SUCCESS
    except Exception:
      return False

  def publish_or_spool(self, topic, payload, qos=1, retain=False):
    ok = self.publish(topic, payload, qos=qos, retain=retain) if self.connected else False
    if ok:
      return True
    rec = {"topic": topic, "payload": payload if isinstance(payload, str) else json.dumps(payload, separators=(",", ":"), ensure_ascii=False), "qos": qos, "retain": retain, "ts": now_iso()}
    self.spool.append(rec)
    return False

  def save_snapshot(self, frame):
    if cv2 is None or frame is None:
      return None, None
    ts = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    fname = f"{self.device_id}_{ts}.jpg"
    path = self.snapshot_dir / fname
    try:
      ok, buf = cv2.imencode(".jpg", frame, [int(cv2.IMWRITE_JPEG_QUALITY), int(self.snapshot_jpeg_quality)])
      if not ok:
        return None, None
      path.write_bytes(buf.tobytes())
      b64 = None
      if self.send_snapshot_base64:
        kb = len(buf) / 1024.0
        if kb <= self.snapshot_base64_max_kb:
          b64 = base64.b64encode(buf.tobytes()).decode("ascii")
      return str(path), b64
    except Exception:
      return None, None

  def http_upload(self, meta, image_path):
    if not self.http_upload_url or requests is None:
      return None
    try:
      headers = {}
      if self.http_upload_token:
        headers["Authorization"] = f"Bearer {self.http_upload_token}"
      files = {"image": open(image_path, "rb")} if image_path else None
      data = {"meta": json.dumps(meta, separators=(",", ":"), ensure_ascii=False)}
      r = requests.post(self.http_upload_url, headers=headers, data=data, files=files, timeout=self.http_timeout_sec)
      try:
        if files and files.get("image"):
          files["image"].close()
      except Exception:
        pass
      if 200 <= r.status_code < 300:
        try:
          j = r.json()
          return j.get("url") or j.get("image_url") or j.get("id")
        except Exception:
          return "ok"
      return None
    except Exception:
      return None

  def telemetry_payload(self):
    up = int(time.time() - self.start_ts)
    return {
      "ts": now_iso(),
      "device_id": self.device_id,
      "ip": get_ip(),
      "uptime_s": up,
      "cpu_temp_c": read_cpu_temp_c(),
      "disk": disk_usage("/"),
    }

  def event_payload(self, detections, faces, snapshot_path=None, snapshot_b64=None, uploaded_url=None):
    labels = [d["label"] for d in detections]
    return {
      "ts": now_iso(),
      "device_id": self.device_id,
      "faces": int(faces),
      "labels": labels,
      "detections": detections,
      "snapshot_path": snapshot_path,
      "snapshot_b64": snapshot_b64,
      "snapshot_url": uploaded_url,
    }

  def signature(self, detections, faces):
    counts = {}
    for d in detections:
      counts[d["label"]] = counts.get(d["label"], 0) + 1
    parts = [f"faces:{int(faces)}"]
    for k in sorted(counts.keys()):
      parts.append(f"{k}:{counts[k]}")
    return sha1("|".join(parts))

  def should_emit_event(self, sig):
    t = time.time()
    if sig != self.last_sig and (t - self.last_event) >= 0.0:
      return True
    if sig == self.last_sig and (t - self.last_event) >= self.event_cooldown_sec:
      return True
    return False

  def handle_frame(self, frame):
    detections, faces = self.vision.detect(frame, self.conf_thr)
    sig = self.signature(detections, faces)
    if not detections and faces == 0:
      self.last_sig = sig
      return

    if not self.should_emit_event(sig):
      return

    snapshot_path, snapshot_b64 = self.save_snapshot(frame)
    uploaded_url = None
    meta = self.event_payload(detections, faces, snapshot_path=None, snapshot_b64=None, uploaded_url=None)
    if snapshot_path and self.http_upload_url:
      uploaded_url = self.http_upload(meta, snapshot_path)

    payload = self.event_payload(detections, faces, snapshot_path=snapshot_path, snapshot_b64=snapshot_b64, uploaded_url=uploaded_url)
    self.publish_or_spool(self.topic_events, payload, qos=1, retain=False)

    self.last_sig = sig
    self.last_event = time.time()

  def run(self):
    self.setup_logging()

    if self.vision_mode in ("face", "yolo"):
      if cv2 is None:
        self.log.warning("OpenCV not available, running telemetry-only mode")
        self.vision_mode = "none"
      elif self.vision_mode == "yolo" and (YOLO is None or not self.yolo_model):
        self.log.warning("YOLO not available or YOLO_MODEL not set, falling back to face mode")
        self.vision_mode = "face"

    if self.vision_mode in ("face", "yolo"):
      ok = self.vision.start()
      if not ok:
        self.log.error("Camera start failed, running telemetry-only mode")
        self.vision_mode = "none"

    self.client = self.build_client()

    def stop_handler(sig, frame):
      self.stop_flag = True

    signal.signal(signal.SIGINT, stop_handler)
    signal.signal(signal.SIGTERM, stop_handler)

    try:
      self.client.connect(self.broker, self.port, self.keepalive)
    except Exception as e:
      self.log.error(f"MQTT connect error: {e}")

    self.client.loop_start()

    while not self.stop_flag:
      t = time.time()

      if t - self.last_heartbeat >= self.heartbeat_sec:
        self.last_heartbeat = t
        self.publish_or_spool(self.topic_telemetry, self.telemetry_payload(), qos=1, retain=False)

      if self.vision_mode != "none" and (t - self.last_capture) >= self.capture_sec:
        self.last_capture = t
        frame = self.vision.read()
        if frame is not None:
          self.handle_frame(frame)

      time.sleep(0.05)

    try:
      self.publish(self.topic_online, "0", qos=1, retain=True)
    except Exception:
      pass

    try:
      self.vision.stop()
    except Exception:
      pass

    try:
      self.client.disconnect()
    except Exception:
      pass

    try:
      self.client.loop_stop()
    except Exception:
      pass


if __name__ == "__main__":
  IotPublisher().run()
