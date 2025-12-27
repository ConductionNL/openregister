---
id: postgresql-migration
title: Migrating from MySQL/MariaDB to PostgreSQL
sidebar_label: PostgreSQL Migration
---

# PostgreSQL Migration Guide

This guide helps you migrate from MySQL/MariaDB to PostgreSQL with pgvector and pg_trgm extensions, eliminating the need for external search engines.

## Why Migrate to PostgreSQL?

### Benefits

1. **Unified Search Solution**
   - Vector search (pgvector) for semantic/AI-powered search
   - Full-text search (pg_trgm) for fuzzy and partial matching
   - No separate Solr or Elasticsearch infrastructure needed

2. **Reduced Complexity**
   - Single database instead of DB + search engine
   - Simplified deployment and maintenance
   - Lower resource requirements

3. **Better Performance**
   - Native SQL queries for search
   - Optimized indexes (GIN, GiST, IVFFlat)
   - Reduced data synchronization overhead

4. **Enhanced Capabilities**
   - ACID-compliant search operations
   - Transactional consistency
   - Advanced PostgreSQL features (JSON, arrays, etc.)

### What You Lose

- Horizontal scaling of search (Solr/Elasticsearch clusters)
- Some specialized search features of dedicated search engines
- Existing Solr/Elasticsearch configurations

### What You Gain

- Vector similarity search for AI/ML applications
- Simpler architecture and deployment
- Lower infrastructure costs
- Integrated storage for embeddings
- Better data consistency

## Migration Overview

```mermaid
graph TB
    A[Current: MySQL + Solr] --> B[Backup Data]
    B --> C[Setup PostgreSQL]
    C --> D[Enable Extensions]
    D --> E[Export from MySQL]
    E --> F[Import to PostgreSQL]
    F --> G[Update Nextcloud Config]
    G --> H[Create Search Indexes]
    H --> I[Generate Embeddings]
    I --> J[Test Search]
    J --> K[Verify & Cleanup]
    
    style A fill:#ffe6e6
    style K fill:#d4edda
    style C fill:#e1f5ff
    style D fill:#e1f5ff
    style H fill:#e1f5ff
```

## Prerequisites

- OpenRegister running on MySQL/MariaDB
- Docker or PostgreSQL 12+ available
- Sufficient disk space for data export/import
- Downtime window for migration
- Backup of existing data

## Step 1: Backup Current Data

### Backup MySQL Database

```bash
# Full database backup.
docker exec master-database-mysql-1 mysqldump \\
  -u nextcloud -pnextcloud nextcloud \\
  > nextcloud_backup_$(date +%Y%m%d).sql

# Verify backup.
ls -lh nextcloud_backup_*.sql
```

### Backup Nextcloud Files

```bash
# Backup Nextcloud data directory.
docker exec master-nextcloud-1 tar czf /tmp/nextcloud_data.tar.gz \\
  -C /var/www/html data config

# Copy backup out of container.
docker cp master-nextcloud-1:/tmp/nextcloud_data.tar.gz ./
```

### Export Solr Indexes (Optional)

If you want to preserve search history:

```bash
# Export Solr data (for reference).
docker exec openregister-solr curl \\
  'http://localhost:8983/solr/openregister/select?q=*:*&rows=10000' \\
  > solr_export.json
```

## Step 2: Setup PostgreSQL with Extensions

### Option A: Using Docker Compose (Recommended)

The updated docker-compose files already include PostgreSQL:

```bash
# Stop current services.
docker-compose down

# Pull latest configuration.
git pull origin main

# Start PostgreSQL service.
docker-compose up -d db

# Wait for database to be ready.
docker exec openregister-postgres pg_isready -U nextcloud
```

Verify extensions:

```bash
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c '\\dx'
```

Expected output:
```
   Name     | Version |   Schema   |                    Description
------------+---------+------------+---------------------------------------------------
 btree_gin  | 1.3     | public     | support for indexing common datatypes in GIN
 btree_gist | 1.7     | public     | support for indexing common datatypes in GiST
 pg_trgm    | 1.6     | public     | text similarity measurement and index searching
 uuid-ossp  | 1.1     | public     | generate universally unique identifiers (UUIDs)
 vector     | 0.5.1   | public     | vector data type and ivfflat access methods
```

### Option B: Manual PostgreSQL Installation

If not using Docker:

```bash
# Install PostgreSQL 16.
sudo apt-get install postgresql-16 postgresql-contrib-16

# Install pgvector extension.
cd /tmp
git clone https://github.com/pgvector/pgvector.git
cd pgvector
make
sudo make install

# Restart PostgreSQL.
sudo systemctl restart postgresql

# Create database and user.
sudo -u postgres psql
```

