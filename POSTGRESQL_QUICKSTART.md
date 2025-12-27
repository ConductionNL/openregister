# PostgreSQL Quick Start Guide

Get OpenRegister running with PostgreSQL vector and full-text search in 5 minutes!

## Prerequisites

- Docker and Docker Compose installed
- Git (to clone/update repository)

## New Installation (Easiest!)

```bash
# 1. Clone or update repository.
git clone https://github.com/ConductionNL/openregister.git
cd openregister

# 2. Start all services.
docker-compose up -d

# 3. Wait for services to be ready (30-60 seconds).
docker-compose logs -f

# 4. Access Nextcloud.
# Open browser: http://localhost:8080
# Login: admin / admin

# 5. Verify PostgreSQL extensions.
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c '\\dx'

# Done! You now have:
# ✅ PostgreSQL 16 with pgvector and pg_trgm
# ✅ Vector search for semantic/AI search
# ✅ Full-text search with trigrams
# ✅ No Solr or Elasticsearch needed!
```

## Migrating from MySQL/Solr

```bash
# 1. BACKUP EVERYTHING FIRST!
docker exec master-database-mysql-1 mysqldump -u nextcloud -pnextcloud nextcloud > backup.sql
docker exec nextcloud tar czf /tmp/data-backup.tar.gz -C /var/www/html data config

# 2. Pull latest changes.
git pull origin main

# 3. Stop services.
docker-compose down

# 4. Start PostgreSQL.
docker-compose up -d db

# 5. Wait for PostgreSQL to be ready.
docker exec openregister-postgres pg_isready -U nextcloud

# 6. Migrate data (choose one method):

# Method A: Using pgloader (recommended).
sudo apt-get install pgloader
cat > migrate.load << 'EOF'
LOAD DATABASE
  FROM mysql://nextcloud:nextcloud@master-database-mysql-1/nextcloud
  INTO postgresql://nextcloud:!ChangeMe!@openregister-postgres/nextcloud
WITH include drop, create tables, create indexes
EOF
pgloader migrate.load

# Method B: Manual export/import.
# See full migration guide: website/docs/development/postgresql-migration.md

# 7. Update Nextcloud config.
docker exec -u 33 master-nextcloud-1 vi /var/www/html/config/config.php
# Change: dbtype='pgsql', dbhost='openregister-postgres', dbport='5432'

# 8. Start all services.
docker-compose up -d

# 9. Create search indexes.
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud

# Run these SQL commands:
ALTER TABLE oc_openregister_objects ADD COLUMN embedding vector(1536);
CREATE INDEX idx_objects_embedding ON oc_openregister_objects USING ivfflat (embedding vector_cosine_ops);
CREATE INDEX idx_objects_title_trgm ON oc_openregister_objects USING gin (title gin_trgm_ops);

# 10. Generate embeddings for existing data.
docker exec -u 33 master-nextcloud-1 php occ openregister:generate-embeddings --all-schemas

# Done! See full migration guide for detailed instructions.
```

## Quick Testing

### Test Vector Search

```bash
# Insert test data with embedding.
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud << EOF
INSERT INTO oc_openregister_objects (title, embedding) 
VALUES ('test document', '[0.1, 0.2, 0.3, ...]'::vector);
EOF

# Search for similar vectors.
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud << EOF
SELECT title, embedding <=> '[0.1, 0.2, 0.3, ...]'::vector AS distance
FROM oc_openregister_objects
ORDER BY distance LIMIT 5;
EOF
```

### Test Text Search

```bash
# Search with trigram similarity.
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud << EOF
SELECT title, similarity(title, 'document') AS score
FROM oc_openregister_objects
WHERE title % 'document'
ORDER BY score DESC LIMIT 10;
EOF
```

### Test via API

```bash
# Semantic search.
curl -u admin:admin 'http://localhost:8080/index.php/apps/openregister/api/objects/search?query=invoice&semantic=true'

# Text search.
curl -u admin:admin 'http://localhost:8080/index.php/apps/openregister/api/objects/search?query=invoice'
```

## Verify Everything Works

```bash
# Check PostgreSQL extensions.
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c "
SELECT name, default_version, installed_version 
FROM pg_available_extensions 
WHERE name IN ('vector', 'pg_trgm', 'btree_gin', 'btree_gist', 'uuid-ossp')
ORDER BY name;"

# Check indexes.
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c "
SELECT tablename, indexname, indexdef 
FROM pg_indexes 
WHERE schemaname = 'public' 
  AND (indexname LIKE '%embedding%' OR indexname LIKE '%trgm%')
ORDER BY tablename, indexname;"

# Check Nextcloud status.
docker exec -u 33 master-nextcloud-1 php occ status

# Check OpenRegister is enabled.
docker exec -u 33 master-nextcloud-1 php occ app:list | grep openregister
```

## What You Get

### PostgreSQL Extensions

