-- =============================================================================
-- PostgreSQL init script — runs once on first container boot.
-- Enforces a hardened default for the cybersec role & database.
-- =============================================================================
-- The POSTGRES_USER / POSTGRES_DB env vars already created the role and db;
-- this script just tightens connection limits and audit logging.
-- =============================================================================

-- Force SSL on the application role (uncomment once TLS is configured on pg)
-- ALTER ROLE :POSTGRES_USER SET sslmode = 'require';

-- Sensible per-role limits
ALTER ROLE current_user SET statement_timeout = '60s';
ALTER ROLE current_user SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE current_user SET log_min_duration_statement = 1000;  -- log slow queries >1s
ALTER ROLE current_user SET timezone = 'UTC';

-- Ensure the application schema exists
CREATE SCHEMA IF NOT EXISTS cybersec AUTHORIZATION current_user;

-- Extensions (loaded into the cybersec schema)
CREATE EXTENSION IF NOT EXISTS pg_trgm SCHEMA cybersec;
CREATE EXTENSION IF NOT EXISTS unaccent SCHEMA cybersec;
CREATE EXTENSION IF NOT EXISTS pgcrypto SCHEMA cybersec;

-- Notify
\echo '== cybersec PostgreSQL init complete =='
