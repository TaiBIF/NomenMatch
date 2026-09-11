FROM python:3.12-slim

# 安裝 Docker CLI 以及 curl
RUN apt-get update && \
    apt-get install -y curl docker.io && \
    rm -rf /var/lib/apt/lists/*

# 安裝 Python 相關依賴
RUN pip install --no-cache-dir flask pymysql pandas numpy tqdm python-dotenv

WORKDIR /code

CMD ["python", "updater/app.py"]