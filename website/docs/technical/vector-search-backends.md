---
title: Vector Search Backends
sidebar_position: 1
description: Guide to vector search backend options and configuration
keywords:
  - Open Register
  - Vector Search
  - PostgreSQL
  - pgvector
  - Solr
  - Dense Vector
---

# Vector Search Backends

OpenRegister supports multiple vector search backends, allowing you to choose the optimal method based on your infrastructure and performance requirements.

## Overview

OpenRegister supports **three vector search backends**:

1. **PHP Cosine Similarity** (Default fallback)
2. **PostgreSQL + pgvector** (Database-level)
3. **Solr 9+ Dense Vector Search** (Search engine-level)

Users can select which backend to use based on what's available in their environment.

## Backend Comparison

| Backend | Speed | Setup Complexity | Scalability | Requirements |
|---------|-------|------------------|-------------|--------------|
| **PHP** | ⭐ | ⭐⭐⭐⭐⭐ (None) | ⭐ | None |
| **PostgreSQL + pgvector** | ⭐⭐⭐⭐ | ⭐⭐⭐ (Medium) | ⭐⭐⭐⭐ | PostgreSQL 11+, pgvector extension |
| **Solr 9+** | ⭐⭐⭐⭐⭐ | ⭐⭐ (If Solr exists) | ⭐⭐⭐⭐⭐ | Solr 9.0+, configured collection |

## Performance Comparison

**Test**: Search 10,000 vectors, return top 10 results

| Backend | Latency | Throughput | Memory | Index Time |
|---------|---------|------------|--------|------------|
| **PHP** | 10s | 1 req/s | Low | N/A |
| **PostgreSQL + pgvector** | 50ms | 50 req/s | Medium | Fast |
| **Solr 9+ (HNSW)** | 20ms | 100+ req/s | Medium-High | Medium |

## PHP Cosine Similarity (Default)

**Status**: Always available fallback

**How It Works**:
- Fetches vectors from database
- Calculates cosine similarity in PHP
- Sorts results in memory
- Returns top N matches

**Performance**:
- Suitable for small datasets (<500 vectors)
- Current temporary optimization: Limited to 500 most recent vectors
- Scales linearly: O(n) complexity

**Use When**:
- Small dataset (<500 vectors)
- No PostgreSQL or Solr available
- Testing/development environment

**Configuration**:
```json
{
  "vectorConfig": {
    "backend": "php"
  }
}
```

## PostgreSQL + pgvector

**Status**: Available when PostgreSQL with pgvector extension is installed

**What is pgvector?**
PostgreSQL extension that adds:
- Native vector data type: `vector(768)`
- Cosine similarity operator: `<=>`
- Vector indexing: HNSW, IVFFlat
- SQL-based vector search

**Performance**:
- 10-100x faster than PHP
- Database-level KNN search
- Optimal for medium-large datasets

**Requirements**:
- PostgreSQL version >= 11
- pgvector extension installed
- Vector column migration

**Installation**:

```sql
-- Install extension
CREATE EXTENSION IF NOT EXISTS vector;

-- Create optimized vector table
CREATE TABLE openregister_vectors (
    id SERIAL PRIMARY KEY,
    entity_type VARCHAR(50),
    entity_id VARCHAR(255),
    chunk_index INTEGER,
    total_chunks INTEGER,
    chunk_text TEXT,
    embedding vector(768),  -- Native vector type!
    embedding_model VARCHAR(100),
    embedding_dimensions INTEGER,
    metadata JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create HNSW index for fast similarity search
CREATE INDEX ON openregister_vectors 
USING hnsw (embedding vector_cosine_ops);
```

**Configuration**:
```json
{
  "vectorConfig": {
    "backend": "database"
  }
}
```

**Detection**:
The system automatically detects PostgreSQL + pgvector availability:
- Checks database platform name
- Queries `pg_extension` table for `vector` extension
- Shows availability status in UI

## Solr 9+ Dense Vector Search

**Status**: Available when Solr 9.0+ is configured

