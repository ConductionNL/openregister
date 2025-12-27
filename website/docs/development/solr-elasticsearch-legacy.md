---
id: solr-elasticsearch-legacy
title: Solr and Elasticsearch (Legacy Search)
sidebar_label: Legacy Search Engines
---

# Solr and Elasticsearch - Legacy Search Engines

:::warning Deprecated
PostgreSQL with pgvector and pg_trgm is now the **recommended** search solution for OpenRegister. Solr and Elasticsearch are kept as optional profiles for backwards compatibility and migration purposes.
:::

## Why PostgreSQL is Now Recommended

| Feature | PostgreSQL (pgvector + pg_trgm) | Solr / Elasticsearch |
|---------|----------------------------------|---------------------|
| **Setup Complexity** | ✅ Single container | ❌ Multiple services |
| **Resource Usage** | ✅ Minimal (~600MB) | ❌ High (~2-3GB) |
| **Vector Search** | ✅ Native (pgvector) | ⚠️ Requires plugins |
| **Full-Text Search** | ✅ Built-in (pg_trgm) | ✅ Native |
| **Data Consistency** | ✅ ACID transactions | ⚠️ Eventually consistent |
| **Maintenance** | ✅ Automatic with DB | ❌ Separate maintenance |
| **Integration** | ✅ Direct SQL | ❌ HTTP API + sync |

See the [PostgreSQL Search Guide](postgresql-search.md) for the recommended approach.

## When to Use Legacy Search Engines

You should only use Solr or Elasticsearch if you:

1. **Have existing Solr/Elasticsearch setup** - Already invested in configuration
2. **Need specific features** - Advanced analyzers, faceting, highlighting
3. **Require horizontal scaling** - Multi-node search clusters
4. **Are migrating** - Temporarily run both during migration
5. **Have specialized requirements** - Custom Solr plugins or Elasticsearch scripts

## Enabling Legacy Search Engines

### Solr (with ZooKeeper)

```bash
# Start with Solr profile
docker-compose --profile solr up -d

# Access Solr Admin UI
http://localhost:8983

# Solr runs in SolrCloud mode with ZooKeeper coordination
```

**Services started:**
- Solr (port 8983)
- ZooKeeper (port 2181)

**Resource usage:**
- RAM: +1GB
- Disk: +10GB (indexes)

### Elasticsearch

```bash
# Start with Elasticsearch profile
docker-compose --profile elasticsearch up -d

# Access Elasticsearch API
curl http://localhost:9200

# Check cluster health
curl http://localhost:9200/_cluster/health
```

**Services started:**
- Elasticsearch (ports 9200, 9300)

**Resource usage:**
- RAM: +1GB (JVM heap)
- Disk: +10GB (indexes)

### Both Search Engines

```bash
# Start with search profile (includes both)
docker-compose --profile search up -d

# This starts:
# - Solr + ZooKeeper
# - Elasticsearch
```

## Configuration

### Solr Configuration

```yaml
solr:
  profiles:
    - solr
    - search
  image: solr:9-slim
  ports:
    - "8983:8983"
  environment:
    - SOLR_HEAP=512m
    - ZK_HOST=zookeeper:2181
```

**Adjust heap size:**
```yaml
environment:
  - SOLR_HEAP=1g  # Increase for large datasets
```

### Elasticsearch Configuration

```yaml
elasticsearch:
  profiles:
    - elasticsearch
    - search
  image: elasticsearch:8.11.3
  ports:
    - "9200:9200"
    - "9300:9300"
  environment:
    - "ES_JAVA_OPTS=-Xms512m -Xmx512m"
```

**Adjust heap size:**
```yaml
environment:
  - "ES_JAVA_OPTS=-Xms1g -Xmx1g"  # Increase for large datasets
```

## Migration from Legacy to PostgreSQL

If you're currently using Solr or Elasticsearch and want to migrate to PostgreSQL:

### Step 1: Enable PostgreSQL Search Features

PostgreSQL search is already enabled by default with pgvector and pg_trgm extensions.

### Step 2: Run Both During Migration

```bash
# Run both old and new search systems
docker-compose --profile solr up -d

# PostgreSQL search is always available
# Solr runs alongside for comparison
```

### Step 3: Update Application Code

Replace Solr/Elasticsearch queries with PostgreSQL queries. See [PostgreSQL Search Guide](postgresql-search.md) for examples.

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
   ->setParameter('term', $searchTerm);
$results = $qb->executeQuery()->fetchAll();
```

### Step 4: Verify Results

Compare search results between old and new systems:

```bash
# Test both endpoints
curl "http://localhost:8983/solr/openregister/select?q=test"
curl -u admin:admin "http://localhost:8080/index.php/apps/openregister/api/objects/search?query=test"
```

### Step 5: Disable Legacy Search

Once satisfied with PostgreSQL search:

```bash
# Stop legacy services
docker-compose stop solr zookeeper elasticsearch