```sql
CREATE DATABASE nextcloud;
CREATE USER nextcloud WITH PASSWORD 'your_password';
GRANT ALL PRIVILEGES ON DATABASE nextcloud TO nextcloud;
\\q
```

Enable extensions:

```bash
sudo -u postgres psql -d nextcloud
```

```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
```

## Step 3: Export Data from MySQL

### Using mysqldump with PostgreSQL Compatibility

```bash
# Export with PostgreSQL-compatible format.
docker exec master-database-mysql-1 mysqldump \\
  -u nextcloud -pnextcloud \\
  --compatible=postgresql \\
  --default-character-set=utf8mb4 \\
  --skip-add-locks \\
  --skip-comments \\
  --skip-set-charset \\
  --no-create-info \\
  nextcloud > mysql_data_only.sql
```

### Alternative: Use Migration Tool

For complex migrations, use pgloader:

```bash
# Install pgloader.
sudo apt-get install pgloader

# Create migration script.
cat > migrate.load << 'EOF'
LOAD DATABASE
  FROM mysql://nextcloud:nextcloud@master-database-mysql-1/nextcloud
  INTO postgresql://nextcloud:!ChangeMe!@openregister-postgres/nextcloud

WITH include drop, create tables, create indexes, reset sequences

SET maintenance_work_mem to '256MB',
    work_mem to '16MB'

CAST type datetime to timestamptz
     drop default drop not null using zero-dates-to-null,
     type date drop not null drop default using zero-dates-to-null;
EOF

# Run migration.
pgloader migrate.load
```

## Step 4: Update Nextcloud Configuration

### Update config.php

```bash
# Edit Nextcloud config.
docker exec -u 33 master-nextcloud-1 vi /var/www/html/config/config.php
```

Change database configuration:

```php
'dbtype' => 'pgsql',  // Changed from 'mysql'.
'dbname' => 'nextcloud',
'dbhost' => 'openregister-postgres',  // Changed from 'master-database-mysql-1'.
'dbport' => '5432',  // Changed from '3306'.
'dbuser' => 'nextcloud',
'dbpassword' => '!ChangeMe!',
'dbtableprefix' => 'oc_',
```

### Update Environment Variables

If using Docker Compose, update `.env` or docker-compose environment:

```yaml
environment:
  - POSTGRES_DB=nextcloud
  - POSTGRES_USER=nextcloud
  - POSTGRES_PASSWORD=!ChangeMe!
  - POSTGRES_HOST=openregister-postgres
```

## Step 5: Create Search Indexes

### Add Vector Columns

```sql
-- Connect to database.
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud

-- Add vector column to objects table.
ALTER TABLE oc_openregister_objects 
ADD COLUMN IF NOT EXISTS embedding vector(1536);

-- Add vector column to file chunks table.
ALTER TABLE oc_openregister_file_chunks 
ADD COLUMN IF NOT EXISTS embedding vector(1536);

-- Create vector similarity index.
CREATE INDEX IF NOT EXISTS idx_objects_embedding 
ON oc_openregister_objects 
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);

CREATE INDEX IF NOT EXISTS idx_chunks_embedding 
ON oc_openregister_file_chunks 
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);
```

### Create Full-Text Search Indexes

```sql
-- Trigram indexes for title and description.
CREATE INDEX IF NOT EXISTS idx_objects_title_trgm 
ON oc_openregister_objects 
USING gin (title gin_trgm_ops);

CREATE INDEX IF NOT EXISTS idx_objects_description_trgm 
ON oc_openregister_objects 
USING gin (description gin_trgm_ops);

-- Composite full-text search index.
CREATE INDEX IF NOT EXISTS idx_objects_fulltext 
ON oc_openregister_objects 
USING gin (to_tsvector('english', 
    COALESCE(title, '') || ' ' || COALESCE(description, '')));

-- Optimize index building.
VACUUM ANALYZE oc_openregister_objects;
VACUUM ANALYZE oc_openregister_file_chunks;
```

## Step 6: Generate Embeddings

### For Existing Objects

```bash
# Run embedding generation command.
docker exec -u 33 master-nextcloud-1 php occ openregister:generate-embeddings \\
  --batch-size=100 \\
  --all-schemas

# Monitor progress.
docker logs -f master-nextcloud-1 | grep 'embedding'
```

### For Existing Files

