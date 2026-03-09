# IoT Vision Dashboard + Raspberry Pi Agent

This project is wired to support a practical end-to-end flow:

- Raspberry Pi captures camera frames
- Pi detects faces / objects
- Pi sends telemetry, detections, and a near-live camera preview to the web app
- Web dashboard shows device status, charts, event snapshots, and the latest camera frame
- Dashboard can queue commands to the Pi
- Pi polls and executes commands, then reports command results back to the dashboard
- MQTT remains optional for extra live updates, but the system works even if MQTT/WebSocket is not available

## Architecture

### Web app

PHP + MySQL dashboard with:

- auth
- device list
- telemetry charts
- event history
- latest event snapshot
- live camera preview using regularly refreshed JPEG uploads
- remote commands with ACK status

### Raspberry Pi agent

`py/send_data.py` supports:

- telemetry heartbeat
- face / YOLO detection
- event snapshot upload
- live frame upload
- command polling and ACK
- optional MQTT publish alongside HTTP

## Important note about the “live camera” view

This implementation uses **fast recurring JPEG frame uploads** from the Pi and the dashboard displays the latest frame.

That gives a reliable, lightweight “live preview” from almost anywhere.

It is not a raw RTSP/WebRTC stream, but for most monitoring dashboards it is the simplest and most dependable option.

## Database tables

- `iot_users`
- `iot_devices`
- `iot_telemetry`
- `iot_events`
- `iot_commands`

## 1. Server setup

### Requirements

- PHP 8.1+
- MySQL / MariaDB
- Apache or Nginx + PHP-FPM
- writable `uploads/` directory

### Environment variables

Set these in Apache / Nginx / hosting panel:

```bash
ROOT_URL=https://your-domain.com/iot_project
ROOT_PATH=/var/www/html/iot_project

DB_DSN=mysql:host=127.0.0.1;dbname=iot;charset=utf8mb4
DB_USER=iot_user
DB_PASS=your_db_password

DEVICE_SHARED_TOKEN=change-this-device-token
DEVICE_HTTP_ENABLED=1

MQTT_TOPIC_ROOT=home/iot
MQTT_WS_URL=
MQTT_WS_USER=
MQTT_WS_PASS=

DASH_TEL_LIMIT=180
DASH_EVT_LIMIT=80
DASH_AUTO_REFRESH_SEC=10
```

### Upload directory permissions

```bash
mkdir -p uploads/snapshots uploads/live
chmod -R 775 uploads
```

## 2. Raspberry Pi setup

### System packages

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip libatlas-base-dev
```

### Python environment

```bash
cd py
python3 -m venv .venv
source .venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
```

### Raspberry Pi environment variables

Create a `.env` or export variables before running:

```bash
export DEVICE_ID=pi-cam-01
export HTTP_BASE_URL=https://your-domain.com/iot_project
export DEVICE_HTTP_TOKEN=change-this-device-token
export HTTP_TIMEOUT_SEC=10

export VISION_MODE=face
export CAMERA_INDEX=0
export CAMERA_WIDTH=640
export CAMERA_HEIGHT=480
export FACE_MIN_SIZE=60

# Optional YOLO
export YOLO_MODEL=/home/pi/models/yolov8n.pt

export HEARTBEAT_SEC=30
export CAPTURE_SEC=2
export LIVE_FRAME_SEC=1
export COMMAND_POLL_SEC=3
export EVENT_COOLDOWN_SEC=8
export CONFIDENCE_THRESHOLD=0.45

# Optional MQTT
export MQTT_ENABLED=0
export MQTT_BROKER=127.0.0.1
export MQTT_PORT=1883
export MQTT_USERNAME=
export MQTT_PASSWORD=
export MQTT_TOPIC_BASE=home/iot/pi-cam-01
```

### Run the Pi agent

```bash
cd py
source .venv/bin/activate
python send_data.py
```

## 3. Dashboard usage

- register an account
- open the dashboard
- choose your device
- watch telemetry, latest event snapshot, event history, and live preview
- use the command buttons to send commands to the Pi

Supported commands now:

- `capture_now`
- `refresh_status`
- `vision_on`
- `vision_off`

You can also send custom JSON payload alongside a command from the dashboard.

## 4. Optional MQTT worker

This project still includes the MQTT worker path if you want DB ingestion from MQTT.

That path needs Composer dependencies for `php-mqtt/client`.

Example:

```bash
composer require php-mqtt/client
php worker/worker.php
```

MQTT is now optional. The HTTP device API is enough for a working setup.

## 5. Recommended production hardening

- change `DEVICE_SHARED_TOKEN`
- force HTTPS
- keep `uploads/` outside broad public listing if your server allows it
- add basic rate limiting on device endpoints
- create a dedicated DB user with least privileges
- if exposing publicly, consider adding per-device tokens instead of one shared token

## 6. systemd service example for Raspberry Pi

Create `/etc/systemd/system/iot-agent.service`:

```ini
[Unit]
Description=IoT Vision Raspberry Pi Agent
After=network-online.target
Wants=network-online.target

[Service]
User=pi
WorkingDirectory=/home/pi/iot_project/py
Environment=DEVICE_ID=pi-cam-01
Environment=HTTP_BASE_URL=https://your-domain.com/iot_project
Environment=DEVICE_HTTP_TOKEN=change-this-device-token
Environment=VISION_MODE=face
Environment=CAMERA_INDEX=0
Environment=CAMERA_WIDTH=640
Environment=CAMERA_HEIGHT=480
Environment=HEARTBEAT_SEC=30
Environment=CAPTURE_SEC=2
Environment=LIVE_FRAME_SEC=1
Environment=COMMAND_POLL_SEC=3
ExecStart=/home/pi/iot_project/py/.venv/bin/python /home/pi/iot_project/py/send_data.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable iot-agent
sudo systemctl start iot-agent
sudo systemctl status iot-agent
```

## 7. Tailwind build

```bash
npx tailwindcss -i ./assets/css/input.css -o ./assets/css/output.css --watch
```

## 8. What to expect operationally

- telemetry appears every `HEARTBEAT_SEC`
- live preview refreshes every `LIVE_FRAME_SEC`
- event records are created only when faces / detections are seen and cooldown rules allow it
- commands are typically picked up within `COMMAND_POLL_SEC`

## 9. Validation done

The updated code was syntax-checked with:

- `python3 -m py_compile py/send_data.py`
- `php -l` for all PHP files

## 10. Next upgrade path

If you want true ultra-smooth live video later, the clean upgrade is:

- WebRTC for browser live stream
- keep this dashboard and DB exactly as the control / history plane
- let the Pi publish WebRTC media separately

For now, this version is much simpler to deploy and is the better fit for a reliable first production setup.
