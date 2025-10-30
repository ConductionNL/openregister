# CLI Commands Reference

OpenRegister provides several CLI commands for administration, debugging, and maintenance tasks. All commands are executed using Nextcloud's `occ` command-line tool.

## Command Execution

### Basic Syntax
```bash
# From host machine (recommended)
docker exec -u 33 <nextcloud-container> php /var/www/html/occ <command>

# From within container
php /var/www/html/occ <command>
```

### Common Container Names
- **Development**: `master-nextcloud-1`
- **Production**: Usually `nextcloud` or similar

## SOLR Commands

### SOLR Debug Command
Comprehensive SOLR debugging and testing tool.

```bash
# Run all debug tests
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ openregister:solr:debug --all

# Show tenant information
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ openregister:solr:debug --tenant-info

# Test SOLR setup
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ openregister:solr:debug --setup

# Test connection
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ openregister:solr:debug --test-connection

# Check cores/collections
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ openregister:solr:debug --check-cores
```

**Output Examples:**
```
🔍 SOLR Debug Tool - OpenRegister Multi-Tenant
================================================
📋 Tenant Information
  Instance ID: oc6fgt938z8c
  Overwrite Host: http://nextcloud.local
  Generated Tenant ID: nc_f0e53393
  Base Core Name: openregister
  Tenant Specific Core: openregister_nc_f0e53393

🔧 Testing SOLR Setup
  SOLR Configuration:
    Host: nextcloud-dev-solr
    Port: 8983
    Path: /solr
    Core: openregister
    Scheme: http
✅ SOLR setup completed successfully
```

## Database Commands

### Standard Nextcloud Commands
```bash
# Database status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ db:add-missing-indices
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ db:add-missing-columns
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ db:convert-filecache-bigint

# Maintenance mode
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ maintenance:mode --on
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ maintenance:mode --off
```

## API Testing Commands

### Object Operations
```bash
# Create test object
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST \
  -H 'Content-Type: application/json' \
  -H 'OCS-APIREQUEST: true' \
  'http://localhost/index.php/apps/openregister/api/objects/19/105' \
  -d '{"naam": "Test Object", "beschrijving": "Test description"}'

# Get objects
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openregister/api/objects?register=19&schema=105'

# Delete object by UUID
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X DELETE \
  -H 'OCS-APIREQUEST: true' \
  'http://localhost/index.php/apps/openregister/api/objects/19/105/<uuid>'
```

### Bulk Import Testing
```bash
# Get object count before import
before_count=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openregister/api/objects?register=19&schema=105' | jq -r '.total // 0')
echo "Objects before: $before_count"

# Perform bulk import
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST \
  -H 'OCS-APIREQUEST: true' \
  'http://localhost/index.php/apps/openregister/api/import/19/105' \
  -F "file=@/var/www/html/apps-extra/openregister/lib/Settings/organisatie.csv"

# Check object count after import
after_count=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openregister/api/objects?register=19&schema=105' | jq -r '.total // 0')
echo "Objects after: $after_count"
echo "Imported: $(($after_count - $before_count))"
```

## SOLR Direct Testing

### Collection Queries
```bash
# Test SOLR collection directly
docker exec -u 33 master-nextcloud-1 curl -s \
  "http://nextcloud-dev-solr:8983/solr/openregister/select?q=*:*&rows=10&wt=json" \
  | jq -r '{total: .response.numFound, docs: [.response.docs[]? | {id: .id, name: .name}]}'

# Search for specific content
docker exec -u 33 master-nextcloud-1 curl -s \
  "http://nextcloud-dev-solr:8983/solr/openregister/select?q=name:test&wt=json" \
  | jq .response.docs

# Check SOLR admin APIs
docker exec -u 33 master-nextcloud-1 curl -s \
  "http://nextcloud-dev-solr:8983/solr/admin/collections?action=CLUSTERSTATUS&wt=json" \
  | jq .cluster.collections
```

## Database Inspection Commands

### Direct Database Access
```bash
# Get database connection info
docker exec -u 33 master-nextcloud-1 php -r "
\$config = include '/var/www/html/config/config.php';
echo 'DB_HOST=' . \$config['dbhost'] . PHP_EOL;
echo 'DB_NAME=' . \$config['dbname'] . PHP_EOL;
echo 'DB_USER=' . \$config['dbuser'] . PHP_EOL;
echo 'DB_PASSWORD=' . \$config['dbpassword'] . PHP_EOL;
"

# Connect to database
docker exec -u 33 master-nextcloud-1 mysql -h master-database-mysql-1 -u nextcloud -pnextcloud nextcloud

# Object count queries
docker exec -u 33 master-nextcloud-1 mysql -h master-database-mysql-1 -u nextcloud -pnextcloud nextcloud -e "
SELECT COUNT(*) as total_objects 
FROM oc_openregister_objects 
WHERE register = 19 AND schema = 105;
"

# Recent objects
docker exec -u 33 master-nextcloud-1 mysql -h master-database-mysql-1 -u nextcloud -pnextcloud nextcloud -e "
SELECT 
    LEFT(id, 8) as short_id,
    name,
    published IS NOT NULL as auto_published,
    created,
    published
FROM oc_openregister_objects 
WHERE register = 19 AND schema = 105 
ORDER BY created DESC 
LIMIT 5;
"
```

