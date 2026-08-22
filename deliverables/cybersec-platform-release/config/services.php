<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Internal API gateway that fronts all Python microservices
    // (recon, security, osint, ai, worker). The Laravel app uses this
    // base URL to dispatch scans and fetch OSINT / chat completions.
    'api_gateway' => [
        'url'   => env('API_GATEWAY_URL', 'http://api-gateway:8080'),
        'token' => env('API_GATEWAY_TOKEN', ''),
    ],

    // Python interpreter used by inline PHP fallbacks that shell out to
    // scan_worker.py (OSINT collection, sandbox launcher). Override via
    // env if your container ships Python at a non-default path.
    'python' => [
        'binary' => env('PYTHON_BINARY', trim((string) (PHP_OS_FAMILY === 'Windows' ? 'where python' : 'command -v python3 2>/dev/null') ?: 'python3') ?: 'python3'),
    ],

    // Internal microservice URLs (used by MicroserviceClient when the
    // api-gateway is bypassed — e.g., during local dev without Docker).
    'recon' => [
        'url' => env('RECON_SERVICE_URL', 'http://127.0.0.1:5000'),
    ],
    'security_service' => [
        'url' => env('SECURITY_SERVICE_URL', 'http://127.0.0.1:5001'),
    ],
    'osint_service' => [
        'url' => env('OSINT_SERVICE_URL', 'http://127.0.0.1:5002'),
    ],
    'ai_service' => [
        'url'      => env('AI_SERVICE_URL', 'http://127.0.0.1:5003'),
        'host'     => env('OLLAMA_HOST', 'http://127.0.0.1:11434'),
        'model'    => env('OLLAMA_MODEL', 'qwen2.5-coder:7b'),
        'timeout'  => (int) env('OLLAMA_TIMEOUT', 120),
    ],

    // Service mesh auth (Laravel -> gateway -> microservices)
    'mesh' => [
        'token'   => env('SERVICE_MESH_TOKEN', ''),
        'timeout' => (int) env('SERVICE_MESH_TIMEOUT', 30),
    ],

    // RBAC configuration (Spatie permission package)
    'rbac' => [
        'default_role'      => env('RBAC_DEFAULT_ROLE', 'analyst'),
        'roles'             => explode(',', (string) env('RBAC_ROLES', 'admin,analyst,client,auditor')),
        'super_admin_role'  => env('RBAC_SUPER_ADMIN_ROLE', 'admin'),
        'guard'             => env('RBAC_GUARD', 'web'),
        'cache_ttl'         => (int) env('RBAC_CACHE_TTL', 86400),
    ],

    // Audit log retention
    'audit' => [
        'enabled'         => filter_var(env('AUDIT_LOG_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'driver'          => env('AUDIT_LOG_DRIVER', 'database'),
        'retention_days'  => (int) env('AUDIT_LOG_RETENTION_DAYS', 365),
        'include_failed'  => filter_var(env('AUDIT_LOG_INCLUDE_FAILED', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],

    // Rate-limit policy knobs
    'rate_limits' => [
        'login_per_min'    => (int) env('RATE_LIMIT_LOGIN_PER_MIN', 5),
        'api_per_min'      => (int) env('RATE_LIMIT_API_PER_MIN', 60),
        'scan_per_hour'    => (int) env('RATE_LIMIT_SCAN_PER_HOUR', 10),
        'reports_per_hour' => (int) env('RATE_LIMIT_REPORTS_PER_HOUR', 30),
        'ai_per_hour'      => (int) env('RATE_LIMIT_AI_PER_HOUR', 50),
    ],

    // Scan execution defaults
    'scan' => [
        'default_profile'      => env('SCAN_DEFAULT_PROFILE', 'balanced'),
        'max_concurrent'       => (int) env('SCAN_MAX_CONCURRENT', 4),
        'timeout_per_host'     => (int) env('SCAN_TIMEOUT_PER_HOST', 1800),
        'nmap_default_opts'    => env('SCAN_NMAP_DEFAULT_OPTS', '-T3 -sV --version-intensity 5'),
        'nuclei_default_opts'  => env('SCAN_NUCLEI_DEFAULT_OPTS', '-severity medium,high,critical'),
        'gobuster_default_opts'=> env('SCAN_GOBUSTER_DEFAULT_OPTS', '-t 30 -to 10s'),
        'subfinder_default_opts' => env('SCAN_SUBFINDER_DEFAULT_OPTS', '-silent'),
        'wpscan_api_token'    => env('WPSCAN_API_TOKEN', ''),
    ],

    // Feature flags
    'features' => [
        'ai_enabled'           => filter_var(env('FEATURE_AI_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'osint_enabled'        => filter_var(env('FEATURE_OSINT_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'remediation_scripts'  => filter_var(env('FEATURE_REMEDIATION_SCRIPTS', 'true'), FILTER_VALIDATE_BOOLEAN),
        'demo_mode'            => filter_var(env('FEATURE_DEMO_MODE', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],

    // Docker socket-proxy for the security sandbox feature
    'docker_proxy' => [
        'url'        => env('DOCKER_SOCKET_PROXY', 'http://socket-proxy:2375'),
        'read_only'  => filter_var(env('DOCKER_PROXY_READONLY', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],

    // Wordlists (SecLists) shared between recon microservice and inline scans
    'wordlists' => [
        'dir'         => env('WORDLISTS_DIR', '/app/wordlists'),
        'seclists'    => env('WORDLIST_SECLISTS', '/app/wordlists/SecLists'),
        'common'      => env('WORDLIST_COMMON', '/app/wordlists/SecLists/Discovery/Web-Content/common.txt'),
        'subdomains'  => env('WORDLIST_SUBDOMAINS', '/app/wordlists/SecLists/Discovery/DNS/subdomains-top1million-5000.txt'),
    ],

];