```bash
# Generate file embeddings.
docker exec -u 33 master-nextcloud-1 php occ openregister:generate-file-embeddings \\
  --batch-size=50 \\
  --all-files

# This will:
# 1. Extract text from files.
# 2. Chunk text into smaller portions.
# 3. Generate embeddings for each chunk.
# 4. Store embeddings in PostgreSQL.
```

## Step 7: Verify Migration

### Test Database Connection

```bash
# Test PostgreSQL connection.
docker exec -u 33 master-nextcloud-1 php occ maintenance:mode --off

# Check Nextcloud status.
docker exec -u 33 master-nextcloud-1 php occ status
```

Expected output:
```
  - installed: true
  - version: 28.0.0.0
  - versionstring: 28.0.0
  - edition: 
  - maintenance: false
  - needsDbUpgrade: false
  - productname: Nextcloud
  - extendedSupport: false
```

### Test Search Functionality

#### Vector Search Test

```bash
# Test semantic search API.
docker exec master-nextcloud-1 curl -s -u 'admin:admin' \\
  -H 'Content-Type: application/json' \\
  'http://localhost/index.php/apps/openregister/api/objects/search?query=document%20management&semantic=true' \\
  | jq .
```

#### Text Search Test

```bash
# Test full-text search.
docker exec master-nextcloud-1 curl -s -u 'admin:admin' \\
  -H 'Content-Type: application/json' \\
  'http://localhost/index.php/apps/openregister/api/objects/search?query=invoice' \\
  | jq .
```

### Verify Search Indexes

```sql
-- Check index usage.
SELECT schemaname, tablename, indexname, idx_scan, idx_tup_read
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
  AND tablename IN ('oc_openregister_objects', 'oc_openregister_file_chunks')
ORDER BY idx_scan DESC;

-- Check vector index size.
SELECT 
    schemaname,
    tablename,
    indexname,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_stat_user_indexes
WHERE indexname LIKE '%embedding%';
```

### Performance Comparison

Run benchmark queries:

```sql
-- Benchmark vector search.
EXPLAIN ANALYZE
SELECT id, title, embedding <=> '[0.1,0.2,...]'::vector AS distance
FROM oc_openregister_objects
ORDER BY distance
LIMIT 10;

-- Benchmark text search.
EXPLAIN ANALYZE
SELECT id, title, similarity(title, 'search term') AS score
FROM oc_openregister_objects
WHERE title % 'search term'
ORDER BY score DESC
LIMIT 20;
```

## Step 8: Update Application Code

### Remove Solr Dependencies

Update `composer.json`:

```json
{
  "require": {
    "solarium/solarium": "^6.3",  // REMOVE THIS
  }
}
```

Run:

```bash
composer remove solarium/solarium
```

### Update Search Service

Replace Solr calls with PostgreSQL queries. See the [PostgreSQL Search Guide](postgresql-search.md) for implementation details.

Example transformation:

**Before (Solr):**
```php
$query = $this->solrClient->createSelect();
$query->setQuery('title:' . $searchTerm);
$results = $this->solrClient->select($query);
```

**After (PostgreSQL):**
```php
$qb = $this->db->getQueryBuilder();
$qb->select(['id', 'title'])
   ->from('openregister_objects')
   ->where('title % :term')
   ->orderBy('similarity(title, :term)', 'DESC')
   ->setParameter('term', $searchTerm)
   ->setMaxResults(50);
$results = $qb->executeQuery()->fetchAll();
```

## Step 9: Cleanup

### Remove Old Services

```bash
# Stop and remove Solr/Elasticsearch containers.
docker stop openregister-solr openregister-elasticsearch openregister-zookeeper
docker rm openregister-solr openregister-elasticsearch openregister-zookeeper

# Remove volumes (if desired).
docker volume rm openregister_solr openregister_elasticsearch openregister_zookeeper
```

### Remove MySQL (After Verification)

Only after confirming PostgreSQL works correctly:

```bash
# Stop MySQL container.
docker stop master-database-mysql-1

# Keep backup for a few weeks before removing.
# docker rm master-database-mysql-1
# docker volume rm openregister_db
```

### Update Documentation

Update internal documentation and runbooks to reflect the PostgreSQL architecture.

## Troubleshooting

### Database Connection Issues

```bash
# Check PostgreSQL is running.
docker exec openregister-postgres pg_isready

# Check Nextcloud can connect.
docker exec -u 33 master-nextcloud-1 php -r "\\$db = pg_connect('host=openregister-postgres dbname=nextcloud user=nextcloud password=!ChangeMe!'); var_dump(pg_connection_status(\\$db) === PGSQL_CONNECTION_OK);"
```

