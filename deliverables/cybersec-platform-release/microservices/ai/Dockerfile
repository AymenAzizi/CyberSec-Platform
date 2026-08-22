# syntax=docker/dockerfile:1.6
# AI microservice.
FROM python:3.11-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    OLLAMA_HOST=http://ollama:11434 \
    OLLAMA_MODEL=qwen2.5-coder:7b

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY . /app

RUN useradd --create-home --shell /usr/sbin/nologin --uid 10005 ai \
    && chown -R ai:ai /app
USER ai

EXPOSE 5003

CMD ["gunicorn", "--workers", "2", "--timeout", "300", "--bind", "0.0.0.0:5003", "--access-logfile", "-", "--error-logfile", "-", "app:app"]
