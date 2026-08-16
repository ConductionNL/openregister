-- Federation test: initialize both databases with required extensions
-- Runs on the default database (nc1) first, then creates and configures nc2

-- Extensions for nc1 (default database)
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

ALTER DATABASE nc1 SET pg_trgm.similarity_threshold = 0.3;
ALTER DATABASE nc1 SET maintenance_work_mem = '256MB';

-- Create and configure nc2
CREATE DATABASE nc2 OWNER nextcloud;

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
    RAISE NOTICE 'Federation test databases initialized: nc1 + nc2';
END $$;
