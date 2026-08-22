"""Knowledge graph builder using NetworkX.

Projects parsed findings into a graph:

    Asset -- has_port --> Port
    Port  -- hosts    --> Service
    Service -- exposes --> Vulnerability
    Vulnerability -- impacts --> Impact

``compute_impact_propagation`` performs a BFS from every critical
vulnerability to compute the blast radius over reachable assets.
"""

from __future__ import annotations

import logging
from collections import deque
from typing import Any, Dict, List, Optional, Tuple

import networkx as nx

logger = logging.getLogger(__name__)

# Severity -> numeric risk score (CDC).
SEVERITY_SCORE: Dict[str, int] = {
    "info": 1,
    "low": 25,
    "medium": 50,
    "high": 75,
    "critical": 100,
}

# Edge type vocabulary.
EDGE_HAS_PORT = "has_port"
EDGE_HOSTS = "hosts"
EDGE_EXPOSES = "exposes"
EDGE_HAS_VULNERABILITY = "has_vulnerability"
EDGE_IMPACTS = "impacts"
EDGE_PROPAGATES = "propagates"  # synthetic edge added during BFS

NODE_ASSET = "asset"
NODE_PORT = "port"
NODE_SERVICE = "service"
NODE_VULNERABILITY = "vulnerability"
NODE_IMPACT = "impact"


def _safe_id(prefix: str, *parts: Any) -> str:
    return f"{prefix}:" + ":".join(str(p) for p in parts if p is not None)


