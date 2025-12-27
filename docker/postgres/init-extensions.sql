-- PostgreSQL Extension Initialization for OpenRegister
-- This script enables required extensions for advanced search capabilities
--
-- Extensions:
-- 1. pgvector - Vector similarity search for AI embeddings and semantic search
-- 2. pg_trgm - Trigram-based full-text search and partial text matching
-- 3. btree_gin - Optimized indexing for GIN indexes
-- 4. btree_gist - Optimized indexing for GiST indexes
-- 5. uuid-ossp - UUID generation functions

-- Enable pgvector extension for vector similarity search.
-- This allows storing and searching AI embeddings (e.g., from OpenAI, Ollama).
-- Use cases: semantic search, RAG (Retrieval Augmented Generation), similarity matching.
CREATE EXTENSION IF NOT EXISTS vector;

-- Enable pg_trgm extension for full-text and partial text search.
-- This provides trigram-based similarity matching and pattern matching.
-- Use cases: autocomplete, fuzzy search, partial string matching, full-text search.
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Enable btree_gin for optimized GIN indexing.
-- GIN indexes are ideal for multi-value columns (arrays, jsonb, full-text).
CREATE EXTENSION IF NOT EXISTS btree_gin;

-- Enable btree_gist for optimized GiST indexing.
-- GiST indexes support geometric and range types, plus custom operators.
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- Enable uuid-ossp for UUID generation.
-- Required for generating UUIDs in database triggers and functions.
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Log successful initialization.
DO $$
BEGIN
    RAISE NOTICE 'OpenRegister PostgreSQL extensions initialized successfully:';
    RAISE NOTICE '  ✓ vector (pgvector) - Vector similarity search';
    RAISE NOTICE '  ✓ pg_trgm - Trigram full-text and partial matching';
    RAISE NOTICE '  ✓ btree_gin - Optimized GIN indexing';
    RAISE NOTICE '  ✓ btree_gist - Optimized GiST indexing';
    RAISE NOTICE '  ✓ uuid-ossp - UUID generation';
END $$;

-- Create helper function for vector similarity search.
-- This function performs cosine similarity search on vector columns.
-- Usage: SELECT * FROM your_table ORDER BY vector_cosine_distance(embedding, query_vector) LIMIT 10;
CREATE OR REPLACE FUNCTION vector_cosine_distance(a vector, b vector)
RETURNS float8
LANGUAGE SQL
IMMUTABLE STRICT PARALLEL SAFE
AS $$
    SELECT 1 - (a <=> b);
$$;

COMMENT ON FUNCTION vector_cosine_distance IS 'Calculate cosine distance between two vectors (returns 0-2, where 0 = identical, 1 = orthogonal, 2 = opposite)';

-- Create helper function for trigram similarity search.
-- This function returns similarity score between two strings (0-1).
-- Usage: SELECT * FROM your_table WHERE similarity(column, 'search term') > 0.3 ORDER BY similarity(column, 'search term') DESC;
CREATE OR REPLACE FUNCTION text_similarity_score(text1 text, text2 text)
RETURNS float4
LANGUAGE SQL
IMMUTABLE STRICT PARALLEL SAFE
AS $$
    SELECT similarity(text1, text2);
$$;

COMMENT ON FUNCTION text_similarity_score IS 'Calculate trigram similarity between two text strings (returns 0-1, where 1 = identical)';

-- Set default similarity threshold for pg_trgm.
-- This affects the % operator behavior (e.g., 'text' % 'search').
-- Lower values = more fuzzy matches, higher values = stricter matches.
ALTER DATABASE nextcloud SET pg_trgm.similarity_threshold = 0.3;

-- Performance optimization: Set work_mem for better index building.
ALTER DATABASE nextcloud SET maintenance_work_mem = '256MB';

-- Log completion message.
DO $$
BEGIN
    RAISE NOTICE '';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'PostgreSQL Search Configuration Complete';
    RAISE NOTICE '========================================';
    RAISE NOTICE '';
    RAISE NOTICE 'Vector Search (pgvector):';
    RAISE NOTICE '  - Use vector data type for embeddings';
    RAISE NOTICE '  - Create index: CREATE INDEX ON table USING ivfflat (embedding vector_cosine_ops);';
    RAISE NOTICE '  - Query: ORDER BY embedding <=> query_vector LIMIT 10';
    RAISE NOTICE '';
    RAISE NOTICE 'Full-Text Search (pg_trgm):';
    RAISE NOTICE '  - Create index: CREATE INDEX ON table USING gin (column gin_trgm_ops);';
    RAISE NOTICE '  - Query: WHERE column % ''search'' OR column ILIKE ''%%search%%''';
    RAISE NOTICE '  - Similarity: ORDER BY similarity(column, ''search'') DESC';
    RAISE NOTICE '';
    RAISE NOTICE 'No external search engine (Solr/Elasticsearch) required!';
    RAISE NOTICE '========================================';
END $$;

