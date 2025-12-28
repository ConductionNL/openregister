# Docker Compose Profiles - Quick Reference

## TL;DR

```bash
# Core services only (minimal)
docker-compose up -d

# With workflow automation
docker-compose --profile n8n up -d

# With Hugging Face LLMs
docker-compose --profile huggingface up -d

# Full stack
docker-compose --profile n8n --profile huggingface up -d
```

## Available Profiles

| Profile | What You Get | When to Use | RAM Needed |
|---------|-------------|-------------|------------|
| **(none)** | PostgreSQL + Nextcloud + Ollama + Presidio | Basic OpenRegister | ~4GB |
| **n8n** | + n8n workflow automation | Need automation/webhooks | +512MB |
| **automation** | Same as n8n (alias) | Alternative name | +512MB |
| **solr** | + Solr + ZooKeeper | Legacy search (backwards compat) | +1GB |
| **elasticsearch** | + Elasticsearch | Legacy search (backwards compat) | +1GB |
| **search** | + Solr + ES (both) | All legacy search engines | +2GB |
| **huggingface** | + TGI + OpenLLM + Dolphin | Advanced LLM features | +16GB + GPU |
| **llm** | + TGI + OpenLLM (no Dolphin) | LLM without vision models | +16GB + GPU |

**Note:** PostgreSQL search (pgvector + pg_trgm) is **recommended**. Solr/Elasticsearch kept for compatibility.

## Quick Commands

### Start

```bash
# Core only
docker-compose up -d

# With n8n
docker-compose --profile n8n up -d

# With Hugging Face
docker-compose --profile huggingface up -d

# With legacy Solr search
docker-compose --profile solr up -d

# With legacy Elasticsearch
docker-compose --profile elasticsearch up -d

# Combined
docker-compose --profile n8n --profile llm up -d
```

### Stop

```bash
# Stop all
docker-compose down

# Stop specific profile services
docker-compose stop n8n
docker-compose stop tgi-llm openllm
```

### View

```bash
# See what's running
docker-compose ps

# See specific profile
docker-compose --profile n8n ps
docker-compose --profile huggingface ps
```

### Logs

```bash
# All logs
docker-compose logs -f

# Specific service
docker-compose logs -f n8n
docker-compose logs -f tgi-llm
docker-compose logs -f openllm
```

## Service Ports

| Service | Port | URL |
|---------|------|-----|
| Nextcloud | 8080 | http://localhost:8080 |
| PostgreSQL | 5432 | postgresql://localhost:5432 |
| Ollama | 11434 | http://localhost:11434 |
| Presidio | 5001 | http://localhost:5001 |
| **n8n** | 5678 | http://localhost:5678 |
| **Solr** (legacy) | 8983 | http://localhost:8983 |
| **ZooKeeper** (legacy) | 2181 | - |
| **Elasticsearch** (legacy) | 9200 | http://localhost:9200 |
| **TGI LLM** | 8081 | http://localhost:8081 |
| **OpenLLM Web UI** | 3000 | http://localhost:3000 |
| **OpenLLM API** | 8082 | http://localhost:8082 |
| Docusaurus (dev) | 3001 | http://localhost:3001 |

## Default Credentials

```bash
# Nextcloud
Username: admin
Password: admin

# PostgreSQL
Username: nextcloud
Password: !ChangeMe!
Database: nextcloud

# n8n
Username: admin
Password: admin
```

## Resource Requirements

```
Core:           4GB RAM, 20GB disk
+ n8n:          +512MB RAM, +5GB disk
+ Solr:         +1GB RAM, +10GB disk (legacy)
+ Elasticsearch: +1GB RAM, +10GB disk (legacy)
+ Hugging Face: +16GB RAM, +40GB disk, GPU recommended
```

## GPU Setup (Optional but Recommended for LLMs)

```bash
# Install NVIDIA Docker (one-time)
distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
curl -s -L https://nvidia.github.io/nvidia-docker/gpgkey | sudo apt-key add -
curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.list | \\
  sudo tee /etc/apt/sources.list.d/nvidia-docker.list
sudo apt-get update && sudo apt-get install -y nvidia-docker2
sudo systemctl restart docker

# Test GPU
docker run --rm --gpus all nvidia/cuda:11.8.0-base-ubuntu22.04 nvidia-smi
```

## Common Operations

### Add n8n to Running Stack

```bash
docker-compose --profile n8n up -d
```

### Add Hugging Face to Running Stack

```bash
docker-compose --profile huggingface up -d
```

### Remove Optional Services

```bash
docker-compose stop n8n tgi-llm openllm
docker-compose rm n8n tgi-llm openllm
```

### Change LLM Model

Edit `docker-compose.yml`:
```yaml
environment:
  - MODEL_ID=mistralai/Mistral-7B-Instruct-v0.2  # Change this
```

