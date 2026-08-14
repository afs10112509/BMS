"""
BMS Desktop — jendela Windows yang memuat UI dari server.
Update server langsung terlihat setelah Muat Ulang / buka ulang app.
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import webview


DEFAULT_URL = "https://bms.adbr.my.id/app/"
DEFAULT_TITLE = "BMS — Belawa Management System"


def app_dir() -> Path:
    if getattr(sys, "frozen", False):
        return Path(sys.executable).resolve().parent
    return Path(__file__).resolve().parent


def load_config() -> dict:
    cfg = {
        "url": os.environ.get("BMS_APP_URL", DEFAULT_URL),
        "title": DEFAULT_TITLE,
        "width": 1360,
        "height": 860,
    }
    path = app_dir() / "config.json"
    if path.exists():
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                cfg.update({k: v for k, v in data.items() if v not in (None, "")})
        except Exception:
            pass
    url = str(cfg.get("url") or DEFAULT_URL).strip()
    if not url.endswith("/"):
        # pastikan path /app/ tetap valid
        if url.rstrip("/").endswith("/app"):
            url = url.rstrip("/") + "/"
    cfg["url"] = url
    cfg["title"] = str(cfg.get("title") or DEFAULT_TITLE)
    cfg["width"] = int(cfg.get("width") or 1360)
    cfg["height"] = int(cfg.get("height") or 860)
    return cfg


class Api:
    def reload(self) -> None:
        win = webview.windows[0] if webview.windows else None
        if win is not None:
            win.evaluate_js(
                "try{if(window.caches){caches.keys().then(ks=>Promise.all(ks.map(k=>caches.delete(k))))}"
                ".finally(function(){location.reload()})}catch(e){location.reload()}"
            )


def main() -> None:
    cfg = load_config()
    api = Api()
    webview.create_window(
        title=cfg["title"],
        url=cfg["url"],
        width=cfg["width"],
        height=cfg["height"],
        min_size=(980, 640),
        confirm_close=False,
        js_api=api,
    )
    # Edge WebView2 di Windows (bawaan Win10/11 modern)
    webview.start(gui="edgechromium", debug=False)


if __name__ == "__main__":
    main()