**Capabilities**:
Solr 9.0+ includes:
- **Dense Vector Field Type**: `DenseVectorField`
- **KNN Search**: K-Nearest Neighbors query parser
- **Similarity Functions**: Cosine, Dot Product, Euclidean
- **Indexing Algorithms**: HNSW (Hierarchical Navigable Small World)

**Performance**:
- Very fast distributed vector search
- Best for large-scale deployments
- 100-1000x faster than PHP at scale

**Requirements**:
- Solr version >= 9.0
- Collection with vector field configured
- Additional memory for HNSW index

**Schema Configuration**:

```xml
<fieldType name="knn_vector" class="solr.DenseVectorField" 
           vectorDimension="768" 
           similarityFunction="cosine" 
           knnAlgorithm="hnsw"/>

<field name="_embedding_" type="knn_vector" indexed="true" stored="true"/>
```

**Query Example**:

```json
{
  "q": "{!knn f=_embedding_ topK=10}[0.123, 0.456, ...]",
  "fl": "id,score"
}
```

**Configuration**:
```json
{
  "vectorConfig": {
    "backend": "solr",
    "solrCollection": "openregister_vectors",
    "solrField": "_embedding_"
  }
}
```

**Note**: The vector field name `_embedding_` is hardcoded as a reserved system field and cannot be changed by users.

**Detection**:
The system automatically detects Solr vector support:
- Checks Solr version >= 9.0
- Lists available collections
- Validates vector field configuration

## Configuration

### Settings Location

Vector search backend configuration is stored in LLM settings under `vectorConfig`:

```json
{
  "llm": {
    "vectorConfig": {
      "backend": "solr",
      "solrCollection": "openregister_vectors",
      "solrField": "_embedding_"
    }
  }
}
```

### Configuration Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `backend` | string | `'php'` | Vector search backend: 'php', 'database', or 'solr' |
| `solrCollection` | string\|null | `null` | Solr collection name for vector storage (required for Solr backend) |
| `solrField` | string | `'_embedding_'` | Solr field name for dense vectors (hardcoded, cannot be changed) |

### UI Configuration

Configure in **Settings → OpenRegister → LLM Configuration**:

1. Click **"Configure LLM"** button
2. Scroll to **"Vector Search Backend"** section
3. Select backend from dropdown:
   - PHP Cosine Similarity (🐌 Slow)
   - PostgreSQL + pgvector (⚡ Fast)
   - Solr 9+ Dense Vector (🚀 Very Fast)
4. If Solr selected:
   - Choose Solr collection from dropdown
   - Vector field name is automatically set to `_embedding_`
5. Click **"Save Configuration"**

### Backend Detection

The system automatically detects available backends:

**Database Detection**:
- Checks database platform (PostgreSQL vs MariaDB/MySQL)
- Queries for pgvector extension
- Shows availability status

**Solr Detection**:
- Checks Solr version >= 9.0
- Lists available collections
- Validates vector field configuration

## Recommended Setup

| Dataset Size | Recommended Backend | Reason |
|--------------|---------------------|--------|
| < 500 vectors | PHP | Simple, no setup |
| 500 - 10,000 | PostgreSQL + pgvector | Fast, integrated |
| 10,000+ | Solr 9+ | Best performance, scalability |

## Migration

### From PHP to PostgreSQL

1. Install PostgreSQL with pgvector extension
2. Migrate vector table to PostgreSQL
3. Update Nextcloud database configuration
4. Select "PostgreSQL + pgvector" in LLM Configuration
5. System will auto-detect and enable

### From PHP to Solr

1. Ensure Solr 9.0+ is running
2. Create or select Solr collection
3. Configure vector field in Solr schema (`_embedding_`)
4. Select "Solr 9+ Dense Vector" in LLM Configuration
5. Choose collection and save

### Switching Backends

You can switch backends at any time:
- Configuration changes take effect immediately
- Existing vectors remain in database
- New searches use selected backend
- No data migration required (vectors stored in database regardless of backend)

## Solr Integration Details

### Vector Storage in Existing Collections

Vectors are stored directly in existing Solr collections (fileCollection and objectCollection) rather than a separate vector collection:

**Files**: Vectors stored in `fileCollection` alongside file chunks  
**Objects**: Vectors stored in `objectCollection` alongside object data

