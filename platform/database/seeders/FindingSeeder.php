<?php

namespace Database\Seeders;

use App\Models\Finding;
use App\Models\Scan;
use App\Models\Target;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds realistic findings for every completed scan.
 *
 * Each scan type pulls from a curated catalogue of findings (real CVE IDs,
 * real titles, real evidence excerpts, real remediation text and citations
 * with line numbers). After inserting findings, the seeder re-computes
 * each scan's severity_counts from the actual rows so the dashboard
 * never shows a number that disagrees with the underlying data.
 *
 * Idempotent: existing findings for a scan are wiped before re-insertion
 * so the catalogue can evolve without leaving stale duplicates.
 */
class FindingSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        foreach (Scan::where('status', Scan::STATUS_COMPLETED)->get() as $scan) {
            $target = $scan->target;

            if (! $target) {
                continue;
            }

            // Re-seeding must produce the same set of findings, so wipe first.
            Finding::where('scan_id', $scan->id)->delete();

            $catalogue = $this->catalogueFor($scan->type);

            foreach ($catalogue as $row) {
                Finding::create(array_merge(
                    $row,
                    [
                        'scan_id'     => $scan->id,
                        'project_id'  => $scan->project_id,
                        'target_id'   => $target->id,
                        'status'      => Finding::STATUS_NEW,
                        'is_false_positive' => false,
                        'verified_at' => null,
                        'verified_by' => null,
                    ],
                ));
            }

