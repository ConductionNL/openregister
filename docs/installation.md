# Installation Guide

This guide covers all installation methods for OpenRegister, from the Nextcloud App Store to Docker development environments.

## Prerequisites

### System Requirements

**Minimum Requirements:**
- PHP 8.1 or higher
- Nextcloud 28 or higher
- Database: PostgreSQL 12+ OR MariaDB 10.5+ / MySQL 8.0+
- 2GB RAM minimum (4GB+ recommended)
- 1GB disk space for the app

**Recommended Setup:**
- PHP 8.2+
- Nextcloud 31+
- PostgreSQL 16+ with pgvector and pg_trgm extensions
- 8GB RAM
- SSD storage

### Required PHP Extensions

```bash
# Core extensions
php-curl
php-gd
php-json
php-mbstring
php-xml
php-zip

# Database extensions (choose one)
php-pgsql    # For PostgreSQL (recommended)
php-mysql    # For MariaDB/MySQL
```

## Installation Methods

### Method 1: Nextcloud App Store (Recommended for Production)

The easiest way to install OpenRegister is directly from the Nextcloud App Store.

1. **Open Nextcloud:**
   - Log in as an administrator
   - Navigate to **Settings** → **Apps**

2. **Search and Install:**
   - Click on **"Featured apps"** or **"Integration"**
   - Search for **"OpenRegister"** or **"Open Register"**
   - Click **"Download and enable"**

3. **Verify Installation:**
   ```bash
   # Via command line
   php occ app:list | grep openregister
   
   # Should show:
   # openregister: 0.2.x enabled
   ```

4. **Access the App:**
   - Navigate to `https://your-nextcloud.com/index.php/apps/openregister`

### Method 2: Manual Installation from Release

For custom installations or when the App Store is unavailable.

1. **Download Latest Release:**
   ```bash
   cd /path/to/nextcloud/apps/
   wget https://github.com/ConductionNL/openregister/releases/latest/download/openregister.tar.gz
   tar -xzf openregister.tar.gz
   chown -R www-data:www-data openregister
   ```

2. **Enable the App:**
   ```bash
   cd /path/to/nextcloud
   sudo -u www-data php occ app:enable openregister
   ```

3. **Verify Installation:**
   ```bash
   sudo -u www-data php occ app:list | grep openregister
   ```

### Method 3: Development Installation (Docker Compose)

For development, testing, or evaluation purposes.

#### Quick Start

```bash
# Clone the repository
git clone https://github.com/ConductionNL/openregister.git
cd openregister

# Start with PostgreSQL (recommended)
docker-compose up -d

# Access Nextcloud
# URL: http://localhost:8080
# Username: admin
# Password: admin
```

#### Detailed Setup

**1. Clone and Navigate:**
```bash
git clone https://github.com/ConductionNL/openregister.git
cd openregister
```

**2. Choose Database Backend:**

**PostgreSQL (Recommended):**
```bash
docker-compose up -d
```

**MariaDB (For Compatibility Testing):**
```bash
docker-compose --profile mariadb up -d
```

**3. Wait for Initialization:**
```bash
# Check container status
docker-compose ps

# Watch Nextcloud logs
docker-compose logs -f nextcloud

# Wait for: "Nextcloud was successfully installed"
```

**4. Access Nextcloud:**
- URL: http://localhost:8080
- Username: `admin`
- Password: `admin`

**5. Enable OpenRegister:**
```bash
docker exec -u 33 nextcloud php occ app:enable openregister
```

**6. Verify Installation:**
```bash
docker exec -u 33 nextcloud php occ app:list | grep openregister
```

#### Docker Compose Services

The Docker setup includes:

| Service | Description | Port | Optional |
|---------|-------------|------|----------|
| **nextcloud** | Nextcloud application server | 8080 | Required |
| **db** | PostgreSQL database (pgvector) | 5432 | Required (default) |
| **db-mariadb** | MariaDB database | 3306 | Optional (profile: mariadb) |
| **ollama** | Local LLM inference (Llama, Mistral) | 11434 | Required |
| **presidio-analyzer** | PII detection and NER | 5001 | Required |
| **solr** | Search engine (legacy) | 8983 | Optional (profile: solr) |
| **elasticsearch** | Alternative search backend | 9200 | Optional (profile: elasticsearch) |
| **n8n** | Workflow automation | 5678 | Optional (profile: n8n) |

#### Optional Services

**Enable Solr:**
```bash
docker-compose --profile solr up -d
```

**Enable n8n Workflows:**
```bash
docker-compose --profile n8n up -d
# Access: http://localhost:5678
# Credentials: admin / admin
```

**Enable All Optional Services:**
```bash
docker-compose --profile solr --profile n8n --profile elasticsearch up -d
```

## Database Setup

### PostgreSQL (Recommended)

PostgreSQL offers advanced features like vector search (pgvector) and full-text search (pg_trgm).