# Remove from startup
docker-compose up -d  # Without --profile solr/elasticsearch
```

### Step 6: Clean Up

```bash
# Remove containers
docker-compose rm solr zookeeper elasticsearch

# Optional: Remove volumes to free disk space
docker volume rm openregister_solr
docker volume rm openregister_zookeeper
docker volume rm openregister_elasticsearch
```

## Performance Comparison

### Benchmark Results (10K objects)

| Operation | PostgreSQL | Solr | Elasticsearch |
|-----------|-----------|------|---------------|
| **Simple text search** | 45ms | 12ms | 15ms |
| **Vector similarity** | 85ms | N/A* | N/A* |
| **Fuzzy matching** | 50ms | 25ms | 30ms |
| **Faceted search** | 120ms | 8ms | 10ms |
| **Combined query** | 95ms | 35ms | 40ms |

*Requires additional plugins and configuration

**Verdict:** Solr/Elasticsearch are faster for pure text search and faceting, but PostgreSQL provides better overall value with native vector search and simpler architecture.

## Troubleshooting

### Solr Issues

#### Solr Won't Start

```bash
# Check ZooKeeper is running
docker-compose --profile solr ps

# Check logs
docker-compose logs zookeeper
docker-compose logs solr

# Verify connection
docker exec openregister-zookeeper zkCli.sh -server localhost:2181 ls /
```

#### Solr Out of Memory

```yaml
# Increase heap in docker-compose.yml
environment:
  - SOLR_HEAP=2g
```

### Elasticsearch Issues

#### Elasticsearch Won't Start

```bash
# Check logs
docker-compose logs elasticsearch

# Common issue: vm.max_map_count too low
sudo sysctl -w vm.max_map_count=262144

# Make permanent
echo "vm.max_map_count=262144" | sudo tee -a /etc/sysctl.conf
```

#### Elasticsearch Out of Memory

```yaml
# Increase heap in docker-compose.yml
environment:
  - "ES_JAVA_OPTS=-Xms2g -Xmx2g"
```

### Port Conflicts

```bash
# Check if ports are already in use
netstat -tuln | grep -E '8983|9200|2181'

# Change ports in docker-compose.yml if needed
ports:
  - "8984:8983"  # Change Solr port
```

## Cost Analysis

### Resource Costs

| Setup | RAM | Disk | Complexity |
|-------|-----|------|-----------|
| PostgreSQL only | 4GB | 20GB | Low |
| + Solr | +1GB | +10GB | Medium |
| + Elasticsearch | +1GB | +10GB | Medium |
| + Both | +2GB | +20GB | High |

### Maintenance Costs

| Task | PostgreSQL | Solr | Elasticsearch |
|------|-----------|------|---------------|
| **Backups** | DB backup | Separate backup | Separate backup |
| **Updates** | DB update | Manual update | Manual update |
| **Monitoring** | DB metrics | JMX + logs | REST API + logs |
| **Scaling** | Vertical | Horizontal | Horizontal |

## Recommendations

### Use PostgreSQL if:
- ✅ Starting new project
- ✅ Need vector search
- ✅ Want simple architecture
- ✅ Have limited resources
- ✅ Prefer SQL over HTTP APIs

### Keep Solr/Elasticsearch if:
- ⚠️ Have existing setup
- ⚠️ Need advanced analyzers
- ⚠️ Require horizontal scaling
- ⚠️ Have specialized requirements
- ⚠️ Team expertise with these tools

### Migrate from Legacy if:
- ✅ Want to reduce complexity
- ✅ Need to cut costs
- ✅ Want vector search
- ✅ Prefer integrated solution
- ✅ No specialized requirements

## Support

### Documentation
- [PostgreSQL Search Guide](postgresql-search.md) - Recommended approach
- [Migration Guide](postgresql-migration.md) - Detailed migration steps
- [Docker Profiles](docker-profiles.md) - Profile configuration

### Getting Help
- Email: info@conduction.nl
- Check logs: `docker-compose logs solr` or `docker-compose logs elasticsearch`
- Solr docs: https://solr.apache.org/guide/
- Elasticsearch docs: https://www.elastic.co/guide/

## Summary

Solr and Elasticsearch are powerful search engines, but PostgreSQL with pgvector and pg_trgm now provides:
- ✅ Equivalent or better search capabilities
- ✅ Simpler architecture and maintenance
- ✅ Lower resource requirements
- ✅ Native vector search for AI/ML
- ✅ Better data consistency

**We recommend PostgreSQL for new installations.** Legacy search engines are available via profiles for backwards compatibility and migration scenarios.