## Debugging Commands

### System Information
```bash
# PHP memory and settings
docker exec -u 33 master-nextcloud-1 php -r "
echo 'PHP Memory Limit: ' . ini_get('memory_limit') . PHP_EOL;
echo 'Current usage: ' . round(memory_get_usage(true)/1024/1024, 2) . 'MB' . PHP_EOL;
echo 'Peak usage: ' . round(memory_get_peak_usage(true)/1024/1024, 2) . 'MB' . PHP_EOL;
"

# Nextcloud status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:list | grep openregister

# Log monitoring
docker logs master-nextcloud-1 --since 10m | grep -i -E 'solr|openregister|error'
docker logs master-nextcloud-1 -f | grep openregister
```

### Performance Monitoring
```bash
# Memory usage during operations
docker stats master-nextcloud-1 --no-stream

# Container resource usage
docker exec master-nextcloud-1 top -bn1 | head -20

# Apache processes
docker exec master-nextcloud-1 ps aux | grep apache
```

## App Management Commands

### OpenRegister App Commands
```bash
# Enable/disable app
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable openregister
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:disable openregister

# App information
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:list | grep openregister
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:info openregister
```

## Development Workflow Commands

### Complete Testing Sequence
```bash
#!/bin/bash
# Complete SOLR and object testing workflow

echo "=== OpenRegister Development Testing Sequence ==="

# 1. Check system status
echo "1. System Status Check..."
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:list | grep openregister

# 2. SOLR debugging
echo "2. SOLR Debug Check..."
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ openregister:solr:debug --all

# 3. Test object creation
echo "3. Creating test object..."
uuid=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST -H 'Content-Type: application/json' -H 'OCS-APIREQUEST: true' \
  'http://localhost/index.php/apps/openregister/api/objects/19/105' \
  -d '{"naam": "CLI Test Object", "beschrijving": "Created via CLI test"}' | jq -r '.id')

echo "Created object: $uuid"

# 4. Check SOLR indexing
echo "4. Checking SOLR indexing..."
sleep 2  # Allow time for indexing
docker exec -u 33 master-nextcloud-1 curl -s \
  "http://nextcloud-dev-solr:8983/solr/openregister/select?q=*:*&wt=json" \
  | jq -r '{total: .response.numFound, sample: [.response.docs[]? | {id: .id, name: .name}] | .[0:3]}'

# 5. Test retrieval
echo "5. Testing object retrieval..."
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://localhost/index.php/apps/openregister/api/objects/19/105/$uuid" \
  | jq -r '{id: .id, name: .naam, created: ."@self".created}'

echo "=== Testing Complete ==="
```

## Troubleshooting Commands

### Common Issues
```bash
# Memory exhaustion
docker exec -u 33 master-nextcloud-1 php -d memory_limit=4G /var/www/html/occ <command>

# Permission issues
docker exec master-nextcloud-1 chown -R www-data:www-data /var/www/html/apps-extra/openregister

# Clear caches
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ maintenance:repair
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ files:scan --all

# Reset SOLR connection
docker restart <solr-container-name>
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ openregister:solr:debug --setup
```

## Security Notes

### Authentication Headers
Always include required headers for API calls:
- `-u 'admin:admin'` for basic authentication
- `-H 'OCS-APIREQUEST: true'` for OCS API requests
- `-H 'Content-Type: application/json'` for JSON payloads

### Container Access
- Use `-u 33` (www-data user) for PHP commands
- Use root access only when necessary for system changes
- Never expose database credentials in production logs

## Performance Optimization

### Memory Management

#### Permanent Memory Limit Increase
The Docker setup has been configured with 4GB PHP memory limit (matching production). To apply these changes:

```bash
# 1. Stop the Nextcloud container
docker-compose stop nextcloud

# 2. Start with new configuration
docker-compose up -d nextcloud

# 3. Verify new memory limit
docker exec -u 33 master-nextcloud-1 php -r "echo 'PHP Memory Limit: ' . ini_get('memory_limit') . PHP_EOL;"
```

**Expected Output:**
```
PHP Memory Limit: 4G
```

#### Temporary Memory Increase
```bash
# Increase PHP memory limit for single command
docker exec -u 33 master-nextcloud-1 php -d memory_limit=4G /var/www/html/occ <command>

# Monitor memory usage during operations
docker exec master-nextcloud-1 bash -c 'while true; do free -h; sleep 5; done' &
```

### Bulk Operations
```bash
# Process large imports in chunks
# Use pagination for large datasets
# Monitor system resources during bulk operations
```

## References

- [Nextcloud OCC Commands](https://docs.nextcloud.com/server/latest/admin_manual/occ_command.html)
- [OpenRegister API Documentation](./api-endpoints.md)
- [SOLR Search Engine](./solr-search-engine.md)
- [Development Setup](./development-setup.md)
