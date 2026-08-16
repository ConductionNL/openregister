-- Create second database for federation testing
-- This runs automatically via docker-entrypoint-initdb.d

CREATE DATABASE nc2 OWNER nextcloud;

-- Enable extensions on the second database
\connect nc2;

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

ALTER DATABASE nc2 SET pg_trgm.similarity_threshold = 0.3;
ALTER DATABASE nc2 SET maintenance_work_mem = '256MB';

DO $$
BEGIN
    RAISE NOTICE 'Federation test: second database (nc2) initialized successfully';
END $$;
