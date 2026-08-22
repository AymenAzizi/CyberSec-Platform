# syntax=docker/dockerfile:1.6
# Reconnaissance microservice image.
# Hardened: non-root user, read-only rootfs compatible, no shell in entrypoint.
FROM python:3.11-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

# --- System dependencies: nmap + SecLists + Go tool binaries --------------
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nmap \
        curl \
        ca-certificates \
        git \
        seclists \
        wordlists \
        wpscan \
    && rm -rf /var/lib/apt/lists/*

# Go-based tools: nuclei, subfinder, gobuster.
ARG NUCLEI_VERSION=3.3.0
ARG SUBFINDER_VERSION=2.6.6
ARG GOBUSTER_VERSION=3.6.0

RUN set -eux; \
    ARCH="$(dpkg --print-architecture)"; \
    case "$ARCH" in \
        amd64) GOARCH="linux-amd64" ;; \
        arm64) GOARCH="linux-arm64" ;; \
        *) echo "unsupported arch: $ARCH"; exit 1 ;; \
    esac; \
    curl -fsSL "https://github.com/projectdiscovery/nuclei/releases/download/v${NUCLEI_VERSION}/nuclei_${NUCLEI_VERSION}_${GOARCH}.zip" -o /tmp/nuclei.zip; \
    apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*; \
    unzip -o /tmp/nuclei.zip -d /usr/local/bin nuclei; \
    chmod +x /usr/local/bin/nuclei; \
    rm /tmp/nuclei.zip; \
    curl -fsSL "https://github.com/projectdiscovery/subfinder/releases/download/v${SUBFINDER_VERSION}/subfinder_${SUBFINDER_VERSION}_${GOARCH}.zip" -o /tmp/subfinder.zip; \
    unzip -o /tmp/subfinder.zip -d /usr/local/bin subfinder; \
    chmod +x /usr/local/bin/subfinder; \
    rm /tmp/subfinder.zip; \
    curl -fsSL "https://github.com/OJ/gobuster/releases/download/v${GOBUSTER_VERSION}/gobuster_${GOBUSTER_VERSION}_${GOARCH}.tar.gz" -o /tmp/gobuster.tgz; \
    tar -xzf /tmp/gobuster.tgz -C /usr/local/bin gobuster; \
    chmod +x /usr/local/bin/gobuster; \
    rm /tmp/gobuster.tgz; \
    apt-get purge -y --auto-remove unzip

# --- Python dependencies ---------------------------------------------------
WORKDIR /app
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

# --- Application code ------------------------------------------------------
COPY . /app

# Pre-populate nuclei templates to a writable location (rootfs may be RO).
RUN mkdir -p /tmp/nuclei-templates /tmp/subfinder \
    && nuclei -update-templates -directory /tmp/nuclei-templates || true

ENV NUCLEI_TEMPLATES_DIR=/tmp/nuclei-templates \
    NUCLEI_HOME=/tmp/nuclei

# Non-root user (CDC: workers must not run as root).
RUN useradd --create-home --shell /usr/sbin/nologin --uid 10001 recon
RUN chown -R recon:recon /app /tmp/nuclei-templates /tmp/subfinder
USER recon

EXPOSE 5000

# gunicorn: 2 workers, 600s timeout for long-running scans.
CMD ["gunicorn", "--workers", "2", "--timeout", "600", "--bind", "0.0.0.0:5000", "--access-logfile", "-", "--error-logfile", "-", "app:app"]
