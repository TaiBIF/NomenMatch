import json
import os
import subprocess
import sys
import threading
from collections import deque
from datetime import datetime

from flask import Flask, request, jsonify

app = Flask(__name__)

# 從環境變數讀取 Token，不要寫死在程式碼裡
API_KEY = os.getenv("UPDATER_API_KEY")

# 加上個小保險，如果環境變數沒抓到就報錯，避免沒有密碼保護
if not API_KEY:
    raise ValueError("未設定 UPDATER_API_KEY 環境變數！請檢查 .env 檔案。")

# 最近一次執行的結果（2026-11）：給 TaiCOL 更新服務查詢，背景更新失敗時才通知得到管理員
#   {"run_id", "state": running|success|failed, "started_at", "finished_at", "error", "watermark"}
STATUS_FILE = os.getenv("NOMENMATCH_STATUS_FILE", "/code/v2/source-data/taicol_update_status.json")
WATERMARK_FILE = os.getenv("NOMENMATCH_WATERMARK_FILE", "/code/v2/source-data/taicol_watermark.txt")
CODE_DIR = os.getenv("NOMENMATCH_CODE_DIR", "/code")
ERROR_TAIL_LINES = 30

lock = threading.Lock()


def _now():
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def _read_status():
    try:
        with open(STATUS_FILE, encoding="utf-8") as f:
            return json.load(f)
    except (FileNotFoundError, ValueError):
        return {"run_id": 0, "state": None}


def _write_status(status):
    tmp = STATUS_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(status, f, ensure_ascii=False, indent=2)
    os.replace(tmp, STATUS_FILE)


def _read_watermark():
    try:
        with open(WATERMARK_FILE, encoding="utf-8") as f:
            return f.read().strip() or None
    except FileNotFoundError:
        return None


# 容器重啟時若還記著 running，那次已經中斷了
_status = _read_status()
if _status.get("state") == "running":
    _status.update(state="failed", finished_at=_now(), error="NomenMatch updater 重啟，執行中斷")
    _write_status(_status)
is_running = False


def run_update_script(status):
    global is_running
    tail = deque(maxlen=ERROR_TAIL_LINES)
    try:
        # 輸出照樣印到容器 log（docker logs），同時留最後幾行當錯誤訊息
        proc = subprocess.Popen(
            [sys.executable, "-u", "updater/update_nomenmatch_data.py"],
            cwd=CODE_DIR, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
        )
        for line in proc.stdout:
            print(line, end="", flush=True)
            tail.append(line)
        code = proc.wait()
        if code == 0:
            status.update(state="success", error=None)
        else:
            status.update(state="failed", error=f"exit code {code}\n" + "".join(tail))
    except Exception as e:
        status.update(state="failed", error=f"{type(e).__name__}: {e}")
        print(f"Update script failed: {e}", flush=True)
    finally:
        status.update(finished_at=_now(), watermark=_read_watermark())
        with lock:
            _write_status(status)
            is_running = False


def _authorized():
    return request.headers.get("x-api-key") == API_KEY


@app.route("/api/trigger-update", methods=["POST"])
def trigger_update():
    global is_running

    if not _authorized():
        return jsonify({"error": "Unauthorized"}), 401

    with lock:
        if is_running:
            current = _read_status()
            return jsonify({"status": "busy", "message": "Task is already running.",
                            "run_id": current.get("run_id")}), 409
        is_running = True
        status = {"run_id": int(_read_status().get("run_id") or 0) + 1, "state": "running",
                  "started_at": _now(), "finished_at": None, "error": None,
                  "watermark": _read_watermark()}
        _write_status(status)

    thread = threading.Thread(target=run_update_script, args=(status,))
    thread.start()

    return jsonify({"status": "accepted", "message": "Update task started in background.",
                    "run_id": status["run_id"]}), 202


@app.route("/api/trigger-update/status", methods=["GET"])
def update_status():
    """最近一次執行的結果；更新服務每天觸發前查一次，名錄更新頁的「重新觸發 NomenMatch」會等到它結束"""
    if not _authorized():
        return jsonify({"error": "Unauthorized"}), 401
    return jsonify(_read_status()), 200


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
