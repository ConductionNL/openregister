---
id: docker-profiles
title: Docker Compose Profiles Guide
sidebar_label: Docker Profiles
---

# Docker Compose Profiles Guide

OpenRegister uses Docker Compose profiles to provide optional services that can be enabled on demand. This allows you to run only the services you need, reducing resource usage and complexity.

## Available Profiles

| Profile | Services | Purpose | Resource Requirements |
|---------|---------|---------|----------------------|
| **none** (default) | PostgreSQL, Nextcloud, Ollama, Presidio | Core OpenRegister functionality | ~4GB RAM |
| **n8n** | + n8n Workflow Automation | Workflow automation and webhooks | +512MB RAM |
| **automation** | Same as n8n | Alias for n8n profile | +512MB RAM |
| **solr** | + Solr + ZooKeeper | Traditional search (legacy) | +1GB RAM |
| **elasticsearch** | + Elasticsearch | Modern search engine (legacy) | +1GB RAM |
| **search** | + Solr + ZooKeeper + Elasticsearch | All search engines (legacy) | +2GB RAM |
| **huggingface** | + TGI, OpenLLM, Dolphin VLM | Advanced LLM capabilities | +16GB RAM, GPU recommended |
| **llm** | + TGI, OpenLLM | LLM inference without VLM | +16GB RAM, GPU recommended |

**Note:** PostgreSQL with pgvector and pg_trgm is now the **recommended** search solution. Solr and Elasticsearch are kept for backwards compatibility and migration purposes.

## Quick Start

### Default Setup (No Profiles)

```bash
# Start with core services only.
docker-compose up -d

# This includes:
# - PostgreSQL with pgvector
# - Nextcloud + OpenRegister
# - Ollama (local LLM)
# - Presidio (PII detection)
```

### With n8n Workflow Automation

```bash
# Start with n8n included.
docker-compose --profile n8n up -d

# Or use the automation alias.
docker-compose --profile automation up -d

# Access n8n at: http://localhost:5678
# Default credentials: admin / admin
```

### With Hugging Face LLM Services

```bash
# Start with all Hugging Face services.
docker-compose --profile huggingface up -d

# This adds:
# - TGI (Text Generation Inference) at http://localhost:8081
# - OpenLLM management UI at http://localhost:3000
# - OpenLLM API at http://localhost:8082

# Or just LLM services without VLM.
docker-compose --profile llm up -d
```

### Multiple Profiles

```bash
# Combine profiles for full stack.
docker-compose --profile n8n --profile huggingface up -d

# Or using short form.
docker-compose --profile n8n --profile llm up -d
```

## Service Details

### n8n Workflow Automation

**What it does:**
- Visual workflow automation
- Webhook integration with Nextcloud
- Data transformation and routing
- Integration with external services

**When to use:**
- Need workflow automation
- Want to react to OpenRegister events
- Need to integrate with external APIs
- Building complex data pipelines

**Ports:**
- 5678 - n8n Web UI and API

**Access:**
```bash
# Web UI
http://localhost:5678

# Default credentials
Username: admin
Password: admin
```

**Volume:**
- `n8n` - Stores workflows and credentials

**Configuration:**
```yaml
environment:
  - N8N_BASIC_AUTH_USER=admin
  - N8N_BASIC_AUTH_PASSWORD=admin
  - N8N_HOST=localhost
  - WEBHOOK_URL=http://localhost:5678/
```

### Hugging Face TGI (Text Generation Inference)

**What it does:**
- High-performance LLM inference
- OpenAI-compatible API
- Optimized for production use
- Supports quantization and sharding

**When to use:**
- Need fast LLM inference
- Want OpenAI-compatible API
- Have GPU available
- Need production-grade performance

**Ports:**
- 8081 - HTTP API

**Access:**
```bash
# Health check
curl http://localhost:8081/health

# OpenAI-compatible endpoint
curl http://localhost:8081/v1/completions \\
  -H "Content-Type: application/json" \\
  -d '{
    "model": "mistralai/Mistral-7B-Instruct-v0.2",
    "prompt": "Hello, how are you?",
    "max_tokens": 100
  }'
```

**Volume:**
- `tgi-models` - Stores downloaded models (can be large!)

**Configuration:**
```yaml
environment:
  - MODEL_ID=mistralai/Mistral-7B-Instruct-v0.2
  - MAX_INPUT_LENGTH=4096
  - MAX_TOTAL_TOKENS=8192
  # - HUGGING_FACE_HUB_TOKEN=your_token_here  # Required for gated models
```