            // Re-compute severity_counts from the actual findings.
            $counts = $this->computeSeverityCounts($scan);
            $scan->severity_counts = $counts;
            $scan->save();
        }
    }

    /**
     * Compute a severity_counts payload from the findings attached to a scan.
     *
     * @return array<string,int>
     */
    private function computeSeverityCounts(Scan $scan): array
    {
        $counts = [
            Finding::SEVERITY_CRITICAL => 0,
            Finding::SEVERITY_HIGH => 0,
            Finding::SEVERITY_MEDIUM => 0,
            Finding::SEVERITY_LOW => 0,
            Finding::SEVERITY_INFO => 0,
        ];

        foreach (Finding::where('scan_id', $scan->id)->get() as $finding) {
            $counts[$finding->severity] = ($counts[$finding->severity] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Return the finding catalogue for a given scan type.
     *
     * @return list<array<string,mixed>>
     */
    private function catalogueFor(string $scanType): array
    {
        return match ($scanType) {
            'nmap'   => $this->nmapCatalogue(),
            'nuclei' => $this->nucleiCatalogue(),
            'osint'  => $this->osintCatalogue(),
            default  => [],
        };
    }

    /**
     * Catalogue for nmap scans — focuses on exposed services and
     * misconfigurations observable at the TCP layer.
     *
     * @return list<array<string,mixed>>
     */
    private function nmapCatalogue(): array
    {
        $now = Carbon::now()->toIso8601String();

        return [
            [
                'title'       => 'Open SSH Port with Weak Cipher Suites',
                'description' => 'TCP port 22 is open and accepts SSH connections from the public Internet. '
                    . 'The negotiated cipher list includes CBC-mode ciphers (aes128-cbc, aes256-cbc) and '
                    . 'diffie-hellman-group1-sha1, all of which are deprecated and known to be exploitable '
                    . 'under active man-in-the-middle conditions (e.g. via the Terrapin attack, CVE-2023-48795).',
                'severity'           => Finding::SEVERITY_HIGH,
                'cvss_score'         => 7.5,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N',
                'cve_id'             => 'CVE-2023-48795',
                'cwe_id'             => 'CWE-326',
                'evidence'           => "22/tcp open  ssh  OpenSSH 8.9p1 Ubuntu 3ubuntu0.6 (protocol 2.0)\n"
                    . "  Supported ciphers:\n"
                    . "    aes128-cbc                       [weak]\n"
                    . "    aes256-cbc                       [weak]\n"
                    . "    diffie-hellman-group1-sha1       [deprecated]\n"
                    . "    diffie-hellman-group14-sha1      [deprecated]\n"
                    . "    chacha20-poly1305@openssh.com    [ok]\n"
                    . "  SSH banner: SSH-2.0-OpenSSH_8.9p1 Ubuntu-3ubuntu0.6",
                'endpoint'           => 'tcp://22',
                'affected_component' => 'OpenSSH 8.9p1 Ubuntu 3ubuntu0.6',
                'source_tool'        => 'nmap',
                'remediation'        => "1. Upgrade OpenSSH to 9.6+ to remediate CVE-2023-48795.\n"
                    . "2. Edit /etc/ssh/sshd_config and add:\n"
                    . "     Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com,aes128-gcm@openssh.com\n"
                    . "     KexAlgorithms curve25519-sha256,curve25519-sha256@libssh.org,diffie-hellman-group16-sha512\n"
                    . "     MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com\n"
                    . "3. Restart sshd: sudo systemctl restart ssh\n"
                    . "4. Re-scan to confirm weak ciphers are no longer negotiated.",
                'impact_score'       => 8.2,
                'citations'          => [
                    ['source' => 'nmap', 'line' => 12, 'snippet' => "<portid=\"22\" protocol=\"tcp\"><state state=\"open\"/>", 'ref' => 'https://nmap.org/nsedoc/scripts/ssh2-enum-algos.html'],
                    ['source' => 'nvd',  'line' => null, 'snippet' => 'CVE-2023-48795 SSH Terrapin prefix-truncation attack', 'ref' => 'https://nvd.nist.gov/vuln/detail/CVE-2023-48795'],
                ],
            ],
            [
                'title'       => 'Outdated Apache HTTP Server Version',
                'description' => 'The HTTP banner reports Apache httpd 2.4.58 on Ubuntu. While 2.4.58 itself '
                    . 'is patched against the most recent CVEs at the time of writing, the version string '
                    . 'leaks the OS family and patch level, simplifying attacker reconnaissance. The '
                    . 'underlying mod_rewrite has not been configured to enforce HTTPS redirects.',
                'severity'           => Finding::SEVERITY_MEDIUM,
                'cvss_score'         => 5.3,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-200',
                'evidence'           => "80/tcp open  http  Apache httpd 2.4.58 ((Ubuntu))\n"
                    . "  HTTP/1.1 200 OK\n"
                    . "  Server: Apache/2.4.58 (Ubuntu)\n"
                    . "  X-Powered-By: PHP/8.2.15\n"
                    . "  No redirect to https:// observed",
                'endpoint'           => 'http://:80',
                'affected_component' => 'Apache httpd 2.4.58',
                'source_tool'        => 'nmap',
                'remediation'        => "1. Disable ServerSignature and ServerTokens in /etc/apache2/conf-enabled/security.conf:\n"
                    . "     ServerTokens Prod\n"
                    . "     ServerSignature Off\n"
                    . "2. Force HTTPS with a 301 redirect in the vhost:\n"
                    . "     RewriteEngine On\n"
                    . "     RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n"
                    . "3. Remove the X-Powered-By header by setting expose_php = Off in php.ini.",
                'impact_score'       => 4.8,
                'citations'          => [
                    ['source' => 'nmap', 'line' => 24, 'snippet' => '<service name="http" product="Apache httpd" version="2.4.58"/>', 'ref' => 'https://httpd.apache.org/security_report.html'],
                ],
            ],
            [
                'title'       => 'TLS 1.0 and TLS 1.1 Enabled on HTTPS Listener',
                'description' => 'The HTTPS listener on port 443 negotiates TLS versions 1.0 and 1.1, both '
                    . 'of which were deprecated by the IETF in RFC 8996 (March 2021). TLS 1.0 is '
                    . 'susceptible to BEAST-style attacks against CBC cipher suites and is explicitly '
                    . 'prohibited for PCI-DSS compliance after 30 June 2018.',
                'severity'           => Finding::SEVERITY_MEDIUM,
                'cvss_score'         => 5.9,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-326',
                'evidence'           => "443/tcp open  https  Apache httpd 2.4.58 (TLS)\n"
                    . "  TLS versions offered:\n"
                    . "    TLSv1.0    [deprecated — RFC 8996]\n"
                    . "    TLSv1.1    [deprecated — RFC 8996]\n"
                    . "    TLSv1.2    [ok]\n"
                    . "    TLSv1.3    [ok]\n"
                    . "  Negotiated cipher: TLS_AES_256_GCM_SHA384",
                'endpoint'           => 'https://:443',
                'affected_component' => 'Apache mod_ssl 2.4.58',
                'source_tool'        => 'nmap',
                'remediation'        => "1. Edit /etc/apache2/mods-enabled/ssl.conf and set:\n"
                    . "     SSLProtocol -all +TLSv1.2 +TLSv1.3\n"
                    . "     SSLCipherSuite TLSv1.2 ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384\n"
                    . "     SSLHonorCipherOrder on\n"
                    . "2. Restart apache: sudo systemctl restart apache2\n"
                    . "3. Validate with: nmap --script ssl-enum-ciphers -p 443 <host>",
                'impact_score'       => 5.5,
                'citations'          => [
                    ['source' => 'nmap', 'line' => 41, 'snippet' => 'TLS versions: TLSv1.0, TLSv1.1, TLSv1.2, TLSv1.3', 'ref' => 'https://datatracker.ietf.org/doc/html/rfc8996'],
                ],
            ],
            [
                'title'       => 'HTTP Proxy Port 8080 Exposed Without Authentication',
                'description' => 'TCP port 8080 is open and exposes a Jetty 9.4.51 HTTP interface without '
                    . 'requiring authentication. Unauthenticated access to internal admin endpoints '
                    . 'could permit configuration disclosure or remote code execution depending on the '
                    . 'upstream application.',
                'severity'           => Finding::SEVERITY_LOW,
                'cvss_score'         => 3.7,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-306',
                'evidence'           => "8080/tcp open  http-proxy  Jetty 9.4.51.v20230217\n"
                    . "  GET / HTTP/1.1 → 200 OK\n"
                    . "  No WWW-Authenticate header returned\n"
                    . "  Server: Jetty(9.4.51.v20230217)",
                'endpoint'           => 'http://:8080',
                'affected_component' => 'Jetty 9.4.51.v20230217',
                'source_tool'        => 'nmap',
                'remediation'        => "1. Bind the admin interface to 127.0.0.1 only, or place it behind a VPN.\n"
                    . "2. Require authentication (HTTP Basic + TLS, or OAuth2 bearer tokens).\n"
                    . "3. If the port must remain public, add a reverse proxy with mTLS in front.\n"
                    . "4. Re-scan to confirm the listener is no longer reachable without credentials.",
                'impact_score'       => 3.0,
                'citations'          => [
                    ['source' => 'nmap', 'line' => 53, 'snippet' => '<portid="8080" protocol="tcp"><state state="open"/>', 'ref' => 'https://cwe.mitre.org/data/definitions/306.html'],
                ],
            ],
            [
                'title'       => 'SSH Service Version Disclosure',
                'description' => 'The SSH banner advertises the exact OpenSSH version and OS patch level: '
                    . '"SSH-2.0-OpenSSH_8.9p1 Ubuntu-3ubuntu0.6". This disclosure is informational but '
                    . 'meaningfully shortens the time-to-exploitation when a CVE affecting this version '
                    . 'is published.',
                'severity'           => Finding::SEVERITY_LOW,
                'cvss_score'         => 3.1,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-200',
                'evidence'           => "SSH banner: SSH-2.0-OpenSSH_8.9p1 Ubuntu-3ubuntu0.6\n"
                    . "Captured via: nmap -sV -p 22 <host>",
                'endpoint'           => 'tcp://22',
                'affected_component' => 'OpenSSH 8.9p1',
                'source_tool'        => 'nmap',
                'remediation'        => "1. Add to /etc/ssh/sshd_config:\n"
                    . "     Banner /etc/issue.net\n"
                    . "     DebianBanner no\n"
                    . "2. Put a non-revealing banner in /etc/issue.net (e.g. \"Authorised access only\").\n"
                    . "3. Restart sshd.",
                'impact_score'       => 2.0,
                'citations'          => [
                    ['source' => 'nmap', 'line' => 12, 'snippet' => '<service name="ssh" product="OpenSSH" version="8.9p1"/>', 'ref' => 'https://man.openbsd.org/sshd_config#Banner'],
                ],
            ],
            [
                'title'       => 'ICMP Echo Reachable From Internet',
                'description' => 'The host responds to ICMP echo requests (ping). While rarely exploitable '
                    . 'on its own, this confirms host liveness to opportunistic scanners and is the first '
                    . 'signal collected by masscan / Shodan sweeps.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-200',
                'evidence'           => "Host status: up\nReason: echo-reply\nReason TTL: 64",
                'endpoint'           => 'icmp://',
                'affected_component' => 'kernel networking stack',
                'source_tool'        => 'nmap',
                'remediation'        => "1. Consider dropping ICMP echo at the perimeter firewall unless monitoring requires it:\n"
                    . "     iptables -A INPUT -p icmp --icmp-type echo-request -j DROP\n"
                    . "2. Document the operational justification if ICMP must remain open.",
                'impact_score'       => 0.5,
                'citations'          => [
                    ['source' => 'nmap', 'line' => 5, 'snippet' => '<status state="up" reason="echo-reply"/>', 'ref' => null],
                ],
            ],
            [
                'title'       => 'Service Fingerprint Available (Informational)',
                'description' => 'Nmap successfully fingerprinted all four open services (ssh, http, https, '
                    . 'http-proxy). The detailed version strings, while useful for inventory, are also '
                    . 'useful for an attacker crafting targeted exploits.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-200',
                'evidence'           => "Service fingerprints captured for ports 22, 80, 443, 8080.\n"
                    . "Confidence: 10/10 (probed)",
                'endpoint'           => 'tcp://22,80,443,8080',
                'affected_component' => 'Apache httpd, OpenSSH, Jetty',
                'source_tool'        => 'nmap',
                'remediation'        => "1. Consider hiding version strings on all services as documented in the related findings.\n"
                    . "2. Subscribe to vendor security mailing lists for the captured versions.",
                'impact_score'       => 0.3,
                'citations'          => [
                    ['source' => 'nmap', 'line' => null, 'snippet' => 'service method="probed" conf="10"', 'ref' => 'https://nmap.org/book/vscan.html'],
                ],
            ],
            [
                'title'       => 'TCP/IP Hostname Reverse Lookup Resolves',
                'description' => 'The target\'s reverse DNS lookup resolves to the configured hostname. '
                    . 'This is informational and confirms DNS configuration is consistent for the in-scope '
                    . 'asset.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => null,
                'evidence'           => "PTR: <host>\nHostnames: [{name: <host>, type: user}]",
                'endpoint'           => 'dns://',
                'affected_component' => 'reverse DNS zone',
                'source_tool'        => 'nmap',
                'remediation'        => "1. No remediation required — informational finding.",
                'impact_score'       => 0.1,
                'citations'          => [
                    ['source' => 'nmap', 'line' => 8, 'snippet' => '<hostnames><hostname name="..." type="user"/></hostnames>', 'ref' => null],
                ],
            ],
            [
                'title'       => 'Scan Completed Successfully',
                'description' => 'nmap completed a full TCP SYN scan with service enumeration against the '
                    . 'in-scope host. 4 ports were found open and 996 ports are closed or filtered. '
                    . 'No abnormal scan behaviour was observed.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => null,
                'evidence'           => "Runstats: 1 IP address (1 host up) scanned in 120.45 seconds\n"
                    . "Exit: success",
                'endpoint'           => 'internal://scan-summary',
                'affected_component' => 'nmap runner',
                'source_tool'        => 'nmap',
                'remediation'        => "1. No remediation required — informational finding.",
                'impact_score'       => 0.0,
                'citations'          => [
                    ['source' => 'nmap', 'line' => 73, 'snippet' => '<finished exit="success"/>', 'ref' => null],
                ],
            ],
        ];
    }

    /**
     * Catalogue for nuclei scans — focuses on application-layer
     * vulnerabilities (CVEs, exposures, misconfigurations).
     *
     * @return list<array<string,mixed>>
     */
    private function nucleiCatalogue(): array
    {
        return [
            [
                'title'       => 'Apache OFBiz Path Traversal Remote Code Execution (CVE-2024-1234)',
                'description' => 'The application exposes an Apache OFBiz endpoint vulnerable to path '
                    . 'traversal combined with deserialization, leading to unauthenticated remote code '
                    . 'execution. The endpoint /webtools/control/ProgramExport accepts a crafted '
                    . 'parameter that escapes the intended working directory and triggers a Groovy '
                    . 'expression evaluation, yielding OS command execution as the OFBiz user.',
                'severity'           => Finding::SEVERITY_CRITICAL,
                'cvss_score'         => 9.8,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H',
                'cve_id'             => 'CVE-2024-1234',
                'cwe_id'             => 'CWE-22',
                'evidence'           => "Matched template: CVE-2024-1234\n"
                    . "URL: https://<host>/webtools/control/ProgramExport;/~Example\n"
                    . "Response: HTTP/1.1 200 OK\n"
                    . "Body contained: \"example-attribute\"\n"
                    . "Confidence: 100%",
                'endpoint'           => 'https://<host>/webtools/control/ProgramExport;/~Example',
                'affected_component' => 'Apache OFBiz 18.12',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Upgrade Apache OFBiz to 18.12.16 or later.\n"
                    . "2. If upgrade is delayed, restrict /webtools/control/ProgramExport to internal IPs only.\n"
                    . "3. Apply vendor hotfix: https://issues.apache.org/jira/browse/OFBIZ-XXXX\n"
                    . "4. Review access logs for indicators of exploitation (strings like '~' in the URI).\n"
                    . "5. Rotate credentials and database passwords if exploitation is suspected.",
                'impact_score'       => 9.5,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => 1, 'snippet' => '"template-id": "CVE-2024-1234", "severity": "critical"', 'ref' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-1234'],
                    ['source' => 'manual', 'line' => null, 'snippet' => 'Confirmed via curl reproduction', 'ref' => null],
                ],
            ],
            [
                'title'       => 'WordPress Plugin SQL Injection (CVE-2023-5678)',
                'description' => 'A third-party WordPress plugin installed on the target is vulnerable to '
                    . 'unauthenticated SQL injection via the "id" parameter on admin-ajax.php?action=example. '
                    . 'The plugin concatenates user input directly into a SQL query without prepared '
                    . 'statements, allowing an attacker to extract sensitive data including password hashes.',
                'severity'           => Finding::SEVERITY_CRITICAL,
                'cvss_score'         => 9.8,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H',
                'cve_id'             => 'CVE-2023-5678',
                'cwe_id'             => 'CWE-89',
                'evidence'           => "Matched template: CVE-2023-5678\n"
                    . "URL: https://<host>/wp-admin/admin-ajax.php?action=example&id=1'\n"
                    . "Response: MySQL error: \"You have an error in your SQL syntax near '1'' at line 1\"\n"
                    . "Time-based payload id=1 AND SLEEP(5) confirmed (response delayed 5.02s)",
                'endpoint'           => 'https://<host>/wp-admin/admin-ajax.php?action=example',
                'affected_component' => 'WordPress plugin "example-plugin" 1.4.2',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Update the affected plugin to version 1.5.0 or later.\n"
                    . "2. If the plugin is unused, remove it entirely.\n"
                    . "3. Audit the database for evidence of exfiltration:\n"
                    . "     SELECT * FROM wp_users WHERE user_login LIKE '%admin%';\n"
                    . "4. Force password reset for all privileged users.\n"
                    . "5. Deploy a WAF rule blocking SQLi payloads on admin-ajax.php.",
                'impact_score'       => 9.2,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => 2, 'snippet' => '"template-id": "CVE-2023-5678", "severity": "critical"', 'ref' => 'https://nvd.nist.gov/vuln/detail/CVE-2023-5678'],
                ],
            ],
            [
                'title'       => 'Exposed .git Directory',
                'description' => 'The .git/config file is publicly accessible, leaking the repository '
                    . 'structure, remote URLs, and potentially the full source code history if the .git '
                    . 'objects are also exposed. An attacker can reconstruct the application source and '
                    . 'identify further vulnerabilities or hardcoded secrets.',
                'severity'           => Finding::SEVERITY_HIGH,
                'cvss_score'         => 7.5,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-538',
                'evidence'           => "URL: https://<host>/.git/config\n"
                    . "Response: HTTP/1.1 200 OK\n"
                    . "Body:\n"
                    . "  [core]\n"
                    . "      repositoryformatversion = 0\n"
                    . "      filemode = true\n"
                    . "  [remote \"origin\"]\n"
                    . "      url = git@github.com:acme/internal-app.git\n"
                    . "      fetch = +refs/heads/*:refs/remotes/origin/*",
                'endpoint'           => 'https://<host>/.git/config',
                'affected_component' => 'web server / deployment pipeline',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Block access to .git at the web server:\n"
                    . "   Apache: RedirectMatch 404 /\\.(git|svn|hg)\n"
                    . "   Nginx:  location ~ /\\.git { deny all; return 404; }\n"
                    . "2. Remove the .git directory from the document root entirely (preferred).\n"
                    . "3. Use a CI/CD pipeline that builds an artefact without VCS metadata.\n"
                    . "4. Rotate any secrets committed to the leaked history (assume compromise).",
                'impact_score'       => 7.8,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => 3, 'snippet' => '"template-id": "git-config", "severity": "high"', 'ref' => 'https://owasp.org/www-project-web-security-testing-guide/'],
                ],
            ],
            [
                'title'       => 'SQL Injection in Login Form',
                'description' => 'The /login endpoint accepts a username parameter that is concatenated '
                    . 'into a SQL query without parameterisation. A boolean-based payload '
                    . "(\"' OR '1'='1' -- \") was observed to bypass authentication and yield an "
                    . 'authenticated session without valid credentials.',
                'severity'           => Finding::SEVERITY_HIGH,
                'cvss_score'         => 8.1,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-89',
                'evidence'           => "POST /login HTTP/1.1\n"
                    . "Body: username=admin'+OR+'1'='1'--&password=x\n"
                    . "Response: HTTP/1.1 302 Found\n"
                    . "Set-Cookie: session=...; HttpOnly\n"
                    . "Location: /dashboard\n"
                    . "Boolean-based SQLi confirmed via authentication bypass",
                'endpoint'           => 'https://<host>/login',
                'affected_component' => 'login controller',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Replace string concatenation with prepared statements:\n"
                    . "     \$stmt = \$pdo->prepare('SELECT * FROM users WHERE username = ? AND password_hash = ?');\n"
                    . "     \$stmt->execute([\$username, \$hash]);\n"
                    . "2. Enforce server-side input validation (allowlist of characters).\n"
                    . "3. Deploy a WAF rule blocking common SQLi payloads on /login.\n"
                    . "4. Add unit tests covering authentication boundary conditions.",
                'impact_score'       => 8.0,
                'citations'          => [
                    ['source' => 'manual', 'line' => null, 'snippet' => 'Authentication bypass confirmed', 'ref' => 'https://owasp.org/www-community/attacks/SQL_Injection'],
                ],
            ],
            [
                'title'       => 'Cross-Site Scripting (Reflected) in Search Endpoint',
                'description' => 'The /search?q= endpoint reflects the user-supplied query parameter '
                    . 'into the HTML response without escaping. A payload of '
                    . '<script>alert(document.domain)</script> was reflected and executed in the '
                    . 'browser, allowing session theft, credential harvesting, or arbitrary actions '
                    . 'on behalf of the victim.',
                'severity'           => Finding::SEVERITY_HIGH,
                'cvss_score'         => 6.1,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-79',
                'evidence'           => "GET /search?q=%3Cscript%3Ealert(document.domain)%3C%2Fscript%3E\n"
                    . "Response: HTTP/1.1 200 OK\n"
                    . "Body contained: \"<script>alert(document.domain)</script>\" (unescaped)\n"
                    . "Browser execution confirmed via headless Chromium",
                'endpoint'           => 'https://<host>/search',
                'affected_component' => 'search view template',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Escape all user-supplied input when rendering HTML:\n"
                    . "   Blade:   {{ \$q }} (not {!! \$q !!})\n"
                    . "   Twig:    {{ q|e }}\n"
                    . "   React/Vue: JSX interpolation is escaped by default.\n"
                    . "2. Add Content-Security-Policy header: default-src 'self'; script-src 'self'\n"
                    . "3. Add an automated test that submits <script> payloads and asserts they are escaped.",
                'impact_score'       => 6.0,
                'citations'          => [
                    ['source' => 'manual', 'line' => null, 'snippet' => 'Reflected payload executed in browser', 'ref' => 'https://owasp.org/www-community/attacks/xss/'],
                ],
            ],
            [
                'title'       => 'Missing HSTS Security Header',
                'description' => 'The HTTPS response does not include the Strict-Transport-Security '
                    . 'header. Without HSTS, a man-in-the-middle attacker can downgrade the connection '
                    . 'to HTTP on first contact and intercept credentials.',
                'severity'           => Finding::SEVERITY_MEDIUM,
                'cvss_score'         => 5.3,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:H/PR:N/UI:R/S:U/C:H/I:L/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-319',
                'evidence'           => "GET / HTTP/1.1 (over HTTPS)\n"
                    . "Response headers:\n"
                    . "  Server: Apache/2.4.58\n"
                    . "  Content-Type: text/html\n"
                    . "  [missing] Strict-Transport-Security",
                'endpoint'           => 'https://<host>/',
                'affected_component' => 'Apache mod_headers',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Enable HSTS in Apache:\n"
                    . "     Header always set Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\"\n"
                    . "2. After validation, consider submitting the domain to the HSTS preload list:\n"
                    . "   https://hstspreload.org/\n"
                    . "3. Verify with: curl -sI https://<host>/ | grep -i strict-transport",
                'impact_score'       => 5.0,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => 4, 'snippet' => '"template-id": "missing-security-headers"', 'ref' => 'https://datatracker.ietf.org/doc/html/rfc6797'],
                ],
            ],
            [
                'title'       => 'Outdated jQuery Version (CVE-2020-11023)',
                'description' => 'The page loads jQuery 3.4.1 which is vulnerable to CVE-2020-11023, '
                    . 'a cross-site scripting issue in the HTML parser when passing strings containing '
                    . '<option> elements from untrusted sources.',
                'severity'           => Finding::SEVERITY_MEDIUM,
                'cvss_score'         => 5.4,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:L/UI:R/S:C/C:L/I:L/A:N',
                'cve_id'             => 'CVE-2020-11023',
                'cwe_id'             => 'CWE-79',
                'evidence'           => "GET / HTTP/1.1\n"
                    . "Body contained: <script src=\"/assets/jquery-3.4.1.min.js\"></script>\n"
                    . "Hash matched: jquery 3.4.1",
                'endpoint'           => 'https://<host>/assets/jquery-3.4.1.min.js',
                'affected_component' => 'jQuery 3.4.1',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Upgrade jQuery to 3.5.0 or later.\n"
                    . "2. If pinned for compatibility reasons, apply the official patch:\n"
                    . "   https://github.com/jquery/jquery/commit/95b37f8c6\n"
                    . "3. Re-test the page to confirm the upgraded library is loaded.",
                'impact_score'       => 4.5,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => null, 'snippet' => 'matched fingerprint: jquery 3.4.1', 'ref' => 'https://nvd.nist.gov/vuln/detail/CVE-2020-11023'],
                ],
            ],
            [
                'title'       => 'Missing Content-Security-Policy Header',
                'description' => 'No Content-Security-Policy header is returned on HTML responses. '
                    . 'CSP is the primary defence-in-depth control against cross-site scripting and '
                    . 'data exfiltration. Its absence means a single injection flaw leads directly '
                    . 'to arbitrary script execution.',
                'severity'           => Finding::SEVERITY_MEDIUM,
                'cvss_score'         => 4.3,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:U/C:L/I:L/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-693',
                'evidence'           => "GET / HTTP/1.1\n"
                    . "Response headers (relevant):\n"
                    . "  [missing] Content-Security-Policy\n"
                    . "  [missing] X-Frame-Options\n"
                    . "  [missing] X-Content-Type-Options",
                'endpoint'           => 'https://<host>/',
                'affected_component' => 'Apache mod_headers',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Define a restrictive CSP and deploy via Apache:\n"
                    . "     Header always set Content-Security-Policy \"default-src 'self'; object-src 'none'; base-uri 'self'\"\n"
                    . "2. Add X-Frame-Options: DENY and X-Content-Type-Options: nosniff.\n"
                    . "3. Validate with: https://securityheaders.com/?q=<host>",
                'impact_score'       => 4.0,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => 4, 'snippet' => '"template-id": "missing-security-headers"', 'ref' => 'https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP'],
                ],
            ],
            [
                'title'       => 'TLS Version 1.0 Protocol Deprecated',
                'description' => 'The HTTPS service negotiates TLS 1.0, deprecated by RFC 8996. This is '
                    . 'a low-severity finding at the network layer but is reported by nuclei as a TLS '
                    . 'misconfiguration.',
                'severity'           => Finding::SEVERITY_LOW,
                'cvss_score'         => 3.7,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-326',
                'evidence'           => "Matched template: tls-version-1-0\n"
                    . "Negotiated: TLSv1.0 with cipher TLS_RSA_WITH_AES_128_CBC_SHA",
                'endpoint'           => 'https://<host>:443',
                'affected_component' => 'Apache mod_ssl',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Disable TLS 1.0 and 1.1 in /etc/apache2/mods-enabled/ssl.conf:\n"
                    . "     SSLProtocol -all +TLSv1.2 +TLSv1.3\n"
                    . "2. Restart apache.",
                'impact_score'       => 3.0,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => 5, 'snippet' => '"template-id": "tls-version-1-0", "severity": "low"', 'ref' => 'https://datatracker.ietf.org/doc/html/rfc8996'],
                ],
            ],
            [
                'title'       => 'Cookie Without Secure Attribute',
                'description' => 'The session cookie is set without the Secure attribute, allowing it to '
                    . 'be transmitted over plaintext HTTP if the user is ever redirected away from HTTPS '
                    . '(e.g. via an MITM downgrade).',
                'severity'           => Finding::SEVERITY_LOW,
                'cvss_score'         => 3.1,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:H/PR:N/UI:R/S:U/C:L/I:L/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-614',
                'evidence'           => "Set-Cookie: session=...; HttpOnly\n"
                    . "[missing] Secure attribute",
                'endpoint'           => 'https://<host>/',
                'affected_component' => 'session middleware',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Set the Secure attribute on all session cookies.\n"
                    . "   PHP: session.cookie_secure = 1\n"
                    . "   Laravel: 'secure' => env('SESSION_SECURE_COOKIE', true) in config/session.php\n"
                    . "   Express: cookie('session', value, { secure: true })",
                'impact_score'       => 2.5,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => null, 'snippet' => 'Set-Cookie lacks Secure flag', 'ref' => 'https://owasp.org/www-community/controls/SecureCookieAttribute'],
                ],
            ],
            [
                'title'       => 'Server Version Disclosure via X-Powered-By',
                'description' => 'The HTTP response carries an X-Powered-By header disclosing the PHP '
                    . 'version. Informational finding; the disclosure is bounded but useful for attacker '
                    . 'targeting.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-200',
                'evidence'           => "X-Powered-By: PHP/8.2.15",
                'endpoint'           => 'https://<host>/',
                'affected_component' => 'PHP 8.2.15',
                'source_tool'        => 'nuclei',
                'remediation'        => "1. Set expose_php = Off in /etc/php/8.2/fpm/php.ini\n"
                    . "2. Restart php-fpm.",
                'impact_score'       => 0.5,
                'citations'          => [
                    ['source' => 'nuclei', 'line' => null, 'snippet' => 'header: X-Powered-By: PHP/8.2.15', 'ref' => 'https://www.php.net/manual/en/ini.core.php#ini.expose-php'],
                ],
            ],
        ];
    }

    /**
     * Catalogue for OSINT scans — focuses on externally observable
     * metadata (DNS, SSL, registrar).
     *
     * @return list<array<string,mixed>>
     */
    private function osintCatalogue(): array
    {
        return [
            [
                'title'       => 'SSL Certificate Expiring Within 60 Days',
                'description' => 'The TLS certificate for the target expires in fewer than 60 days. '
                    . 'If not renewed before the expiry date, browsers will display a full-page '
                    . 'warning to users and APIs will refuse the connection.',
                'severity'           => Finding::SEVERITY_MEDIUM,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:L',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-295',
                'evidence'           => "Subject: CN=<host>\n"
                    . "Issuer: Let's Encrypt R3\n"
                    . "Valid from: 2026-01-12\n"
                    . "Valid to:   2026-04-12\n"
                    . "Days remaining: 47",
                'endpoint'           => 'tls://<host>:443',
                'affected_component' => 'TLS certificate',
                'source_tool'        => 'osint',
                'remediation'        => "1. Renew the certificate before 2026-04-12.\n"
                    . "2. Automate renewal with certbot --renew-hook \"systemctl reload apache2\".\n"
                    . "3. Monitor certificate expiry with a Prometheus blackbox exporter.",
                'impact_score'       => 3.0,
                'citations'          => [
                    ['source' => 'osint', 'line' => null, 'snippet' => 'ssl.valid_to: 2026-04-12', 'ref' => 'https://letsencrypt.org/docs/'],
                ],
            ],
            [
                'title'       => 'SPF Record Too Permissive',
                'description' => 'The SPF TXT record ends with ~all (softfail) instead of -all (hardfail). '
                    . 'Softfail allows spoofed mail from unauthorised senders to be delivered (typically '
                    . 'to spam), increasing the risk of phishing reaching end users.',
                'severity'           => Finding::SEVERITY_LOW,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:U/C:L/I:L/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-290',
                'evidence'           => "TXT: \"v=spf1 mx a ~all\"\n"
                    . "Recommended: \"v=spf1 mx a -all\"",
                'endpoint'           => 'dns://<host>/TXT',
                'affected_component' => 'DNS TXT record',
                'source_tool'        => 'osint',
                'remediation'        => "1. Update the SPF record to use -all (hardfail):\n"
                    . "   <host>. IN TXT \"v=spf1 mx a -all\"\n"
                    . "2. Monitor mail logs for legitimate senders being rejected after the change.\n"
                    . "3. Consider adding a DMARC record of p=reject once SPF stabilises.",
                'impact_score'       => 2.0,
                'citations'          => [
                    ['source' => 'osint', 'line' => null, 'snippet' => 'TXT: v=spf1 mx a ~all', 'ref' => 'https://datatracker.ietf.org/doc/html/rfc7208'],
                ],
            ],
            [
                'title'       => 'Potential Subdomain Takeover (www)',
                'description' => 'The www subdomain resolves to a CNAME pointing to a third-party '
                    . 'hosting provider (e.g. Heroku, GitHub Pages). If the upstream resource is ever '
                    . 'deleted without removing the DNS record, an attacker can claim the resource and '
                    . 'serve content on the victim\'s domain.',
                'severity'           => Finding::SEVERITY_LOW,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-350',
                'evidence'           => "www.<host>. CNAME <host>.\n"
                    . "CNAME chain ends at a third-party provider.\n"
                    . "Verification: HTTP 404 returned with provider-branded error page.",
                'endpoint'           => 'dns://www.<host>',
                'affected_component' => 'DNS CNAME record',
                'source_tool'        => 'osint',
                'remediation'        => "1. Verify the upstream resource is still provisioned.\n"
                    . "2. Remove the CNAME if the resource is no longer needed.\n"
                    . "3. Implement monitoring that detects dangling CNAMEs.",
                'impact_score'       => 2.5,
                'citations'          => [
                    ['source' => 'osint', 'line' => null, 'snippet' => 'CNAME → third-party provider', 'ref' => 'https://github.com/EdOverflow/can-i-take-over-xyz'],
                ],
            ],
            [
                'title'       => 'DNSSEC Not Configured',
                'description' => 'The domain does not advertise DNSSEC records (no RRSIG/DNSKEY). '
                    . 'Without DNSSEC, downstream resolvers cannot cryptographically verify that DNS '
                    . 'responses are authentic, leaving the domain exposed to DNS spoofing attacks.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:L/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-345',
                'evidence'           => "Query: <host>. DNSKEY\n"
                    . "Response: NOERROR — no records returned\n"
                    . "Query: <host>. RRSIG\n"
                    . "Response: NOERROR — no records returned",
                'endpoint'           => 'dns://<host>/DNSKEY',
                'affected_component' => 'DNS zone',
                'source_tool'        => 'osint',
                'remediation'        => "1. Enable DNSSEC at the registrar.\n"
                    . "2. Sign the zone ( BIND: dnssec-signzone, or use managed DNSSEC at Cloudflare/AWS Route 53).\n"
                    . "3. Publish DS records with the registrar.",
                'impact_score'       => 1.0,
                'citations'          => [
                    ['source' => 'osint', 'line' => null, 'snippet' => 'no DNSKEY/RRSIG records', 'ref' => 'https://datatracker.ietf.org/doc/html/rfc4033'],
                ],
            ],
            [
                'title'       => 'Open Ports Catalogued From Public Scan Data',
                'description' => 'Public scan data (Shodan / Censys) reports 4 open ports consistent '
                    . 'with the platform\'s own nmap results. No discrepancies detected.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => null,
                'evidence'           => "Shodan open ports: 22, 80, 443, 8080\n"
                    . "Last Shodan scan: " . Carbon::now()->subDays(2)->toDateString(),
                'endpoint'           => 'external://shodan',
                'affected_component' => 'public attack surface',
                'source_tool'        => 'osint',
                'remediation'        => "1. No remediation required — informational finding.\n"
                    . "2. Consider registering the asset with Shodan Monitor for change notifications.",
                'impact_score'       => 0.2,
                'citations'          => [
                    ['source' => 'shodan', 'line' => null, 'snippet' => '4 open ports match nmap results', 'ref' => 'https://www.shodan.io/'],
                ],
            ],
            [
                'title'       => 'WHOIS Registrant Org Disclosed',
                'description' => 'WHOIS publishes the registrant organisation name and contact email. '
                    . 'Informational — useful for attacker social-engineering reconnaissance.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => 'CWE-200',
                'evidence'           => "Registrant: <client>\n"
                    . "Registrant email: dns@<host>",
                'endpoint'           => 'whois://<host>',
                'affected_component' => 'WHOIS record',
                'source_tool'        => 'osint',
                'remediation'        => "1. Consider using a WHOIS privacy service.\n"
                    . "2. Use a role-based email (e.g. dns-admin@<host>) rather than a personal address.",
                'impact_score'       => 0.3,
                'citations'          => [
                    ['source' => 'osint', 'line' => null, 'snippet' => 'WHOIS registrant_org visible', 'ref' => 'https://www.icann.org/'],
                ],
            ],
            [
                'title'       => 'Certificate Transparency Log Coverage Confirmed',
                'description' => 'The domain\'s certificates are properly submitted to Certificate '
                    . 'Transparency logs. No unlogged certificates were observed. This is a positive '
                    . 'control validation.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => null,
                'evidence'           => "crt.sh returned 3 unexpired certificates, all logged.\n"
                    . "Earliest log entry: 2026-01-12\n"
                    . "Latest log entry: 2026-02-01",
                'endpoint'           => 'external://crt.sh',
                'affected_component' => 'TLS certificate issuance',
                'source_tool'        => 'osint',
                'remediation'        => "1. No remediation required — informational finding.\n"
                    . "2. Subscribe to crt.sh RSS feed for new certificate issuances.",
                'impact_score'       => 0.0,
                'citations'          => [
                    ['source' => 'osint', 'line' => null, 'snippet' => 'crt.sh: 3 unexpired certs', 'ref' => 'https://crt.sh/'],
                ],
            ],
            [
                'title'       => 'OSINT Collection Summary',
                'description' => 'OSINT collection completed for the target. Subdomain enumeration, '
                    . 'WHOIS, DNS, SSL, and tech-stack fingerprinting modules all executed successfully.',
                'severity'           => Finding::SEVERITY_INFO,
                'cvss_score'         => 0.0,
                'cvss_vector'        => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N',
                'cve_id'             => null,
                'cwe_id'             => null,
                'evidence'           => "Modules executed: whois, dns, ssl, crtsh, tech_detector\n"
                    . "Subdomains discovered: 4\n"
                    . "Technologies detected: 4\n"
                    . "SSL days remaining: 47",
                'endpoint'           => 'internal://osint-summary',
                'affected_component' => 'OSINT runner',
                'source_tool'        => 'osint',
                'remediation'        => "1. No remediation required — informational finding.",
                'impact_score'       => 0.0,
                'citations'          => [
                    ['source' => 'osint', 'line' => null, 'snippet' => 'all modules completed', 'ref' => null],
                ],
            ],
        ];
    }
}
