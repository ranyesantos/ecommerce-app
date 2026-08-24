import base64
import json
import os
import sys
import urllib.error
import urllib.request

API_URL = "http://rabbitmq:15672/api"
ADMIN_USER = os.environ["RABBITMQ_ADMIN_USER"]
ADMIN_PASSWORD = os.environ["RABBITMQ_ADMIN_PASSWORD"]
CATALOG_USER = os.environ["RABBITMQ_CATALOG_USER"]
CATALOG_PASSWORD = os.environ["RABBITMQ_CATALOG_PASSWORD"]


def request(method: str, path: str, payload: object) -> None:
    credentials = base64.b64encode(
        f"{ADMIN_USER}:{ADMIN_PASSWORD}".encode("utf-8")
    ).decode("ascii")
    body = json.dumps(payload).encode("utf-8")
    http_request = urllib.request.Request(
        f"{API_URL}{path}",
        data=body,
        method=method,
        headers={
            "Authorization": f"Basic {credentials}",
            "Content-Type": "application/json",
        },
    )
    with urllib.request.urlopen(http_request, timeout=10) as response:
        if response.status < 200 or response.status >= 300:
            raise RuntimeError(f"{method} {path} returned {response.status}")


try:
    request("PUT", f"/users/{CATALOG_USER}", {
        "password": CATALOG_PASSWORD,
        "tags": [],
    })
    with open("/config/definitions.json", encoding="utf-8") as definitions_file:
        request("POST", "/definitions", json.load(definitions_file))
    request("PUT", f"/permissions/ecommerce/{CATALOG_USER}", {
        "configure": "^$",
        "write": "^ecommerce\\.events$",
        "read": "^$",
    })
except (KeyError, OSError, RuntimeError, urllib.error.URLError) as error:
    print(f"RabbitMQ bootstrap failed: {error}", file=sys.stderr)
    raise SystemExit(1) from error