**Supported Models:**
- Mistral 7B Instruct
- Llama 2 7B/13B/70B
- CodeLlama
- Falcon
- StarCoder
- Any Hugging Face model compatible with TGI

**Model Downloads:**
Models are automatically downloaded on first start. This can take time depending on model size:
- 7B models: ~5-10 minutes, ~14GB
- 13B models: ~10-20 minutes, ~26GB
- 70B models: ~30-60 minutes, ~140GB

### OpenLLM Management Interface

**What it does:**
- Web UI for model management
- Download and manage language models
- Test models interactively
- Monitor model performance
- OpenAI-compatible API

**When to use:**
- Want easy model management
- Need to test multiple models
- Want a user-friendly interface
- Need model switching capabilities

**Ports:**
- 3000 (dev: 3002) - Web UI
- 8082 - API endpoint

**Access:**
```bash
# Web UI
http://localhost:3000  # Production
http://localhost:3002  # Development (avoid docs port conflict)

# API
curl http://localhost:8082/v1/models
```

**Volumes:**
- `openllm-models` - Model storage
- `openllm-cache` - Hugging Face cache

**Configuration:**
```yaml
environment:
  - OPENLLM_MODEL=mistralai/Mistral-7B-Instruct-v0.2
  - OPENLLM_BACKEND=vllm
  - OPENLLM_MAX_MODEL_LEN=4096
  - OPENLLM_GPU_MEMORY_UTILIZATION=0.9
```

**Available Backends:**
- `vllm` - Fast inference, GPU optimized (recommended)
- `pt` - PyTorch backend, CPU compatible
- `ctransformers` - Quantized models

## Configuration Examples

### Basic Setup (Core Only)

```yaml
# docker-compose.yml
services:
  db:
    image: pgvector/pgvector:pg16
  nextcloud:
    image: nextcloud
  ollama:
    image: ollama/ollama:latest
```

```bash
docker-compose up -d
```

### With Automation

```yaml
# docker-compose.override.yml
version: '3.5'
services:
  n8n:
    profiles: ["n8n"]
```

```bash
docker-compose --profile n8n up -d
```

### Full Stack with LLMs

```bash
# Production
docker-compose --profile n8n --profile huggingface up -d

# Development
docker-compose -f docker-compose.dev.yml --profile n8n --profile llm up -d
```

## Resource Requirements

### Minimum Requirements

| Setup | RAM | Disk | GPU |
|-------|-----|------|-----|
| Core only | 4GB | 20GB | Optional (Ollama) |
| + n8n | 4.5GB | 25GB | Optional |
| + Hugging Face (7B) | 20GB | 40GB | 8GB VRAM (recommended) |
| + Hugging Face (13B) | 28GB | 50GB | 16GB VRAM (recommended) |
| Full stack | 24GB | 60GB | 16GB VRAM |

### Recommended Requirements

| Setup | RAM | Disk | GPU |
|-------|-----|------|-----|
| Core only | 8GB | 50GB | 8GB VRAM |
| + n8n | 8GB | 50GB | 8GB VRAM |
| + Hugging Face (7B) | 32GB | 100GB | 16GB VRAM |
| + Hugging Face (13B) | 48GB | 150GB | 24GB VRAM |
| Full stack | 48GB | 200GB | 24GB VRAM |

## GPU Support

### NVIDIA GPU Setup

```bash
# Install NVIDIA Docker runtime
distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
curl -s -L https://nvidia.github.io/nvidia-docker/gpgkey | sudo apt-key add -
curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.list | \\
  sudo tee /etc/apt/sources.list.d/nvidia-docker.list

sudo apt-get update
sudo apt-get install -y nvidia-docker2
sudo systemctl restart docker

# Verify GPU is available
docker run --rm --gpus all nvidia/cuda:11.8.0-base-ubuntu22.04 nvidia-smi
```

### Without GPU

You can still run LLM services on CPU, but performance will be significantly slower:

```yaml
# docker-compose.override.yml
services:
  tgi-llm:
    deploy:
      resources:
        reservations:
          # Remove GPU reservation
          memory: 8G
    environment:
      # Use smaller models
      - MODEL_ID=gpt2
```

## Management Commands

### Start/Stop Specific Profiles

```bash
# Start with profile
docker-compose --profile n8n up -d

# Stop specific service
docker-compose stop n8n

# Remove profile services
docker-compose --profile n8n down
```

