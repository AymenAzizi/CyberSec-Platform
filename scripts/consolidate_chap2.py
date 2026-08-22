#!/usr/bin/env python3
"""Consolidate chapter 2's 12 comparison tables into 5 tables + brief prose paragraphs.

Replaces the heavy tables for: Directory Enum, Subdomain, Database, CI/CD,
Orchestration, Monitoring, Graph DB with brief prose paragraphs (keeping only
the justification, dropping the comparison table).

Keeps the tables for: Port Scanners, Vulnerability Scanners, Backend Frameworks,
Microservice Frameworks, AI/LLM Solutions.
"""

import re
from pathlib import Path

src = Path('/home/z/my-project/rapport/chap_02.tex')
text = src.read_text()

# Each entry: (subsection_title, replacement_text)
replacements = [
    # 1. Directory Enumeration -> brief prose
    (
        'Comparison of Directory Enumeration Tools',
        '''For directory and file discovery, four candidates were considered: \\textbf{Gobuster}, \\textbf{Feroxbuster}, \\textbf{Dirb}, and \\textbf{ffuf}. \\textbf{Gobuster} was selected for its simplicity, its Go-based compiled performance, and its clean CLI interface, which maps directly to the \\texttt{BaseScannerService} abstraction in the reconnaissance microservice. Feroxbuster offers built-in recursive scanning, but our platform implements its own result normalization and correlation logic, making built-in recursion redundant. Dirb was excluded due to its slow, single-threaded nature. Ffuf, though capable, introduced additional complexity without providing significant advantages for our specific use case.'''
    ),
    # 2. Subdomain Discovery -> brief prose
    (
        'Comparison of Subdomain Discovery Tools',
        '''For passive subdomain enumeration, \\textbf{Subfinder} was selected over \\textbf{Amass}, \\textbf{Assetfinder}, and \\textbf{Findomain}. Subfinder's purely passive approach (querying more than 30 public data sources, including \\texttt{crt.sh}, SecurityTrails, and Shodan) aligns with the reconnaissance phase's requirement to minimize direct interaction with the target. Amass, while more comprehensive with its active scanning capabilities, introduces risk of detection and requires more complex configuration. Subfinder's clean JSON output integrates seamlessly with the result parser, and its single-binary deployment simplifies containerization.'''
    ),
]

# Apply each replacement: replace the subsection (title + body until next subsection)
# with a new subsection containing only the prose paragraph.
for title, new_body in replacements:
    # Match from \subsection{<title>} up to (but not including) the next \subsection{ OR \section{
    pattern = re.compile(
        r'\\subsection\{' + re.escape(title) + r'\}.*?(?=^\\(?:subsection|section)\{)',
        re.DOTALL | re.MULTILINE
    )
    replacement = '\\subsection{' + title + '}\n\n' + new_body + '\n\n'
    new_text, n = pattern.subn(lambda m: replacement, text, count=1)
    if n == 0:
        print(f"WARNING: did not find subsection '{title}'")
    else:
        print(f"OK: replaced '{title}'")
    text = new_text

# Now handle the bottom 5 (Database, CI/CD, Orchestration, Monitoring, Graph DB)
# We replace them all with a single consolidated subsection.
# Find from \subsection{Comparison of Database Solutions} up to \subsection{DevSecOps Pipeline Stages Diagram}
consolidated_block = '''\\subsection{Selection of Database, CI/CD, Orchestration, Monitoring, and Graph Engine}

The remaining infrastructure-layer technology choices are summarized briefly below. For each category, multiple candidates were evaluated against the project's requirements (local deployment, on-premises data residency, single-node footprint, Laravel compatibility), and the selected solution emerged as the natural fit.

\\par \\textbf{Database -- PostgreSQL 16} was selected over MySQL 8.0, MariaDB, and SQLite for two decisive reasons: (1) it natively supports the \\textbf{Apache AGE} graph extension, which allows the platform to model the attack surface as a directed graph alongside standard SQL, and (2) its \\textbf{JSONB} column type with GIN indexes provides superior querying of heterogeneous scan results and AI analysis output. SQLite was excluded due to its single-writer limitation; MySQL/MariaDB lack a graph extension comparable to AGE.

\\par \\textbf{CI/CD Platform -- GitHub Actions} was selected over GitLab CI, Jenkins, and CircleCI for its tight integration with the project's Git repository, its native support for container-based jobs, and its comprehensive catalog of security-focused Actions (Trivy, Syft, Cosign). The free tier for private repositories (2,000 minutes per month) comfortably covers the platform's CI workload. Jenkins, while extremely powerful, requires infrastructure overhead disproportionate for a single-team project.

\\par \\textbf{Container Orchestrator -- Docker Compose} was selected over Kubernetes, Nomad, and Docker Swarm because the deployment target is a single hardened host and Compose's declarative YAML model directly mirrors the twelve-service architecture. Kubernetes was rejected: its control-plane overhead ($\\sim$2~GB of RAM) is unjustifiable for a single-team platform, and its RBAC/NetworkPolicy abstractions would duplicate the role-based access control already implemented at the Laravel layer.

\\par \\textbf{Monitoring -- Prometheus + Grafana} were selected over Datadog, the ELK stack, and Zabbix because they are the de-facto open-source standard for cloud-native monitoring and they keep all telemetry data on the customer's premises (a non-negotiable requirement for security engagements handling confidential client data). Datadog was rejected because it ships telemetry to a vendor cloud, violating the PoA data-residency clauses typically signed with pentest clients.

\\par \\textbf{Graph Database -- Apache AGE} was selected over Neo4j, ArangoDB, TigerGraph, and Amazon Neptune because it is a PostgreSQL extension, which means the platform benefits from a single relational+graph store with one set of credentials, one backup strategy, and native Laravel/Eloquent compatibility. AGE implements openCypher (the same query language as Neo4j), so graph queries are portable. Neo4j was rejected because it would require a second database server alongside PostgreSQL, doubling the operational surface.

'''

# Match from \subsection{Comparison of Database Solutions} to \subsection{DevSecOps Pipeline Stages Diagram}
pattern = re.compile(
    r'\\subsection\{Comparison of Database Solutions\}.*?(?=^\\subsection\{DevSecOps Pipeline Stages Diagram\})',
    re.DOTALL | re.MULTILINE
)
new_text, n = pattern.subn(lambda m: consolidated_block, text, count=1)
if n == 0:
    print("WARNING: did not find Database -> DevSecOps block")
else:
    print("OK: replaced 5 bottom comparison subsections with consolidated block")
text = new_text

src.write_text(text)
print(f"\nFinal file size: {len(text)} chars, {text.count(chr(10))} lines")
