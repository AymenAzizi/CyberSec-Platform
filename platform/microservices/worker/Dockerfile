# syntax=docker/dockerfile:1.6
# Worker image — long-running process, not a web service.
FROM python:3.11-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    REDIS_URL=redis://redis:6379/0 \
    LARAVEL_URL=http://laravel:8000 \
    RECON_SERVICE_URL=http://recon:5000 \
    SECURITY_SERVICE_URL=http://security:5001 \
    OSINT_SERVICE_URL=http://osint:5002 \
    AI_SERVICE_URL=http://ai:5003 \
    MAX_ATTEMPTS=3 \
    IDLE_SLEEP=2

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY . /app

RUN useradd --create-home --shell /usr/sbin/nologin --uid 10006 worker \
    && chown -R worker:worker /app
USER worker

# Read-only rootfs compatible: worker only writes to /tmp (logs go to stdout).
# No port exposed — workers are not web services.
CMD ["python", "-u", "worker.py"]