### View Running Services

```bash
# List all containers
docker-compose ps

# List only profile services
docker-compose --profile n8n ps
docker-compose --profile huggingface ps
```

### Logs

```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f n8n
docker-compose logs -f tgi-llm
docker-compose logs -f openllm
```

### Update Services

```bash
# Pull latest images
docker-compose pull

# Recreate containers
docker-compose --profile n8n --profile huggingface up -d --force-recreate
```

## Troubleshooting

### Profile Service Not Starting

```bash
# Check if profile is activated
docker-compose --profile n8n config

# Verify service exists
docker-compose --profile n8n ps -a

# Check logs for errors
docker-compose --profile n8n logs n8n
```

### Out of Memory

```bash
# Check memory usage
docker stats

# Reduce services
docker-compose --profile n8n down
docker-compose up -d  # Only core services

# Or adjust memory limits in docker-compose.yml
```

### Model Download Issues

```bash
# Check TGI logs
docker-compose logs -f tgi-llm

# Common issues:
# 1. Need Hugging Face token for gated models
#    Set HUGGING_FACE_HUB_TOKEN environment variable

# 2. Slow download
#    Models are large, be patient or use local mirror

# 3. Out of disk space
#    Check: df -h
#    Clean: docker system prune -a
```

### GPU Not Detected

```bash
# Verify GPU is available
nvidia-smi

# Check Docker can access GPU
docker run --rm --gpus all nvidia/cuda:11.8.0-base-ubuntu22.04 nvidia-smi

# Verify service configuration
docker-compose --profile huggingface config | grep -A 5 "devices"
```

## Best Practices

### Development

```bash
# Use dev compose file
docker-compose -f docker-compose.dev.yml --profile n8n --profile llm up -d

# Enable debug logging
# Edit docker-compose.dev.yml and set LOG_LEVEL=DEBUG
```

### Production

```bash
# Use production compose file
docker-compose --profile n8n --profile huggingface up -d

# Change default passwords
# Edit docker-compose.yml and update:
# - N8N_BASIC_AUTH_PASSWORD
# - NEXTCLOUD_ADMIN_PASSWORD
# - POSTGRES_PASSWORD
```

### Resource Optimization

```bash
# Run only what you need
docker-compose up -d  # Core only

# Add services as needed
docker-compose --profile n8n up -d

# Use smaller models
# Edit docker-compose.yml:
# - MODEL_ID=microsoft/phi-2  # 2.7B parameters, much smaller
```

## Integration Examples

### Using n8n with OpenRegister

1. Start with n8n profile:
```bash
docker-compose --profile n8n up -d
```

2. Access n8n: http://localhost:5678

3. Create workflow:
   - Trigger: Webhook
   - Webhook URL: http://localhost:5678/webhook/openregister
   - Action: HTTP Request to Nextcloud API

4. Configure OpenRegister webhook:
```bash
docker exec -u 33 nextcloud php occ config:app:set openregister webhook_url --value="http://n8n:5678/webhook/openregister"
```

### Using TGI for AI Features

1. Start with Hugging Face profile:
```bash
docker-compose --profile huggingface up -d
```

2. Configure OpenRegister to use TGI:
```bash
# Access Nextcloud settings
# Settings > OpenRegister > AI Configuration
# API Endpoint: http://tgi-llm:80/v1
# Model: mistralai/Mistral-7B-Instruct-v0.2
```

3. Test the connection:
```bash
docker exec master-nextcloud-1 curl -s http://tgi-llm:80/health
```

### Using OpenLLM for Model Management

1. Start OpenLLM:
```bash
docker-compose --profile llm up -d
```

2. Access Web UI: http://localhost:3000

3. Download models through UI or CLI:
```bash
docker exec openregister-openllm openllm download microsoft/phi-2
```

4. Switch models:
```bash
docker-compose stop openllm
# Edit docker-compose.yml, change OPENLLM_MODEL
docker-compose --profile llm up -d openllm
```

## Summary

Docker Compose profiles provide flexible deployment options:

- **Default**: Minimal setup for basic functionality
- **+ n8n**: Add workflow automation
- **+ Hugging Face**: Add advanced LLM capabilities
- **Custom**: Mix and match based on your needs

Choose profiles based on:
- Available resources (RAM, GPU)
- Required features
- Performance needs
- Use case requirements

Start small with core services, then add profiles as needed!