### Extension Not Available

```bash
# Verify pgvector installation.
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c "SELECT * FROM pg_available_extensions WHERE name IN ('vector', 'pg_trgm');"

# If missing, check container image.
docker exec openregister-postgres psql --version
```

### Slow Search Performance

```sql
-- Check if indexes are being used.
EXPLAIN (ANALYZE, BUFFERS)
SELECT * FROM oc_openregister_objects
WHERE title % 'search term'
ORDER BY similarity(title, 'search term') DESC
LIMIT 20;

-- Rebuild indexes if needed.
REINDEX INDEX CONCURRENTLY idx_objects_title_trgm;

-- Update statistics.
ANALYZE oc_openregister_objects;
```

### Embedding Generation Fails

```bash
# Check AI service configuration.
docker exec -u 33 master-nextcloud-1 php occ config:list openregister

# Test embedding API.
docker exec master-nextcloud-1 curl -s http://localhost:11434/api/embeddings \\
  -d '{"model":"nomic-embed-text","prompt":"test"}'

# Check logs.
docker logs master-nextcloud-1 | grep -i embedding
```

### Data Inconsistencies

```sql
-- Check for NULL embeddings.
SELECT COUNT(*) as objects_without_embeddings
FROM oc_openregister_objects
WHERE embedding IS NULL;

-- Re-generate if needed.
docker exec -u 33 master-nextcloud-1 php occ openregister:generate-embeddings --force
```

## Rollback Plan

If migration fails, you can rollback:

### Quick Rollback

```bash
# 1. Stop PostgreSQL.
docker-compose stop db

# 2. Restore MySQL backup.
docker-compose up -d db
docker exec -i master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud < nextcloud_backup_YYYYMMDD.sql

# 3. Restore old config.php.
docker cp config.php.backup master-nextcloud-1:/var/www/html/config/config.php

# 4. Restart services.
docker-compose restart
```

### Full Rollback

```bash
# Restore from complete backup.
docker-compose down
docker volume rm openregister_db openregister_nextcloud
docker-compose up -d
# Restore data and config from backups.
```

## Post-Migration Tasks

### Monitor Performance

```sql
-- Create monitoring view.
CREATE VIEW search_performance AS
SELECT 
    schemaname,
    tablename,
    indexname,
    idx_scan as index_scans,
    idx_tup_read as tuples_read,
    idx_tup_fetch as tuples_fetched
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
ORDER BY idx_scan DESC;

-- Check regularly.
SELECT * FROM search_performance;
```

### Optimize Configuration

```sql
-- Tune for your workload.
ALTER SYSTEM SET shared_buffers = '512MB';
ALTER SYSTEM SET effective_cache_size = '2GB';
ALTER SYSTEM SET maintenance_work_mem = '128MB';
ALTER SYSTEM SET work_mem = '8MB';

-- Reload configuration.
SELECT pg_reload_conf();
```

### Schedule Maintenance

Create cron job for regular maintenance:

```bash
# Add to crontab.
0 2 * * 0 docker exec openregister-postgres psql -U nextcloud -d nextcloud -c "VACUUM ANALYZE;"
```

## Migration Checklist

- [ ] Backup MySQL database
- [ ] Backup Nextcloud files and config
- [ ] Setup PostgreSQL with extensions
- [ ] Verify extensions installed
- [ ] Export data from MySQL
- [ ] Import data to PostgreSQL
- [ ] Update config.php
- [ ] Test database connection
- [ ] Create vector columns
- [ ] Create search indexes
- [ ] Generate embeddings for objects
- [ ] Generate embeddings for files
- [ ] Test vector search
- [ ] Test full-text search
- [ ] Test API endpoints
- [ ] Update application code
- [ ] Remove Solr/Elasticsearch services
- [ ] Monitor performance
- [ ] Update documentation
- [ ] Remove MySQL after verification period

## Need Help?

If you encounter issues during migration:

1. Check the [PostgreSQL Search Guide](postgresql-search.md)
2. Review Docker logs: `docker logs master-nextcloud-1`
3. Check PostgreSQL logs: `docker logs openregister-postgres`
4. Contact support: info@conduction.nl

## Summary

Migrating to PostgreSQL provides:
- ✅ Simplified architecture (no separate search engine)
- ✅ Native vector search capabilities
- ✅ Better full-text search with trigrams
- ✅ Reduced infrastructure costs
- ✅ Improved data consistency
- ✅ Modern PostgreSQL features

The migration process is straightforward with proper planning and testing. Allow for adequate downtime and always maintain backups during the transition.


