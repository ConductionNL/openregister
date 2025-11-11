# Standalone Ollama Setup Guide

This guide shows how to run Ollama separately from the main OpenRegister stack using `docker-compose.ollama.yml`.

## Why Standalone?

Running Ollama standalone is ideal for:
- 🖥️ **Separate Hardware**: Run on a more powerful machine with more RAM/GPU
- 🔧 **Production**: Isolate AI workloads from application stack
- 📊 **Scalability**: Serve multiple OpenRegister instances from one Ollama
- 💰 **Cost Optimization**: Use a dedicated GPU machine only for AI

### Technical Implementation

OpenRegister uses LLPhant's native Ollama integration:
- **Native API**: Direct connection to Ollama's `/api/` endpoints
- **No Compatibility Layer**: Bypasses OpenAI-compatible API for better performance
- **Optimized Configuration**: Automatically uses correct endpoint formats
- **Full Feature Support**: Chat, embeddings, and function calling all work natively

## Quick Start

### 1. Start Standalone Ollama

```bash
# From the openregister directory
docker-compose -f docker-compose.ollama.yml up -d

# Check status
docker-compose -f docker-compose.ollama.yml ps

# View logs
docker-compose -f docker-compose.ollama.yml logs -f ollama

# Access Web UI
# Open your browser to http://localhost:4000
```

### 2. Pull Llama 3.2 Model

```bash
# Pull the recommended 8B model (requires 8-16GB RAM)
docker exec standalone-ollama ollama pull llama3.2

# Or pull the lighter 3B version
docker exec standalone-ollama ollama pull llama3.2:3b

# For embedding (RAG features)
docker exec standalone-ollama ollama pull nomic-embed-text
```

### 3. Verify It's Running

```bash
# Test API endpoint from your host machine
curl http://localhost:11434/api/tags

# Should return JSON with model list

# Check resource usage
docker stats standalone-ollama
```

### 4. Configure OpenRegister

**⚠️ Important: Docker Networking**

OpenRegister runs inside the Nextcloud container, so you **cannot** use `localhost` - it must be able to reach Ollama from inside the container.

**Option 1: Use Host IP Address (Simplest)**
- URL: `http://YOUR_HOST_IP:11434` (e.g., `http://192.168.1.100:11434`)
- Model: `llama3.2`
- **Find your IP**: Run `hostname -I | awk '{print $1}'` on Linux/Mac or `ipconfig` on Windows

**Option 2: Connect via Docker Network (Recommended for Production)**

First, connect the standalone Ollama container to your Nextcloud network:

```bash
# Find your Nextcloud network
docker network ls | grep master

# Connect standalone Ollama to that network
docker network connect master_default standalone-ollama

# Verify connection from Nextcloud container
docker exec master-nextcloud-1 curl -s http://standalone-ollama:11434/api/tags
```

Then in OpenRegister settings:
- URL: `http://standalone-ollama:11434`
- Model: `llama3.2`

**Option 3: Different Physical Machine**
- URL: `http://your-ollama-server-ip:11434`
- Model: `llama3.2`
- Ensure port 11434 is accessible (firewall rules)

**❌ Won't Work:**
- `http://localhost:11434` - This refers to the Nextcloud container itself, not your host machine

## Memory Configuration

The standalone compose is pre-configured for **Llama 3.2 8B models** with:
- **Memory Limit**: 16GB
- **Memory Reservation**: 8GB  
- **Shared Memory**: 2GB
- **Concurrent Requests**: 4

### For Larger Models (70B)

Edit `docker-compose.ollama.yml`:

```yaml
deploy:
  resources:
    limits:
      memory: 80G  # Increase for 70B models
    reservations:
      memory: 64G  # Minimum reservation
```

Then restart:

```bash
docker-compose -f docker-compose.ollama.yml down
docker-compose -f docker-compose.ollama.yml up -d
```

## GPU Support

### Prerequisites
- NVIDIA GPU with CUDA support
- [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/install-guide.html) installed

### Enable GPU

Uncomment the GPU section in `docker-compose.ollama.yml`:

```yaml
services:
  ollama:
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all  # or count: 1 for single GPU
              capabilities: [gpu]
```

Restart Ollama:

```bash
docker-compose -f docker-compose.ollama.yml down
docker-compose -f docker-compose.ollama.yml up -d
```

Verify GPU is being used:

```bash
# Check GPU usage
nvidia-smi

# Check Ollama can see GPU
docker exec standalone-ollama nvidia-smi
```

## Web UI

The standalone setup includes **Open WebUI** for easy model management and testing.

### Accessing the Web UI

```bash
# Open in your browser
http://localhost:4000
```

### Features

- 🎨 **Chat Interface**: Test models with a user-friendly chat interface
- 📊 **Model Management**: Pull, delete, and switch between models
- ⚙️ **Configuration**: Adjust model parameters (temperature, context, etc.)
- 📜 **Chat History**: Save and resume conversations
- 🔧 **System Prompts**: Create and manage custom prompts
- 📁 **File Upload**: Upload documents for RAG testing

### First-Time Setup

1. Open `http://localhost:4000` in your browser
2. Create an admin account (first user becomes admin)
3. The UI will automatically connect to Ollama at `http://ollama:11434`
4. Start chatting with your models!

### Tips

- Use the Web UI to quickly test different models before configuring OpenRegister
- Experiment with temperature and other parameters to find optimal settings
- Test RAG functionality by uploading documents
- Export chat histories for debugging or documentation

## Managing Models

### List Installed Models

```bash
docker exec standalone-ollama ollama list
```

### Pull Additional Models