This enables:
- Single source of truth for each entity
- Full document retrieval without additional lookups
- Atomic updates to existing documents

### Solr Document Structure

Vectors are stored as fields in existing Solr documents:

```json
{
  "id": "object_abc123_chunk_0",
  "entity_type_s": "object",
  "entity_id_s": "abc123",
  "chunk_index_i": 0,
  "chunk_text_txt": "This is the text that was embedded...",
  "_embedding_": [0.1, 0.2, 0.3, ...],
  "_embedding_model_": "text-embedding-ada-002",
  "_embedding_dim_": 1536
}
```

### KNN Query Syntax

The implementation uses Solr's KNN query parser:

```
{!knn f=_embedding_ topK=10}[0.1, 0.2, 0.3, ...]
```

**Benefits**:
- Very fast (millisecond range)
- Uses HNSW indexing algorithm
- Returns full documents with all metadata
- Supports filtering by entity type

### Configuration

No separate `solrCollection` field needed - uses existing `fileCollection` and `objectCollection` from Solr settings. The `solrField` is hardcoded to `_embedding_` (a reserved system field).

## Performance Monitoring

### Current Performance

**PHP Backend**:
- 279 vectors → ~300ms similarity calculation
- 1,000 vectors → ~1 second
- 10,000 vectors → ~10 seconds
- Plus embedding generation time (~1.5s)
- **Current optimization**: Limited to 500 most recent vectors

**PostgreSQL + pgvector**:
- 1K vectors → ~20ms
- 10K vectors → ~50ms
- 100K vectors → ~200ms
- Uses HNSW indexing

**Solr 9+ Dense Vector**:
- 1K vectors → ~20ms
- 10K vectors → ~15ms
- 100K vectors → ~30ms
- Uses HNSW indexing algorithm
- Distributed search across collections

### Performance Targets

| Metric | Current (PHP) | Target (PostgreSQL) | Best (Solr) |
|--------|-------------------|---------------------|---------------|
| **Search Time (1K vectors)** | 1s | 20ms | 10ms |
| **Search Time (10K vectors)** | 10s | 50ms | 15ms |
| **Search Time (100K vectors)** | 100s | 200ms | 30ms |
| **Indexing** | None | HNSW | ANN algorithms |
| **Scalability** | Poor | Good | Excellent |

### Performance Comparison

**Test**: Search 10,000 vectors, return top 10 results

| Backend | Latency | Throughput | Memory | Index Time |
|---------|---------|------------|--------|------------|
| **PHP** | 10s | 1 req/s | Low | N/A |
| **PostgreSQL + pgvector** | 50ms | 50 req/s | Medium | Fast |
| **Solr 9+ (HNSW)** | 20ms | 100+ req/s | Medium-High | Medium |

## Troubleshooting

### Backend Not Available

**PostgreSQL + pgvector**:
- Verify PostgreSQL is installed
- Check pgvector extension: `SELECT * FROM pg_extension WHERE extname = 'vector';`
- Install if missing: `CREATE EXTENSION vector;`

**Solr 9+**:
- Verify Solr version >= 9.0
- Check Solr is accessible
- Verify collection exists
- Check vector field is configured in schema

### Slow Performance

**PHP Backend**:
- Consider migrating to PostgreSQL or Solr
- Current optimization limits to 500 most recent vectors
- Performance scales linearly with vector count

**PostgreSQL**:
- Ensure HNSW index is created
- Check query execution plan
- Monitor database performance

**Solr**:
- Verify HNSW indexing is enabled
- Check collection health
- Monitor Solr performance metrics

## API Endpoints

### Get Database Info

```bash
GET /api/settings/database
```

Returns database type, version, and vector support.

### Get Solr Info

```bash
GET /api/settings/solr-info
```

Returns Solr availability, version, and collections.

## Related Documentation

- [Vectorization Architecture](./vectorization-architecture.md) - How vectors are generated and stored
- [Performance Optimization](../development/performance-optimization.md) - General performance tuning
- [Solr Setup Configuration](./solr-setup-configuration.md) - Solr configuration details