class GraphBuilder:
    """Build a NetworkX DiGraph from parsed findings."""

    def __init__(self) -> None:
        self.graph: nx.DiGraph = nx.DiGraph()

    # ------------------------------------------------------------------
    def build_from_findings(
        self,
        findings: List[Dict[str, Any]],
        target: str,
    ) -> nx.DiGraph:
        """Populate ``self.graph`` from findings and return it."""
        self.graph = nx.DiGraph()
        asset_id = _safe_id(NODE_ASSET, target)
        self.graph.add_node(
            asset_id,
            id=asset_id,
            label=str(target),
            type=NODE_ASSET,
            risk_score=0,
        )

        for finding in findings:
            self._add_finding(asset_id, finding)

        # Roll up risk scores: asset's risk = max risk of connected vulns.
        asset_risk = self._compute_asset_risk(asset_id)
        self.graph.nodes[asset_id]["risk_score"] = asset_risk
        return self.graph

    # ------------------------------------------------------------------
    def _add_finding(self, asset_id: str, finding: Dict[str, Any]) -> None:
        tool = finding.get("source_tool", "generic")
        endpoint = finding.get("endpoint")
        severity = (finding.get("severity") or "info").lower()
        risk = SEVERITY_SCORE.get(severity, 1)

        # Extract port number if present.
        port_num = self._extract_port(endpoint, finding, tool)
        port_id: Optional[str] = None
        service_id: Optional[str] = None
        if port_num is not None:
            port_id = _safe_id(NODE_PORT, asset_id, port_num)
            self.graph.add_node(
                port_id,
                id=port_id,
                label=f"port {port_num}",
                type=NODE_PORT,
                port=port_num,
                risk_score=risk,
            )
            self.graph.add_edge(asset_id, port_id, type=EDGE_HAS_PORT)

            # Service inference: use finding title/description if available.
            service_name = self._infer_service_name(finding, tool)
            if service_name:
                service_id = _safe_id(NODE_SERVICE, asset_id, port_num, service_name)
                self.graph.add_node(
                    service_id,
                    id=service_id,
                    label=service_name,
                    type=NODE_SERVICE,
                    risk_score=risk,
                )
                self.graph.add_edge(port_id, service_id, type=EDGE_HOSTS)

        # Vulnerability node.
        vuln_id = _safe_id(
            NODE_VULNERABILITY,
            asset_id,
            finding.get("cve_id") or finding.get("title") or id(finding),
        )
        self.graph.add_node(
            vuln_id,
            id=vuln_id,
            label=str(finding.get("title") or finding.get("cve_id") or "vulnerability"),
            type=NODE_VULNERABILITY,
            severity=severity,
            risk_score=risk,
            cve_id=finding.get("cve_id"),
            source_tool=tool,
            description=finding.get("description"),
        )
        if service_id:
            self.graph.add_edge(service_id, vuln_id, type=EDGE_EXPOSES)
        elif port_id:
            self.graph.add_edge(port_id, vuln_id, type=EDGE_EXPOSES)
        else:
            self.graph.add_edge(asset_id, vuln_id, type=EDGE_HAS_VULNERABILITY)

        # Impact node — only for medium+ severities.
        if SEVERITY_SCORE.get(severity, 1) >= SEVERITY_SCORE["medium"]:
            impact_id = _safe_id(NODE_IMPACT, vuln_id, severity)
            impact_label = self._impact_label(severity, finding)
            self.graph.add_node(
                impact_id,
                id=impact_id,
                label=impact_label,
                type=NODE_IMPACT,
                risk_score=risk,
            )
            self.graph.add_edge(vuln_id, impact_id, type=EDGE_IMPACTS, weight=risk)

    # ------------------------------------------------------------------
    @staticmethod
    def _extract_port(
        endpoint: Optional[str],
        finding: Dict[str, Any],
        tool: str,
    ) -> Optional[int]:
        if tool == "nmap":
            evidence = finding.get("evidence") or finding.get("title") or ""
            # title format: "Open port 80/tcp http (open)"
            for token in evidence.split():
                if token.isdigit():
                    try:
                        return int(token)
                    except ValueError:
                        continue
        if endpoint:
            # endpoint may be "host:port" or "host:port/path" or just "port".
            try:
                # Strip scheme.
                ep = endpoint
                if "://" in ep:
                    ep = ep.split("://", 1)[1]
                # host:port form.
                if ":" in ep:
                    host_part, _, port_part = ep.partition(":")
                    port_part = port_part.split("/", 1)[0]
                    if port_part.isdigit():
                        return int(port_part)
                if ep.isdigit():
                    return int(ep)
            except (ValueError, IndexError):
                return None
        return None

    @staticmethod
    def _infer_service_name(finding: Dict[str, Any], tool: str) -> Optional[str]:
        title = str(finding.get("title") or "")
        if tool == "nmap" and "/" in title:
            # "Open port 443/tcp https (open)" -> "https"
            parts = title.split()
            for part in parts:
                if part.startswith(("tcp", "udp")):
                    continue
                if part.isdigit() or "/" in part:
                    continue
                if part in {"(open)", "(closed)", "(filtered)"}:
                    continue
                return part.lower()
        if tool == "gobuster":
            return "http"
        if tool == "wpscan":
            return "wordpress"
        if tool == "nuclei":
            return "http"
        if tool == "subfinder":
            return "dns"
        return None

    @staticmethod
    def _impact_label(severity: str, finding: Dict[str, Any]) -> str:
        cve = finding.get("cve_id")
        title = finding.get("title") or "vulnerability"
        if cve:
            return f"{severity.upper()} impact: {cve} ({title})"
        return f"{severity.upper()} impact: {title}"

    # ------------------------------------------------------------------
    def _compute_asset_risk(self, asset_id: str) -> int:
        """Roll up the asset's risk score from connected vulnerabilities."""
        max_risk = 0
        for node in self.graph.successors(asset_id):
            node_data = self.graph.nodes[node]
            if node_data.get("type") == NODE_VULNERABILITY:
                max_risk = max(max_risk, int(node_data.get("risk_score", 0)))
            # Traverse one more hop (asset -> port -> vuln).
            for n2 in self.graph.successors(node):
                d2 = self.graph.nodes[n2]
                if d2.get("type") == NODE_VULNERABILITY:
                    max_risk = max(max_risk, int(d2.get("risk_score", 0)))
        return max_risk

    # ------------------------------------------------------------------
    def compute_impact_propagation(self, graph: Optional[nx.DiGraph] = None) -> Dict[str, Any]:
        """BFS from every critical vulnerability to compute blast radius.

        Returns a dict ``{critical_vulns: [...], blast_radius: {asset_id: distance}}``.
        """
        g = graph or self.graph
        critical_vulns = [
            n for n, d in g.nodes(data=True)
            if d.get("type") == NODE_VULNERABILITY and d.get("severity") == "critical"
        ]
        if not critical_vulns:
            # If no criticals, propagate from high severity too.
            critical_vulns = [
                n for n, d in g.nodes(data=True)
                if d.get("type") == NODE_VULNERABILITY
                and d.get("severity") in {"high", "critical"}
            ]

        blast_radius: Dict[str, int] = {}
        for source in critical_vulns:
            visited: Dict[str, int] = {source: 0}
            queue: deque[str] = deque([source])
            while queue:
                node = queue.popleft()
                depth = visited[node]
                # Walk successors (outgoing impact edges) AND predecessors
                # (back to the asset that owns the vuln).
                neighbors = list(g.successors(node)) + list(g.predecessors(node))
                for nxt in neighbors:
                    if nxt in visited:
                        continue
                    visited[nxt] = depth + 1
                    queue.append(nxt)
                    # Add a synthetic propagates edge for visualization.
                    if not g.has_edge(source, nxt):
                        g.add_edge(source, nxt, type=EDGE_PROPAGATES, weight=depth + 1)
                # Track the shortest distance to each asset.
                for n, d in visited.items():
                    if g.nodes[n].get("type") == NODE_ASSET:
                        if n not in blast_radius or d < blast_radius[n]:
                            blast_radius[n] = d

        return {
            "critical_vulns": critical_vulns,
            "blast_radius": blast_radius,
            "affected_assets": list(blast_radius.keys()),
        }

    # ------------------------------------------------------------------
    def serialize(self, graph: Optional[nx.DiGraph] = None) -> Dict[str, Any]:
        """Return a JSON-serializable dict for storage."""
        g = graph or self.graph
        nodes = []
        edges = []
        for node_id, data in g.nodes(data=True):
            n = {"id": node_id}
            n.update({k: v for k, v in data.items() if k != "id"})
            nodes.append(n)
        for u, v, data in g.edges(data=True):
            edges.append({"source": u, "target": v, **data})
        return {
            "nodes": nodes,
            "edges": edges,
            "stats": {
                "node_count": g.number_of_nodes(),
                "edge_count": g.number_of_edges(),
            },
        }

    # ------------------------------------------------------------------
    def to_cytoscape(self, graph: Optional[nx.DiGraph] = None) -> Dict[str, Any]:
        """Return the graph in Cytoscape.js format."""
        g = graph or self.graph
        nodes = []
        edges = []
        for node_id, data in g.nodes(data=True):
            nodes.append({
                "data": {
                    "id": node_id,
                    "label": data.get("label", node_id),
                    "type": data.get("type", "unknown"),
                    "risk_score": int(data.get("risk_score", 0)),
                    "severity": data.get("severity"),
                    "cve_id": data.get("cve_id"),
                    "source_tool": data.get("source_tool"),
                }
            })
        for idx, (u, v, data) in enumerate(g.edges(data=True)):
            edges.append({
                "data": {
                    "id": f"e{idx}",
                    "source": u,
                    "target": v,
                    "type": data.get("type", "related"),
                    "weight": int(data.get("weight", 1)),
                }
            })
        return {"nodes": nodes, "edges": edges}


__all__ = ["GraphBuilder", "SEVERITY_SCORE"]