```bash
# Mistral (faster alternative)
docker exec standalone-ollama ollama pull mistral

# Code generation
docker exec standalone-ollama ollama pull codellama

# High quality (requires 64GB+ RAM)
docker exec standalone-ollama ollama pull llama3.2:70b
```

### Remove Models

```bash
docker exec standalone-ollama ollama rm llama2
```

### Check Model Info

```bash
docker exec standalone-ollama ollama show llama3.2
```

## Performance Tuning

### Environment Variables

Edit `docker-compose.ollama.yml` to tune performance:

```yaml
environment:
  # Number of concurrent requests (default: 4)
  - OLLAMA_NUM_PARALLEL=8
  
  # How long to keep models in memory (default: 30m)
  - OLLAMA_KEEP_ALIVE=1h
  
  # Maximum models to keep loaded (default: 2)
  - OLLAMA_MAX_LOADED_MODELS=3
```

### Monitor Performance

```bash
# Real-time stats
docker stats standalone-ollama

# Detailed logs
docker logs -f standalone-ollama

# Check API status
curl http://localhost:11434/api/tags
```

## Networking

### Connect Multiple OpenRegister Instances

1. **Same Host**: All instances use `http://localhost:11434`

2. **Different Hosts**: 
   - Open firewall port 11434
   - Use `http://ollama-server-ip:11434`

3. **Docker Network** (Advanced):

```bash
# Create shared network
docker network create openregister-shared

# Start Ollama on shared network
docker-compose -f docker-compose.ollama.yml up -d

# Connect network to Ollama
docker network connect openregister-shared standalone-ollama

# In other stacks, connect to same network
docker network connect openregister-shared nextcloud-container
```

## Troubleshooting

### Connection Error: "Failed to connect to localhost"

**Symptoms:**
- Error: "Failed to connect to localhost port 11434"
- Chat test fails in OpenRegister
- API connection refused

**Cause:** Using `http://localhost:11434` in OpenRegister, but Nextcloud runs in a container where `localhost` refers to the container itself.

**Solutions:**

**Quick Fix - Use Host IP:**

```bash
# Find your host IP address
hostname -I | awk '{print $1}'  # Linux/Mac
# Or ipconfig on Windows

# Use that IP in OpenRegister settings
# Example: http://192.168.1.100:11434
```

**Better Fix - Connect Docker Networks:**

```bash
# Find Nextcloud's network
docker network ls | grep master

# Connect standalone Ollama to it
docker network connect master_default standalone-ollama

# Test connection from Nextcloud
docker exec master-nextcloud-1 curl -s http://standalone-ollama:11434/api/tags

# Should return JSON with models
```

Then update OpenRegister settings:
- URL: `http://standalone-ollama:11434`
- Test connection - should work now!

**Why this happens:** Docker container isolation means `localhost` inside a container is different from `localhost` on your host. Containers communicate via network names or IP addresses.

### Ollama Won't Start

```bash
# Check logs
docker-compose -f docker-compose.ollama.yml logs ollama

# Check if port is already in use
sudo lsof -i :11434

# Restart clean
docker-compose -f docker-compose.ollama.yml down -v
docker-compose -f docker-compose.ollama.yml up -d
```

### Out of Memory

```bash
# Check current usage
docker stats standalone-ollama

# If using too much memory:
# 1. Use smaller model (3B instead of 8B)
# 2. Reduce OLLAMA_NUM_PARALLEL
# 3. Reduce OLLAMA_MAX_LOADED_MODELS
# 4. Add more RAM to server
```

### Slow Responses

```bash
# Check if model is loaded
docker exec standalone-ollama ollama list

# Increase keep alive time
# Edit docker-compose.ollama.yml:
# OLLAMA_KEEP_ALIVE=1h

# Enable GPU if available (see GPU section above)
```

### Can't Connect from OpenRegister

```bash
# Test connection from OpenRegister container
docker exec nextcloud curl http://ollama:11434/api/tags

# If that fails, check:
# 1. Firewall rules
# 2. Docker network configuration
# 3. Ollama is running: docker ps | grep ollama
```

## Maintenance

### Update Ollama

```bash
# Pull latest image
docker pull ollama/ollama:latest

# Recreate container
docker-compose -f docker-compose.ollama.yml up -d
```

### Update Models

```bash
# Models are automatically updated when pulled again
docker exec standalone-ollama ollama pull llama3.2
```

### Backup Models

```bash
# Models are stored in Docker volume 'ollama'
# Backup the volume:
docker run --rm \
  -v ollama:/source \
  -v $(pwd):/backup \
  alpine tar czf /backup/ollama-backup.tar.gz -C /source .
```

### Restore Models

```bash
# Restore from backup:
docker run --rm \
  -v ollama:/target \
  -v $(pwd):/backup \
  alpine tar xzf /backup/ollama-backup.tar.gz -C /target
```

## Production Checklist

- [ ] Sufficient RAM allocated (8GB minimum, 16GB recommended for 8B models)
- [ ] GPU configured if available
- [ ] Firewall rules configured for remote access
- [ ] Models downloaded and tested
- [ ] Performance monitoring in place
- [ ] Backup strategy implemented
- [ ] Resource limits set appropriately
- [ ] Health checks passing
- [ ] Connection from OpenRegister tested
- [ ] Log rotation configured

## Resources

- **Ollama Documentation**: https://ollama.ai/
- **Model Library**: https://ollama.ai/library
- **GitHub**: https://github.com/ollama/ollama
- **OpenRegister AI Docs**: See `OLLAMA.md` and `website/docs/features/ai.md`

---

**Last Updated**: November 11, 2025  
**OpenRegister Version**: v0.2.7+

