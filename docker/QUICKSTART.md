# Docker Compose Quick Start

Get OpenRegister running in under 5 minutes with Docker Compose!

## Prerequisites

- Docker 20.10 or higher
- Docker Compose 2.0 or higher
- 8GB RAM available
- 10GB free disk space

**Check your versions:**
```bash
docker --version
docker-compose --version
```

## 🚀 Quick Start (30 seconds)

```bash
# 1. Clone the repository
git clone https://github.com/ConductionNL/openregister.git
cd openregister

# 2. Start the services
docker-compose up -d

# 3. Wait for initialization (check logs)
docker-compose logs -f nextcloud
# Wait for: "Nextcloud was successfully installed"
# Press Ctrl+C to exit logs

# 4. Access Nextcloud
# Open: http://localhost:8080
# Username: admin
# Password: admin
```

**That's it!** OpenRegister is now running with:
- ✅ Nextcloud 
- ✅ PostgreSQL with pgvector + pg_trgm
- ✅ Ollama (local AI)
- ✅ Presidio (PII detection)
- ✅ OpenRegister app enabled

## First Steps

### 1. Access OpenRegister

Navigate to: http://localhost:8080/index.php/apps/openregister

### 2. Create Your First Register

```bash
# Via command line
docker exec -u 33 nextcloud php occ openregister:register:create \
  --title="My First Register" \
  --description="Test register" \
  contacts

# Or via UI: OpenRegister → Registers → New Register
```

### 3. Import a Schema

```bash
# Import Person schema from Schema.org
docker exec -u 33 nextcloud php occ openregister:schema:import \
  --source=schema.org \
  Person

# Or via UI: OpenRegister → Schemas → Import
```

### 4. Create an Object

Via API:
```bash
curl -X POST http://localhost:8080/index.php/apps/openregister/api/objects \
  -u admin:admin \
  -H "Content-Type: application/json" \
  -d '{
    "register": "contacts",
    "schema": "Person",
    "object": {
      "name": "John Doe",
      "email": "john@example.com"
    }
  }'
```

## 📊 Check Service Status

```bash
# View all running services
docker-compose ps

# Check specific service logs
docker-compose logs nextcloud
docker-compose logs db
docker-compose logs ollama

# Follow logs in real-time
docker-compose logs -f
```

## 🔧 Common Operations

### Restart Services

```bash
# Restart all
docker-compose restart

# Restart specific service
docker-compose restart nextcloud
```

### Stop Services

```bash
# Stop (preserves data)
docker-compose down

# Stop and remove data (fresh start)
docker-compose down -v
```

### Execute Commands in Container

```bash
# Run occ commands
docker exec -u 33 nextcloud php occ app:list
docker exec -u 33 nextcloud php occ openregister:status

# Access bash shell
docker exec -it nextcloud bash

# Access PostgreSQL
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud
```

### View Database

```bash
# Connect to PostgreSQL
docker exec -it openregister-postgres psql -U nextcloud -d nextcloud

# Once connected:
\dt                              # List tables
\d oc_openregister_objects      # Describe table
SELECT * FROM oc_openregister_objects LIMIT 5;

# Exit: \q
```

## 🎯 Using AI Features

### Pull Ollama Models

```bash
# Llama 3.2 (4.7GB)
docker exec openregister-ollama ollama pull llama3.2

# Mistral (4.1GB)
docker exec openregister-ollama ollama pull mistral

# List models
docker exec openregister-ollama ollama list

# Test model
docker exec openregister-ollama ollama run llama3.2 "Hello, world!"
```

### Test AI Chat

Via API:
```bash
curl -X POST http://localhost:8080/index.php/apps/openregister/api/chat/send \
  -u admin:admin \
  -H "Content-Type: application/json" \
  -d '{
    "message": "What objects do I have?",
    "model": "llama3.2"
  }'
```

## 🔄 Alternative Database (MariaDB)

For testing compatibility with MariaDB:

```bash
# Stop current setup
docker-compose down -v

# Start with MariaDB
docker-compose --profile mariadb up -d

# Everything else works the same!
```

## 📦 Optional Services

### Enable Solr Search

```bash
docker-compose --profile solr up -d

# Access Solr UI: http://localhost:8983
```

### Enable n8n Workflows

```bash
docker-compose --profile n8n up -d

# Access n8n UI: http://localhost:5678
# Username: admin
# Password: admin
```

