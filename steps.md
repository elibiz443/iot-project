```
sudo apt update
sudo apt install -y python3-picamera2
sudo apt install -y python3-libcamera
sudo apt install -y python3-picamera2 python3-libcamera
```

```
import requests

TOKEN = "fb78ad63a5d1587dfc1c55507eccb29300a840c9d040afa3e99e2ec32cf3b4b7"
BASE = "https://www.iot.ellyambet.com"

headers = {
  "X-Device-Token": TOKEN,
  "Content-Type": "application/json",
}

r = requests.post(
  f"{BASE}/admin/controllers/device_api.php?api=ingest",
  headers=headers,
  json={
    "kind": "online",
    "device_id": "test-device",
    "online": True,
    "ip": "127.0.0.1"
  },
  timeout=10
)

print("status:", r.status_code)
print("body:", r.text)
```

```
import requests

TOKEN = "fb78ad63a5d1587dfc1c55507eccb29300a840c9d040afa3e99e2ec32cf3b4b7"
BASE = "https://www.iot.ellyambet.com"

with open("test.jpg", "rb") as f:
  r = requests.post(
    f"{BASE}/admin/controllers/device_api.php?api=upload_live_frame",
    headers={"X-Device-Token": TOKEN},
    data={"device_id": "test-device"},
    files={"image": ("test.jpg", f, "image/jpeg")},
    timeout=10
  )

print("status:", r.status_code)
print("body:", r.text)
```

```
curl -i --http1.1 "https://www.iot.ellyambet.com/admin/controllers/device_api.php?api=commands_poll&device_id=test-device" \
  -H "X-Device-Token: fb78ad63a5d1587dfc1c55507eccb29300a840c9d040afa3e99e2ec32cf3b4b7"
```

```
curl -i --http1.1 -A "python-requests/2.31.0" \
  -X POST "https://www.iot.ellyambet.com/admin/controllers/device_api.php?api=ingest" \
  -H "X-Device-Token: fb78ad63a5d1587dfc1c55507eccb29300a840c9d040afa3e99e2ec32cf3b4b7" \
  -H "Content-Type: application/json" \
  -d '{"kind":"online","device_id":"test-device","online":true,"ip":"127.0.0.1"}'
```

```
import requests

TOKEN = "fb78ad63a5d1587dfc1c55507eccb29300a840c9d040afa3e99e2ec32cf3b4b7"
BASE = "https://www.iot.ellyambet.com"

session = requests.Session()
session.headers.update({
  "X-Device-Token": TOKEN.strip(),
  "User-Agent": "curl/8.5.0",
  "Accept": "*/*",
})

r = session.post(
  f"{BASE}/admin/controllers/device_api.php?api=ingest",
  headers={"Content-Type": "application/json"},
  json={
    "kind": "online",
    "device_id": "test-device",
    "online": True,
    "ip": "127.0.0.1"
  },
  timeout=10
)

print(r.status_code)
print(r.text)
```