Then restart:
```bash
docker-compose --profile huggingface up -d --force-recreate tgi-llm
```

### View Model Download Progress

```bash
docker-compose logs -f tgi-llm
# Wait for "Connected" message
```

### Check Service Health

```bash
# All services
docker-compose ps

# Specific checks
curl http://localhost:8080/status.php  # Nextcloud
docker exec openregister-postgres pg_isready  # PostgreSQL
curl http://localhost:5678/healthz  # n8n
curl http://localhost:8081/health  # TGI
curl http://localhost:3000/health  # OpenLLM
```

## Troubleshooting

### Service Won't Start

```bash
# Check logs
docker-compose logs [service-name]

# Common issues:
# - Out of memory: Reduce services or add RAM
# - Port conflict: Check if port is already in use
# - GPU not found: Install NVIDIA Docker or disable GPU
```

### Model Download Stuck

```bash
# TGI downloads models on first start
# Large models (7B+) take 5-30 minutes
# Check progress:
docker-compose logs -f tgi-llm

# If stuck, check disk space:
df -h
```

### Out of Memory

```bash
# Check usage
docker stats

# Solutions:
# 1. Run fewer profiles
docker-compose down
docker-compose up -d  # Core only

# 2. Use smaller models
# Edit docker-compose.yml:
# MODEL_ID=microsoft/phi-2  # Much smaller (2.7B)

# 3. Add swap space
sudo fallocate -l 8G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
```

## Integration Examples

### n8n + OpenRegister Webhooks

1. Start n8n:
   ```bash
   docker-compose --profile n8n up -d
   ```

2. Access: http://localhost:5678

3. Create webhook workflow

4. Configure OpenRegister:
   ```bash
   # In Nextcloud settings or via occ
   docker exec -u 33 nextcloud php occ config:app:set openregister \\
     webhook_url --value="http://n8n:5678/webhook/openregister"
   ```

### TGI for AI Features

1. Start Hugging Face:
   ```bash
   docker-compose --profile huggingface up -d
   ```

2. Configure in Nextcloud:
   - Settings > OpenRegister > AI
   - Endpoint: http://tgi-llm:80/v1
   - Model: mistralai/Mistral-7B-Instruct-v0.2

3. Test:
   ```bash
   curl http://localhost:8081/v1/models
   ```

## Model Options

### Recommended Models by Size

**Small (2-3B) - CPU friendly:**
- `microsoft/phi-2` - 2.7B, good for code
- `google/gemma-2b` - 2B, efficient

**Medium (7B) - Good balance:**
- `mistralai/Mistral-7B-Instruct-v0.2` - Excellent quality
- `meta-llama/Llama-2-7b-chat-hf` - Solid performer
- `teknium/OpenHermes-2.5-Mistral-7B` - Great for chat

**Large (13B+) - Best quality:**
- `meta-llama/Llama-2-13b-chat-hf` - High quality
- `NousResearch/Nous-Hermes-2-Mixtral-8x7B-DPO` - Excellent reasoning

**Specialized:**
- `codellama/CodeLlama-7b-Instruct-hf` - Code generation
- `HuggingFaceH4/zephyr-7b-beta` - Helpful assistant
- `openchat/openchat-3.5-0106` - Fast and good

## Configuration Files

### Override Settings

Create `docker-compose.override.yml`:
```yaml
version: '3.5'
services:
  n8n:
    environment:
      - N8N_BASIC_AUTH_PASSWORD=my-secure-password
  tgi-llm:
    environment:
      - MODEL_ID=microsoft/phi-2
      - HUGGING_FACE_HUB_TOKEN=hf_your_token_here
```

Apply:
```bash
docker-compose --profile n8n --profile llm up -d
```

## Development vs Production

### Development
```bash
# Use dev compose file
docker-compose -f docker-compose.dev.yml --profile n8n up -d

# Features:
# - Debug logging enabled
# - Hot reload for code changes
# - OpenLLM on port 3002 (avoid docs conflict)
```

### Production
```bash
# Use production compose file
docker-compose --profile n8n --profile huggingface up -d

# Remember to:
# 1. Change default passwords
# 2. Use HTTPS reverse proxy
# 3. Configure backups
# 4. Monitor resources
```

## More Information

- **Full Guide**: `website/docs/development/docker-profiles.md`
- **PostgreSQL**: `website/docs/development/postgresql-search.md`
- **Migration**: `website/docs/development/postgresql-migration.md`
- **Setup**: `POSTGRESQL_QUICKSTART.md`

## Support

- Email: info@conduction.nl
- Documentation: https://openregisters.app/
- Check logs: `docker-compose logs -f`

---

**Remember**: Start with core services, add profiles as needed. You can always add more later!