### Enable All Optional Services

```bash
docker-compose --profile solr --profile n8n --profile elasticsearch up -d
```

## 🧪 Run Integration Tests

```bash
# Install Newman in container (first time only)
docker exec -u root nextcloud apt-get update
docker exec -u root nextcloud apt-get install -y nodejs npm
docker exec -u root nextcloud npm install -g newman

# Run tests
docker exec -u 33 nextcloud newman run \
  /var/www/html/custom_apps/openregister/tests/integration/openregister-crud.postman_collection.json \
  --env-var "base_url=http://localhost" \
  --env-var "admin_user=admin" \
  --env-var "admin_password=admin"
```

Or use the automated test script:
```bash
./docker/test-database-compatibility.sh
```

## 🐛 Troubleshooting

### Port 8080 Already in Use

**Option 1: Stop conflicting service**
```bash
sudo lsof -i :8080
# Note the PID and stop it
```

**Option 2: Change port**
Edit `docker-compose.yml`:
```yaml
nextcloud:
  ports:
    - "8081:80"  # Change to any available port
```

### Services Won't Start

```bash
# Check logs
docker-compose logs

# Reset everything
docker-compose down -v
docker-compose up -d
```

### Permission Denied Errors

```bash
# Fix ownership
docker exec -u root nextcloud chown -R www-data:www-data /var/www/html
docker exec -u root nextcloud chmod -R 755 /var/www/html
```

### Database Connection Failed

```bash
# Check if database is running
docker-compose ps db

# Check database logs
docker-compose logs db

# Restart database
docker-compose restart db

# Wait a few seconds for health check
docker-compose ps
```

### Out of Memory

Increase Docker memory:
- **Docker Desktop**: Settings → Resources → Memory → Set to 8GB+
- **Linux**: Edit `/etc/docker/daemon.json`

### Slow Performance

```bash
# Check resource usage
docker stats

# Prune unused images/containers
docker system prune -a
```

## 📚 Next Steps

After setup:

1. **Read the User Guide**: http://localhost:8080/index.php/apps/openregister (Settings → Documentation)
2. **Explore API**: See [API Documentation](../website/docs/api/)
3. **Set Up Access Control**: [Access Control Guide](../website/docs/features/access-control.md)
4. **Configure AI Features**: [AI Features Guide](../website/docs/features/ai-features.md)
5. **Create Workflows**: [n8n Integration](../website/docs/technical/n8n-mcp/)

## 🔗 Useful Links

- **Nextcloud UI**: http://localhost:8080
- **OpenRegister**: http://localhost:8080/index.php/apps/openregister
- **Ollama API**: http://localhost:11434
- **Presidio**: http://localhost:5001
- **Solr** (if enabled): http://localhost:8983
- **n8n** (if enabled): http://localhost:5678
- **Elasticsearch** (if enabled): http://localhost:9200

## 💾 Data Persistence

Data is stored in Docker volumes:
- `openregister_nextcloud` - Nextcloud files and config
- `openregister_db` - Database data
- `openregister_ollama` - AI models
- `openregister_apps` - Installed apps

**Backup volumes:**
```bash
# Backup
docker run --rm -v openregister_db:/data -v $(pwd):/backup alpine \
  tar czf /backup/db-backup.tar.gz -C /data .

# Restore
docker run --rm -v openregister_db:/data -v $(pwd):/backup alpine \
  tar xzf /backup/db-backup.tar.gz -C /data
```

## 🔒 Security Notes

**⚠️ Default credentials are for development only!**

For production:
1. Change all passwords in `docker-compose.yml`
2. Enable HTTPS
3. Use secure passwords
4. Restrict network access
5. Enable firewall rules

## 📖 More Documentation

- [Complete Installation Guide](../website/docs/installation.md)
- [Database Testing Guide](README-DATABASE-TESTING.md)
- [Docker Profiles Guide](../website/docs/development/docker-profiles.md)
- [Development Setup](../website/docs/development/docker-setup.md)
- [PostgreSQL Search](../website/docs/development/postgresql-search.md)

## 🆘 Need Help?

- **Documentation**: https://openregisters.app/
- **GitHub Issues**: https://github.com/ConductionNL/openregister/issues
- **Discussions**: https://github.com/ConductionNL/openregister/discussions
- **Email**: support@conduction.nl

---

**Happy coding! 🚀**


