#!/bin/bash
set -e

# Initialize databases for ExApps
# This script runs on PostgreSQL startup

echo "Creating ExApp databases..."

# Function to create database if it doesn't exist
create_db_if_not_exists() {
    local db=$1
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
        SELECT 'CREATE DATABASE $db' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$db')\gexec
        GRANT ALL PRIVILEGES ON DATABASE $db TO $POSTGRES_USER;
EOSQL
    echo "Database $db ready"
}

# Create databases for each ExApp
for db in keycloak openzaak openklant opentalk valtimo; do
    create_db_if_not_exists $db
done

# Add PostGIS extension to openzaak (required for geo features)
echo "Adding PostGIS to openzaak..."
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "openzaak" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS postgis;
    CREATE EXTENSION IF NOT EXISTS pg_trgm;
EOSQL

echo "ExApp databases initialized successfully!"