- ✅ **pgvector** - Store and search AI embeddings (semantic search)
- ✅ **pg_trgm** - Fuzzy text matching and autocomplete
- ✅ **btree_gin** - Optimized indexing for complex queries
- ✅ **btree_gist** - Advanced indexing capabilities
- ✅ **uuid-ossp** - UUID generation

### Search Capabilities

- 🔍 **Vector Similarity Search** - Find semantically similar content
- 📝 **Full-Text Search** - Search across all text fields
- 🎯 **Fuzzy Matching** - Handle typos and variations
- ⚡ **Autocomplete** - Fast prefix and partial matching
- 🔗 **Hybrid Search** - Combine vector and text search

### No Longer Needed

- ❌ Solr
- ❌ Elasticsearch
- ❌ ZooKeeper
- ❌ Separate search infrastructure

## Container Overview

```
openregister-postgres     - PostgreSQL 16 with pgvector (port 5432)
nextcloud                 - Nextcloud with OpenRegister (port 8080)
openregister-ollama       - Local LLM for AI features (port 11434)
openregister-presidio-analyzer - PII detection (port 5001)
openregister-n8n          - Workflow automation (port 5678)
```

## Common Commands

```bash
# View logs.
docker-compose logs -f db
docker-compose logs -f nextcloud

# Restart services.
docker-compose restart

# Stop everything.
docker-compose down

# Stop and remove volumes (CAUTION: deletes data!).
docker-compose down -v

# Connect to PostgreSQL.
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud

# Connect to Nextcloud container.
docker exec -it -u 33 master-nextcloud-1 bash

# Run OCC commands.
docker exec -u 33 master-nextcloud-1 php occ <command>
```

## Useful PostgreSQL Commands

```sql
-- List all extensions.
\\dx

-- List all tables.
\\dt

-- Describe table.
\\d oc_openregister_objects

-- List all indexes.
\\di

-- Check database size.
SELECT pg_size_pretty(pg_database_size('nextcloud'));

-- Check table sizes.
SELECT 
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size
FROM pg_tables
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;

-- Check index usage.
SELECT 
    schemaname,
    tablename,
    indexname,
    idx_scan as scans,
    pg_size_pretty(pg_relation_size(indexrelid)) as size
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
ORDER BY idx_scan DESC;

-- Test vector operations.
SELECT '[1,2,3]'::vector <=> '[4,5,6]'::vector AS cosine_distance;

-- Test trigram similarity.
SELECT similarity('hello world', 'hello word') AS similarity_score;
```

## Development Mode

For active development with code changes:

```bash
# Use dev compose file.
docker-compose -f docker-compose.dev.yml up -d

# Watch for changes.
npm run watch

# View detailed logs (dev has more logging).
docker-compose -f docker-compose.dev.yml logs -f db
```

## Troubleshooting

### Extensions Not Found

```bash
# Verify PostgreSQL image.
docker exec openregister-postgres psql --version

# Should be: PostgreSQL 16.x

# Manually create extensions if needed.
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud << EOF
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
EOF
```

### Connection Refused

```bash
# Check PostgreSQL is running.
docker ps | grep postgres

# Check PostgreSQL is ready.
docker exec openregister-postgres pg_isready -U nextcloud

# Check Nextcloud can connect.
docker exec -u 33 master-nextcloud-1 php -r "
\$db = pg_connect('host=openregister-postgres dbname=nextcloud user=nextcloud password=!ChangeMe!');
var_dump(pg_connection_status(\$db) === PGSQL_CONNECTION_OK);
"
```

### Slow Search Performance

```sql
-- Check if indexes exist.
SELECT indexname FROM pg_indexes 
WHERE tablename = 'oc_openregister_objects';

-- Create missing indexes.
CREATE INDEX IF NOT EXISTS idx_objects_embedding 
ON oc_openregister_objects USING ivfflat (embedding vector_cosine_ops);

CREATE INDEX IF NOT EXISTS idx_objects_title_trgm 
ON oc_openregister_objects USING gin (title gin_trgm_ops);

-- Update statistics.
ANALYZE oc_openregister_objects;
```

## Resources

- 📖 [Full PostgreSQL Search Guide](website/docs/development/postgresql-search.md)
- 🔄 [Detailed Migration Guide](website/docs/development/postgresql-migration.md)
- 📋 [Migration Summary](POSTGRESQL_MIGRATION_SUMMARY.md)
- 🌐 [Documentation Site](https://openregisters.app/)

## Need Help?

- Email: info@conduction.nl
- Documentation: https://openregisters.app/
- Check logs: `docker-compose logs -f`

## Next Steps

1. **Configure AI Features**: Set up Ollama or OpenAI for semantic search
2. **Import Schemas**: Load your JSON schemas
3. **Create Objects**: Start storing data
4. **Test Search**: Try vector and text search
5. **Explore APIs**: Check the OpenAPI documentation

Happy searching! 🚀