#### Automatic Setup (Docker)

Extensions are automatically installed via `docker/postgres/init-extensions.sql`:
- ✅ **pgvector** - Vector similarity search
- ✅ **pg_trgm** - Trigram full-text search
- ✅ **btree_gin** - Optimized GIN indexing
- ✅ **btree_gist** - Optimized GiST indexing
- ✅ **uuid-ossp** - UUID generation

#### Manual Setup (Production)

```bash
# Connect to PostgreSQL as superuser
psql -U postgres

# Create database
CREATE DATABASE nextcloud;
CREATE USER nextcloud WITH PASSWORD 'your_secure_password';
GRANT ALL PRIVILEGES ON DATABASE nextcloud TO nextcloud;

# Connect to the database
\c nextcloud

# Install extensions
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
```

#### Verify Extensions

```bash
# Check installed extensions
psql -U nextcloud -d nextcloud -c "SELECT extname, extversion FROM pg_extension;"

# Expected output:
#   extname   | extversion
# ------------+------------
#  vector     | 0.8.1
#  pg_trgm    | 1.6
#  btree_gin  | 1.3
#  btree_gist | 1.7
#  uuid-ossp  | 1.1
```

#### Nextcloud Configuration

Add to `config/config.php`:

```php
'dbtype' => 'pgsql',
'dbname' => 'nextcloud',
'dbhost' => 'localhost',
'dbport' => '5432',
'dbuser' => 'nextcloud',
'dbpassword' => 'your_secure_password',
'dbtableprefix' => 'oc_',
```

### MariaDB / MySQL

MariaDB/MySQL are fully supported but lack advanced search features.

#### Manual Setup

```bash
# Connect to MariaDB/MySQL
mysql -u root -p

# Create database
CREATE DATABASE nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nextcloud'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON nextcloud.* TO 'nextcloud'@'localhost';
FLUSH PRIVILEGES;
```

#### Nextcloud Configuration

Add to `config/config.php`:

```php
'dbtype' => 'mysql',
'dbname' => 'nextcloud',
'dbhost' => 'localhost',
'dbport' => '3306',
'dbuser' => 'nextcloud',
'dbpassword' => 'your_secure_password',
'dbtableprefix' => 'oc_',
```

## Post-Installation Configuration

### 1. Basic Settings

Access **Settings** → **OpenRegister** → **Settings**:

- **Organization Name**: Your organization name
- **Default Register**: Default register for new objects
- **API Base URL**: Your Nextcloud URL

### 2. Create First Register

1. Navigate to **OpenRegister** → **Registers**
2. Click **"New Register"**
3. Fill in details:
   - **Title**: E.g., "Contacts"
   - **Description**: Purpose of the register
   - **Slug**: URL-friendly identifier (e.g., "contacts")
4. Click **"Save"**

### 3. Import or Create Schema

**Option A: Import from Schema.org**
1. Go to **Schemas** → **Import**
2. Select **"Schema.org"**
3. Search and select a schema (e.g., "Person")
4. Click **"Import"**

**Option B: Create Custom Schema**
1. Go to **Schemas** → **New Schema**
2. Define properties in JSON Schema format
3. Save the schema

### 4. Test the Installation

```bash
# Via command line
docker exec -u 33 nextcloud php occ openregister:test

# Via API
curl -u admin:admin \
  http://localhost:8080/index.php/apps/openregister/api/registers

# Expected: JSON list of registers
```

## AI Features Setup (Optional)

### Ollama Integration

OpenRegister can use Ollama for:
- Semantic search
- Content understanding
- AI-powered chat
- RAG (Retrieval Augmented Generation)

**1. Pull a Model:**
```bash
# Inside Docker
docker exec openregister-ollama ollama pull llama3.2

# Alternative models
docker exec openregister-ollama ollama pull mistral
docker exec openregister-ollama ollama pull codellama
```

**2. Verify Ollama:**
```bash
curl http://localhost:11434/api/tags
```

**3. Configure in OpenRegister:**
- Navigate to **Settings** → **OpenRegister** → **AI**
- Enable **"AI Features"**
- Set Ollama URL: `http://ollama:11434`
- Select model: `llama3.2`

### Presidio PII Detection

For automatic PII detection and anonymization:

**Verify Presidio:**
```bash
curl http://localhost:5001/health
```

**Configure in OpenRegister:**
- Navigate to **Settings** → **OpenRegister** → **Privacy**
- Enable **"PII Detection"**
- Set Presidio URL: `http://presidio-analyzer:5001`

## Troubleshooting

### Common Issues

#### App Not Showing After Installation

```bash
# Clear cache
php occ maintenance:mode --on
php occ app:disable openregister
php occ app:enable openregister
php occ maintenance:mode --off
```

#### Database Connection Errors

**PostgreSQL:**
```bash
# Check if PostgreSQL is running
docker-compose ps db

# Check logs
docker logs openregister-postgres

# Test connection
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c "SELECT version();"
```

