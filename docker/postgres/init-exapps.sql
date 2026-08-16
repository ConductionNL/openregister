-- Initialize databases for ExApps
-- This script runs on PostgreSQL startup

-- Create databases for each ExApp (PostgreSQL syntax)
SELECT 'CREATE DATABASE keycloak' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'keycloak')\gexec
SELECT 'CREATE DATABASE openzaak' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'openzaak')\gexec
SELECT 'CREATE DATABASE openklant' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'openklant')\gexec
SELECT 'CREATE DATABASE opentalk' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'opentalk')\gexec
SELECT 'CREATE DATABASE valtimo' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'valtimo')\gexec

-- Grant privileges to nextcloud user
GRANT ALL PRIVILEGES ON DATABASE keycloak TO nextcloud;
GRANT ALL PRIVILEGES ON DATABASE openzaak TO nextcloud;
GRANT ALL PRIVILEGES ON DATABASE openklant TO nextcloud;
GRANT ALL PRIVILEGES ON DATABASE opentalk TO nextcloud;
GRANT ALL PRIVILEGES ON DATABASE valtimo TO nextcloud;
