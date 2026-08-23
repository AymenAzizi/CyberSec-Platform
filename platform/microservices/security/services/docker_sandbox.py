"""Docker sandbox — manages vulnerable test applications.

Uses the Docker SDK configured to talk to a docker-socket-proxy URL (set via
the ``DOCKER_HOST`` env var) rather than mounting ``/var/run/docker.sock``
directly. This is a CDC security control: the service never has direct
root-equivalent access to the Docker daemon.
"""

from __future__ import annotations

import logging
import os
from typing import Any, Dict, List, Optional

logger = logging.getLogger(__name__)

# Mapping of supported test apps to their container images.
SANDBOX_IMAGES: Dict[str, str] = {
    "dvwa": "vulnerables/web-dvwa:latest",
    "sqli-labs": "acgpiano/sqli-labs:latest",
    "webgoat": "webgoat/goatandwolf:latest",
    "bwapp": "raesene/bwapp:latest",
}

DEFAULT_PORTS: Dict[str, int] = {
    "dvwa": 80,
    "sqli-labs": 80,
    "webgoat": 8080,
    "bwapp": 80,
}


class DockerSandbox:
    """Manage vulnerable test containers via the Docker SDK."""

    def __init__(self) -> None:
        self._client = None
        self._init_client()

    def _init_client(self) -> None:
        try:
            import docker  # type: ignore
        except ImportError:
            logger.warning("docker SDK not installed; sandbox disabled")
            return
        # If DOCKER_HOST is set (e.g. tcp://docker-socket-proxy:2375), use it.
        # Otherwise fall back to the local socket (dev only).
        try:
            self._client = docker.DockerClient.from_env()
            self._client.ping()
        except Exception as exc:  # noqa: BLE001
            logger.warning("docker daemon unreachable: %s", exc)
            self._client = None

    # ------------------------------------------------------------------
    @property
    def available(self) -> bool:
        return self._client is not None

    def list_supported(self) -> List[Dict[str, Any]]:
        return [
            {"name": name, "image": image, "default_port": DEFAULT_PORTS.get(name, 80)}
            for name, image in SANDBOX_IMAGES.items()
        ]

    # ------------------------------------------------------------------
    def start(self, app: str, port: Optional[int] = None, network: str = "pfe-sandbox") -> Dict[str, Any]:
        if not self.available:
            return {"error": "docker daemon unreachable; set DOCKER_HOST to a socket-proxy URL"}
        if app not in SANDBOX_IMAGES:
            return {"error": f"unknown sandbox app {app!r}", "supported": list(SANDBOX_IMAGES.keys())}

        image = SANDBOX_IMAGES[app]
        host_port = port or DEFAULT_PORTS[app]
        container_name = f"pfe-sandbox-{app}"

        # Tear down any existing container with the same name.
        try:
            existing = self._client.containers.get(container_name)
            existing.remove(force=True)
        except Exception:  # nosec B110
            pass

        try:
            # Ensure the isolated network exists.
            try:
                self._client.networks.get(network)
            except Exception:  # noqa: BLE001
                self._client.networks.create(network, driver="bridge")
            container = self._client.containers.run(
                image,
                name=container_name,
                detach=True,
                ports={f"{host_port}/tcp": None},  # Let Docker pick a host port.
                network=network,
                # Security: no privileged, no new privileges, read-only where possible.
                privileged=False,
                security_flags=["no-new-privileges"],
                environment={"TZ": "UTC"},
                labels={"pfe.sandbox": "true", "pfe.app": app},
                auto_remove=False,
            )
            container.reload()
            port_bindings = container.attrs.get("NetworkSettings", {}).get("Ports", {})
            assigned_port = None
            for bindings in port_bindings.values():
                if bindings:
                    assigned_port = int(bindings[0]["HostPort"])
                    break
            return {
                "app": app,
                "container_id": container.id,
                "container_name": container_name,
                "image": image,
                "assigned_port": assigned_port,
                "status": container.status,
                "network": network,
                "url": f"http://localhost:{assigned_port}" if assigned_port else None,
            }
        except Exception as exc:  # noqa: BLE001
            logger.error("failed to start sandbox %s: %s", app, exc)
            return {"error": str(exc)}

    # ------------------------------------------------------------------
    def stop(self, app: str) -> Dict[str, Any]:
        if not self.available:
            return {"error": "docker daemon unreachable"}
        container_name = f"pfe-sandbox-{app}"
        try:
            container = self._client.containers.get(container_name)
            container.stop(timeout=5)
            container.remove(force=True)
            return {"app": app, "status": "stopped", "container_name": container_name}
        except Exception as exc:  # noqa: BLE001
            return {"error": str(exc)}

    # ------------------------------------------------------------------
    def status(self) -> Dict[str, Any]:
        if not self.available:
            return {"available": False, "containers": []}
        try:
            containers = self._client.containers.list(all=True, filters={"label": "pfe.sandbox=true"})
            return {
                "available": True,
                "containers": [
                    {
                        "id": c.id,
                        "name": c.name,
                        "image": c.image.tags[0] if c.image.tags else c.image.id,
                        "status": c.status,
                        "labels": c.labels,
                    }
                    for c in containers
                ],
            }
        except Exception as exc:  # noqa: BLE001
            return {"available": True, "error": str(exc), "containers": []}


__all__ = ["DockerSandbox", "SANDBOX_IMAGES", "DEFAULT_PORTS"]
