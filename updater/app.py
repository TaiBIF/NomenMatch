import os
import subprocess
import threading
from flask import Flask, request, jsonify

app = Flask(__name__)

# 從環境變數讀取 Token，不要寫死在程式碼裡
API_KEY = os.getenv("UPDATER_API_KEY")

# 加上個小保險，如果環境變數沒抓到就報錯，避免沒有密碼保護
if not API_KEY:
    raise ValueError("未設定 UPDATER_API_KEY 環境變數！請檢查 .env 檔案。")

is_running = False
lock = threading.Lock()

def run_update_script():
    global is_running
    try:
        subprocess.run(
            ["python", "updater/update_nomenmatch_data.py"],
            cwd="/code",
            check=True
        )
    except Exception as e:
        print(f"Update script failed: {e}")
    finally:
        with lock:
            is_running = False

@app.route("/api/trigger-update", methods=["POST"])
def trigger_update():
    global is_running
    
    x_api_key = request.headers.get("x-api-key")
    if x_api_key != API_KEY:
        return jsonify({"error": "Unauthorized"}), 401

    with lock:
        if is_running:
            return jsonify({"status": "busy", "message": "Task is already running."}), 409
        is_running = True

    thread = threading.Thread(target=run_update_script)
    thread.start()
    
    return jsonify({"status": "accepted", "message": "Update task started in background."}), 202

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)