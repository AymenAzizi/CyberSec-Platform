# syntax=docker/dockerfile:1.6
# Security testing microservice.
# Security control: communicates with Docker via a socket-proxy URL (DOCKER_HOST)
# instead of mounting /var/run/docker.sock directly.
FROM python:3.11-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    DOCKER_HOST=tcp://docker-socket-proxy:2375

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY . /app

RUN useradd --create-home --shell /usr/sbin/nologin --uid 10002 security \
    && chown -R security:security /app
USER security

EXPOSE 5001

CMD ["gunicorn", "--workers", "2", "--timeout", "300", "--bind", "0.0.0.0:5001", "--access-logfile", "-", "--error-logfile", "-", "app:app"]
