# syntax=docker/dockerfile:1.6
# OSINT microservice.
FROM python:3.11-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY . /app

RUN useradd --create-home --shell /usr/sbin/nologin --uid 10003 osint \
    && chown -R osint:osint /app
USER osint

EXPOSE 5002

CMD ["gunicorn", "--workers", "2", "--timeout", "120", "--bind", "0.0.0.0:5002", "--access-logfile", "-", "--error-logfile", "-", "app:app"]
