# Database Compatibility Testing

OpenRegister supports both **PostgreSQL** (recommended) and **MariaDB/MySQL** for maximum flexibility. This document explains how to test both database backends.

## Quick Start

### PostgreSQL (Default - Recommended)

PostgreSQL is the recommended database for production use, offering advanced features like vector search (pgvector) and full-text search (pg_trgm).

```bash
# Start with PostgreSQL (default)
docker-compose up -d

# Check status
docker-compose ps

# View logs
docker-compose logs -f nextcloud
```

### MariaDB (For Compatibility Testing)

MariaDB/MySQL support is maintained for backward compatibility and environments where PostgreSQL is not available.

```bash
# Start with MariaDB
docker-compose --profile mariadb up -d

# Check status
docker-compose --profile mariadb ps

# View logs
docker-compose --profile mariadb logs -f nextcloud-mariadb
```

## Switching Between Databases

### From PostgreSQL to MariaDB

```bash
# Stop and remove all containers
docker-compose down

# Remove volumes (WARNING: This deletes all data!)
docker volume rm openregister_db openregister_nextcloud openregister_config

# Start with MariaDB
docker-compose --profile mariadb up -d
```

### From MariaDB to PostgreSQL

```bash
# Stop and remove all containers
docker-compose --profile mariadb down

# Remove volumes (WARNING: This deletes all data!)
docker volume rm openregister_db openregister_nextcloud openregister_config

# Start with PostgreSQL
docker-compose up -d
```

## Running Integration Tests

### With PostgreSQL

```bash
# Start PostgreSQL stack
docker-compose up -d

# Wait for Nextcloud to be ready
docker-compose logs -f nextcloud

# Run Newman integration tests
docker exec -u 33 nextcloud newman run \
  /var/www/html/custom_apps/openregister/tests/integration/openregister-crud.postman_collection.json \
  --env-var "base_url=http://localhost" \
  --env-var "admin_user=admin" \
  --env-var "admin_password=admin" \
  --reporters cli
```

### With MariaDB

```bash
# Start MariaDB stack
docker-compose --profile mariadb up -d

# Wait for Nextcloud to be ready
docker-compose --profile mariadb logs -f nextcloud-mariadb

# Run Newman integration tests
docker exec -u 33 nextcloud newman run \
  /var/www/html/custom_apps/openregister/tests/integration/openregister-crud.postman_collection.json \
  --env-var "base_url=http://localhost" \
  --env-var "admin_user=admin" \
  --env-var "admin_password=admin" \
  --reporters cli
```

## Database Access

### PostgreSQL

```bash
# Access PostgreSQL CLI
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud

# Example queries
\dt                              # List tables
\d oc_openregister_objects      # Describe table
SELECT version();                # PostgreSQL version
```

### MariaDB

```bash
# Access MariaDB CLI
docker exec -it openregister-mariadb mysql -u nextcloud -p'!ChangeMe!' nextcloud

# Example queries
SHOW TABLES;                                    # List tables
DESCRIBE oc_openregister_objects;               # Describe table
SELECT VERSION();                               # MariaDB version
```

## Database Configuration Details

### PostgreSQL (Port 5432)

- **Image:** pgvector/pgvector:pg16
- **Extensions:** pg_trgm, vector
- **Features:** Vector search, full-text search, JSON operations
- **Optimizations:** Configured for high concurrency and performance

**Connection String:**
```
postgresql://nextcloud:!ChangeMe!@localhost:5432/nextcloud
```

### MariaDB (Port 3306)

- **Image:** mariadb:11.2
- **Character Set:** utf8mb4_unicode_ci
- **Features:** Standard SQL, JSON support (basic)
- **Optimizations:** InnoDB tuning for performance

**Connection String:**
```
mysql://nextcloud:!ChangeMe!@localhost:3306/nextcloud
```

## Feature Comparison

| Feature | PostgreSQL | MariaDB |
|---------|-----------|---------|
| Vector Search (pgvector) | ✅ Yes | ❌ No |
| Full-Text Search (native) | ✅ pg_trgm | ⚠️ Basic FULLTEXT |
| JSON Operations | ✅ Advanced | ⚠️ Basic |
| Performance | ✅ Excellent | ✅ Good |
| Type Strictness | ✅ Strict (safer) | ⚠️ Permissive |
| Production Ready | ✅ Recommended | ✅ Supported |

## Continuous Integration

For CI/CD pipelines, you can test both databases sequentially:

```bash
#!/bin/bash
# test-both-databases.sh

echo "=== Testing PostgreSQL ==="
docker-compose up -d
sleep 30  # Wait for initialization
# Run tests...
docker-compose down -v

echo "=== Testing MariaDB ==="
docker-compose --profile mariadb up -d
sleep 30  # Wait for initialization
# Run tests...
docker-compose --profile mariadb down -v
```

## Troubleshooting

### PostgreSQL Issues

```bash
# Check PostgreSQL logs
docker logs openregister-postgres

# Check if extensions are loaded
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c "SELECT * FROM pg_extension;"

# Reset PostgreSQL data
docker-compose down
docker volume rm openregister_db
docker-compose up -d
```

### MariaDB Issues

```bash
# Check MariaDB logs
docker logs openregister-mariadb

# Check character set
docker exec openregister-mariadb mysql -u nextcloud -p'!ChangeMe!' -e "SHOW VARIABLES LIKE 'character_set%';"

# Reset MariaDB data
docker-compose --profile mariadb down
docker volume rm openregister_db
docker-compose --profile mariadb up -d
```

## Known Differences

### Type Handling

**PostgreSQL:**
- Strict type checking (strings ≠ integers)
- Explicit casting required for type mismatches
- JSON columns have specific operators

**MariaDB:**
- Permissive type coercion (strings can be compared to integers)
- Implicit type conversion in most cases
- JSON stored as TEXT internally

### Date/Time Functions

**PostgreSQL:**
- `TO_CHAR(date, format)` for formatting
- `DATE_TRUNC()` for truncation
- Timezone-aware types

**MariaDB:**
- `DATE_FORMAT(date, format)` for formatting
- `DATE()`, `MONTH()`, etc. for extraction
- Timezone support limited

### Boolean Values

**PostgreSQL:**
- Native boolean type
- TRUE/FALSE literals

**MariaDB:**
- Stored as TINYINT(1)
- 1/0 values

## Best Practices

1. **Always test with PostgreSQL in development** - It catches more type errors early
2. **Run integration tests on both databases before releases**
3. **Use database-agnostic code** - Check platform and use appropriate SQL
4. **Document database-specific features** - If using pgvector, document MariaDB limitations
5. **Monitor query performance on both platforms** - Optimize for the most restrictive

## Additional Resources

- [PostgreSQL Documentation](https://www.postgresql.org/docs/)
- [MariaDB Documentation](https://mariadb.com/kb/en/)
- [Nextcloud Database Configuration](https://docs.nextcloud.com/server/latest/admin_manual/configuration_database/)
- [Docker Compose Profiles](https://docs.docker.com/compose/profiles/)


