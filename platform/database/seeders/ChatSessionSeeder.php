<?php

namespace Database\Seeders;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds two sample chat sessions for the analyst account, demonstrating
 * the conversational workflow with the security co-pilot assistant.
 *
 * Idempotent: sessions are keyed on (user_id, title); messages are wiped
 * and re-inserted so the conversation can evolve with the catalogue.
 */
class ChatSessionSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        $users = User::whereIn('email', ['admin@cybersec.local', 'analyst@cybersec.local'])->get();
        $firstProject = Project::first();

        foreach ($users as $user) {
            $this->seedCriticalTriageChat($user, $firstProject);
            $this->seedRemediationWalkthroughChat($user, $firstProject);
        }
    }

    /**
     * First sample chat: critical-finding triage flow.
     */
    private function seedCriticalTriageChat(User $analyst, ?Project $project): void
    {
        $affectedAsset = $project?->targets()->first()?->domain_url ?? 'ensi.tn';

        $session = ChatSession::updateOrCreate(
            ['user_id' => $analyst->id, 'title' => 'Critical finding triage — CVE-2024-1234'],
            [
                'user_id'    => $analyst->id,
                'project_id' => $project?->id,
                'title'      => 'Critical finding triage — CVE-2024-1234',
                'context'    => [
                    'intent'   => 'triage',
                    'topic'    => 'CVE-2024-1234',
                    'severity' => 'critical',
                ],
            ],
        );

        // Wipe & re-insert messages so the catalogue stays the source of truth.
        ChatMessage::where('chat_session_id', $session->id)->delete();

        $messages = [
            [
                'role' => ChatMessage::ROLE_USER,
                'content' => 'I just got an alert about CVE-2024-1234 on the ENSI project. '
                    . 'What is this CVE and how urgent is it?',
                'citations' => null,
            ],
            [
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => "CVE-2024-1234 is a critical (CVSS 9.8) unauthenticated remote code "
                    . "execution vulnerability in Apache OFBiz. The flaw lives in the path-traversal "
                    . "handling of /webtools/control/ProgramExport and chains into a Groovy expression "
                    . "evaluator, yielding OS command execution as the OFBiz JVM user.\n\n"
                    . "Given that:\n"
                    . "  - the affected asset ({$affectedAsset}) "
                    . "is internet-exposed,\n"
                    . "  - the exploit is publicly available,\n"
                    . "  - no authentication is required,\n\n"
                    . "this should be treated as a P0 incident. Recommended next steps:\n"
                    . "  1. Confirm exploitation in access logs (look for ~ or /~Example in the URI).\n"
                    . "  2. Restrict /webtools/control/ProgramExport at the WAF or reverse proxy.\n"
                    . "  3. Upgrade OFBiz to 18.12.16 or later.\n"
                    . "  4. Rotate credentials and DB passwords if exploitation is confirmed.",
                'citations' => [
                    ['source' => 'finding', 'id' => 'CVE-2024-1234', 'ref' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-1234'],
                    ['source' => 'scan', 'tool' => 'nuclei', 'matched_at' => '/webtools/control/ProgramExport;/~Example'],
                ],
            ],
            [
                'role' => ChatMessage::ROLE_USER,
                'content' => 'Can you generate a remediation script I can hand to the sysadmin team?',
                'citations' => null,
            ],
            [
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => "Here is a bash remediation snippet that:\n"
                    . "  1. Backs up the current OFBiz deployment,\n"
                    . "  2. Applies an emergency reverse-proxy block,\n"
                    . "  3. Downloads the patched release.\n\n"
                    . "```bash\n"
                    . "#!/usr/bin/env bash\n"
                    . "set -Eeuo pipefail\n"
                    . "OFBIZ_HOME=/opt/ofbiz\n"
                    . "BACKUP_DIR=/opt/ofbiz-backup-$(date +%Y%m%d-%H%M%S)\n\n"
                    . "# 1) Snapshot the running deployment\n"
                    . "cp -a \"\$OFBIZ_HOME\" \"\$BACKUP_DIR\"\n\n"
                    . "# 2) Block the vulnerable endpoint at the reverse proxy\n"
                    . "cat > /etc/nginx/conf.d/ofbiz-emergency.conf <<'EOF'\n"
                    . "location ~ ^/webtools/control/ProgramExport {\n"
                    . "    deny all;\n"
                    . "    return 404;\n"
                    . "}\n"
                    . "EOF\n"
                    . "nginx -t && systemctl reload nginx\n\n"
                    . "# 3) Pull the patched release (verify SHA-256 before extraction)\n"
                    . "curl -fL -o /tmp/ofbiz-18.12.16.zip \\\n"
                    . "  https://archive.apache.org/dist/ofbiz/apache-ofbiz-18.12.16.zip\n"
                    . "echo 'expected-sha256  /tmp/ofbiz-18.12.16.zip' | sha256sum -c -\n"
                    . "```\n\n"
                    . "Validation steps:\n"
                    . "  - Re-run nuclei against the target.\n"
                    . "  - Confirm the CVE-2024-1234 template no longer matches.\n"
                    . "  - Review the access log to ensure the WAF rule is hitting.",
                'citations' => [
                    ['source' => 'finding', 'id' => 'CVE-2024-1234', 'lines' => [3, 12]],
                    ['source' => 'manual', 'id' => 'sysadmin runbook', 'ref' => 'https://issues.apache.org/jira/browse/OFBIZ-XXXX'],
                ],
            ],
        ];

        foreach ($messages as $msg) {
            ChatMessage::create(array_merge(['chat_session_id' => $session->id], $msg));
        }
    }

    /**
     * Second sample chat: remediation walkthrough for a finding set.
     */
    private function seedRemediationWalkthroughChat(User $analyst, ?Project $project): void
    {
        $session = ChatSession::updateOrCreate(
            ['user_id' => $analyst->id, 'title' => 'Remediation walkthrough — ACME Corp pentest'],
            [
                'user_id'    => $analyst->id,
                'project_id' => $project?->id,
                'title'      => 'Remediation walkthrough — ACME Corp pentest',
                'context'    => [
                    'intent'   => 'remediation',
                    'project'  => $project?->name,
                ],
            ],
        );

        ChatMessage::where('chat_session_id', $session->id)->delete();

        $messages = [
            [
                'role' => ChatMessage::ROLE_USER,
                'content' => 'Summarise the findings from the ACME Corp pentest and rank them by remediation priority.',
                'citations' => null,
            ],
            [
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => "Here is the prioritised summary of findings from the ACME Corp engagement:\n\n"
                    . "P0 — Immediate (critical)\n"
                    . "  1. [CVE-2024-1234] Apache OFBiz RCE — CVSS 9.8\n"
                    . "  2. [CVE-2023-5678] WordPress plugin SQLi — CVSS 9.8\n\n"
                    . "P1 — Within 7 days (high)\n"
                    . "  3. Exposed .git directory — CVSS 7.5\n"
                    . "  4. SQL Injection in /login — CVSS 8.1\n"
                    . "  5. Reflected XSS in /search — CVSS 6.1\n\n"
                    . "P2 — Within 30 days (medium)\n"
                    . "  6. Missing HSTS header — CVSS 5.3\n"
                    . "  7. Outdated jQuery 3.4.1 — CVSS 5.4\n"
                    . "  8. Missing CSP header — CVSS 4.3\n\n"
                    . "P3 — Within 90 days (low / info)\n"
                    . "  9. TLS 1.0 enabled — CVSS 3.7\n"
                    . "  10. Cookie without Secure attribute — CVSS 3.1\n"
                    . "  11. Server version disclosure — informational\n\n"
                    . "Recommended sequencing:\n"
                    . "  - Days 1–2: WAF + reverse proxy blocks for P0 items (immediate risk reduction).\n"
                    . "  - Days 3–5: Apply patches and remove .git directory.\n"
                    . "  - Days 6–14: Refactor data-access layer for SQLi, escape outputs for XSS.\n"
                    . "  - Days 15–30: Header hardening (HSTS, CSP, X-Content-Type-Options).\n"
                    . "  - Days 30+: Deprecate legacy TLS and outdated libraries.\n\n"
                    . "Would you like me to draft a remediation script for any of these items?",
                'citations' => [
                    ['source' => 'report', 'project' => $project?->name],
                ],
            ],
            [
                'role' => ChatMessage::ROLE_USER,
                'content' => 'Yes — give me a single ansible playbook that fixes the header issues (HSTS + CSP).',
                'citations' => null,
            ],
            [
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => "Here is an Ansible playbook that configures HSTS, CSP, and related security "
                    . "headers on Apache hosts in scope for the ACME engagement.\n\n"
                    . "```yaml\n"
                    . "- name: Apply Apache security headers (HSTS + CSP)\n"
                    . "  hosts: acme_web\n"
                    . "  become: true\n"
                    . "  tasks:\n"
                    . "    - name: Ensure mod_headers is enabled\n"
                    . "      apache2_module:\n"
                    . "        name: headers\n"
                    . "        state: present\n"
                    . "      notify: reload apache2\n\n"
                    . "    - name: Deploy security headers snippet\n"
                    . "      copy:\n"
                    . "        dest: /etc/apache2/conf-enabled/security-headers.conf\n"
                    . "        content: |\n"
                    . "          Header always set Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\"\n"
                    . "          Header always set Content-Security-Policy \"default-src 'self'; object-src 'none'; base-uri 'self'\"\n"
                    . "          Header always set X-Frame-Options \"DENY\"\n"
                    . "          Header always set X-Content-Type-Options \"nosniff\"\n"
                    . "          Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n"
                    . "      notify: reload apache2\n\n"
                    . "  handlers:\n"
                    . "    - name: reload apache2\n"
                    . "      service:\n"
                    . "        name: apache2\n"
                    . "        state: reloaded\n"
                    . "```\n\n"
                    . "After applying, validate with:\n"
                    . "  curl -sI https://<host>/ | grep -iE 'strict-transport|content-security|x-frame|x-content-type'\n"
                    . "Or use the public scanner at https://securityheaders.com/?q=<host>.",
                'citations' => [
                    ['source' => 'finding', 'ids' => ['missing HSTS header', 'missing CSP header']],
                    ['source' => 'manual', 'ref' => 'https://httpd.apache.org/docs/2.4/mod/mod_headers.html'],
                ],
            ],
        ];

        foreach ($messages as $msg) {
            ChatMessage::create(array_merge(['chat_session_id' => $session->id], $msg));
        }
    }
}