**MariaDB:**
```bash
# Check if MariaDB is running
docker-compose --profile mariadb ps db-mariadb

# Check logs
docker logs openregister-mariadb

# Test connection
docker exec openregister-mariadb mysql -u nextcloud -p'!ChangeMe!' -e "SELECT VERSION();"
```

#### Extensions Not Loaded

```bash
# Check PostgreSQL extensions
docker exec openregister-postgres psql -U nextcloud -d nextcloud \
  -c "SELECT extname FROM pg_extension WHERE extname IN ('vector', 'pg_trgm');"

# If missing, recreate database
docker-compose down -v
docker-compose up -d
```

#### Port Already in Use

```bash
# Find process using port 8080
sudo lsof -i :8080

# Stop the process or change port in docker-compose.yml:
# ports:
#   - "8081:80"  # Change 8080 to 8081
```

### Getting Help

- **Documentation**: https://openregisters.app/
- **GitHub Issues**: https://github.com/ConductionNL/openregister/issues
- **Community Forum**: https://github.com/ConductionNL/openregister/discussions
- **Email Support**: support@conduction.nl

## Next Steps

After installation:

1. **Read the User Guide**: [User Documentation](./user-guide/getting-started.md)
2. **Explore Features**: [Feature Documentation](./features/)
3. **Set Up Access Control**: [Access Control Guide](./features/access-control.md)
4. **Configure Multi-Tenancy**: [Multi-Tenancy Guide](./features/multi-tenancy.md)
5. **API Documentation**: [API Reference](./api/)

## Upgrading

### From Nextcloud App Store

Updates are automatic via the Nextcloud update mechanism:
1. Navigate to **Settings** → **Apps**
2. Look for OpenRegister updates
3. Click **"Update"**

### From GitHub Release

```bash
# Backup first!
php occ maintenance:mode --on
cp -r apps/openregister apps/openregister.backup

# Download and extract new version
cd apps/
wget https://github.com/ConductionNL/openregister/releases/latest/download/openregister.tar.gz
rm -rf openregister
tar -xzf openregister.tar.gz
chown -R www-data:www-data openregister

# Run migrations
php occ upgrade
php occ maintenance:mode --off
```

### Docker Development

```bash
# Pull latest changes
git pull origin main

# Rebuild containers
docker-compose down
docker-compose up -d --build

# Run migrations
docker exec -u 33 nextcloud php occ upgrade
```

## Uninstallation

### Via App Store

1. Navigate to **Settings** → **Apps**
2. Find OpenRegister
3. Click **"Remove"**

### Via Command Line

```bash
# Disable and remove
php occ app:disable openregister
php occ app:remove openregister

# Optionally remove data (CAUTION: Cannot be undone!)
# This removes all registers, schemas, objects, and audit trails
php occ openregister:cleanup --force
```

### Docker Development

```bash
# Stop and remove all containers and volumes
docker-compose down -v

# Remove cloned repository
cd ..
rm -rf openregister
```

## Production Deployment Checklist

Before deploying to production:

- [ ] Use PostgreSQL 16+ with pgvector and pg_trgm
- [ ] Enable HTTPS/SSL
- [ ] Configure proper backup strategy
- [ ] Set strong passwords for database and Nextcloud admin
- [ ] Configure firewall rules
- [ ] Set up monitoring and logging
- [ ] Review PHP memory limits (4GB+ recommended)
- [ ] Enable Redis/Memcached for caching
- [ ] Configure proper file permissions
- [ ] Test backup and restore procedures
- [ ] Set up automated updates
- [ ] Review security settings
- [ ] Configure fail2ban or similar
- [ ] Set up log rotation

## Performance Optimization

### Database Tuning

**PostgreSQL:**
```sql
-- Add to postgresql.conf
shared_buffers = 4GB
effective_cache_size = 12GB
maintenance_work_mem = 1GB
work_mem = 32MB
max_connections = 200
```

**MariaDB:**
```ini
# Add to my.cnf
innodb_buffer_pool_size = 4G
innodb_log_file_size = 512M
max_connections = 200
query_cache_size = 64M
```

### PHP Tuning

Add to `php.ini`:
```ini
memory_limit = 4G
upload_max_filesize = 2G
post_max_size = 2G
max_execution_time = 300
opcache.enable = 1
opcache.memory_consumption = 256
```

### Nextcloud Tuning

Add to `config/config.php`:
```php
'memcache.local' => '\OC\Memcache\APCu',
'memcache.distributed' => '\OC\Memcache\Redis',
'redis' => [
  'host' => 'localhost',
  'port' => 6379,
],
```

## Support and Resources

- **Website**: https://openregisters.app/
- **Documentation**: https://openregisters.app/docs/
- **GitHub**: https://github.com/ConductionNL/openregister
- **License**: EUPL-1.2
- **Support**: support@conduction.nl


