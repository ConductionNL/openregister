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

GPU acceleration provides **10-100x speedup** compared to CPU inference. This dramatically improves:
- Model loading time (60s → 5s)
- First token latency (10s → 1s)  
- Inference speed (5 tokens/sec → 50-200 tokens/sec)
- Ability to run larger models smoothly

### Prerequisites

1. **NVIDIA GPU** with CUDA support (check https://developer.nvidia.com/cuda-gpus)
2. **NVIDIA drivers** installed on Windows/Linux host (version 525+ recommended)
3. **WSL2** (if using Windows - included in Windows 10/11)
4. **Docker** with NVIDIA Container Toolkit support
5. **NVIDIA Container Toolkit** for Docker

### Step 1: Verify WSL GPU Support (Windows + WSL)

First, verify your GPU is accessible from WSL2:

```bash
# Check GPU is visible in WSL
nvidia-smi

# Expected output:
# +---------------------------------------------------------------------------------------+
# | NVIDIA-SMI 546.30      Driver Version: 546.30       CUDA Version: 12.3     |
# |-----------------------------------------+----------------------+----------------------+
# | GPU  Name                     TCC/WDDM  | Bus-Id        Disp.A | Volatile Uncorr. ECC |
# |   0  NVIDIA GeForce RTX 3070 ...  WDDM  | 00000000:01:00.0  On |                  N/A |
# +---------------------------------------------------------------------------------------+
```

**Troubleshooting if nvidia-smi fails:**

```bash
# Update NVIDIA drivers on Windows host first
# Then update WSL2 kernel
wsl --update

# Restart WSL
wsl --shutdown
# Then reopen WSL terminal

# Test again
nvidia-smi
```

### Step 2: Verify Docker GPU Support

Check if Docker can access the GPU:

```bash
# Test GPU passthrough to Docker containers
docker run --rm --gpus all nvidia/cuda:12.3.0-base-ubuntu20.04 nvidia-smi

# Should show the same GPU information as above
```

**If this fails**, you need to install NVIDIA Container Toolkit:

```bash
# For Ubuntu/Debian (in WSL or Linux)
distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
curl -s -L https://nvidia.github.io/nvidia-docker/gpgkey | sudo apt-key add -
curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.list | \
  sudo tee /etc/apt/sources.list.d/nvidia-docker.list

sudo apt-get update
sudo apt-get install -y nvidia-container-toolkit
sudo systemctl restart docker

# Test again
docker run --rm --gpus all nvidia/cuda:12.3.0-base-ubuntu20.04 nvidia-smi
```

For other operating systems, see: https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/install-guide.html

### Step 3: Enable GPU in Docker Compose

The GPU configuration is already included in `docker-compose.ollama.yml` (starting from v0.2.7+). Verify it contains:

```yaml
services:
  ollama:
    image: ollama/ollama:latest
    container_name: standalone-ollama
    # ... other config ...
    deploy:
      resources:
        limits:
          memory: 16G
        reservations:
          memory: 8G
          devices:
            - driver: nvidia
              count: all  # Use all available GPUs (or count: 1 for single GPU)
              capabilities: [gpu]
```

If not present, add the `devices` section under `deploy.resources.reservations`.

### Step 4: Apply GPU Configuration

Restart Ollama with GPU support:

```bash
# Stop and remove old container
docker-compose -f docker-compose.ollama.yml down

# Start with GPU configuration
docker-compose -f docker-compose.ollama.yml up -d

# Check container status
docker-compose -f docker-compose.ollama.yml ps
```

### Step 5: Verify GPU is Working

Run these verification commands:

```bash
# 1. Check GPU is accessible inside container
docker exec standalone-ollama nvidia-smi

# Should show your GPU information from inside the container
# If this shows "command not found", GPU is NOT configured!

# 2. Verify Docker GPU device configuration
docker inspect standalone-ollama --format '{{json .HostConfig.DeviceRequests}}' | python3 -m json.tool

# Should show:
# [
#     {
#         "Driver": "",
#         "Count": -1,
#         "Capabilities": [["gpu"]],
#         ...
#     }
# ]
# If it shows 'null', container was created without GPU support

# 3. Check Ollama logs for GPU detection
docker logs standalone-ollama 2>&1 | grep -i "inference compute"

# Should show something like:
# inference compute id=GPU-xxx library=CUDA compute=8.6 name=CUDA0
# description="NVIDIA GeForce RTX 3070 Laptop GPU"
# total="8.0 GiB" available="6.2 GiB"

# 4. Test inference speed (should be MUCH faster with GPU)
time docker exec standalone-ollama ollama run llama3.2 "What is 2+2?"

# GPU: ~5-10 seconds total
# CPU: ~60+ seconds total
```

### Performance Comparison

| Metric | CPU Mode | GPU Mode | Improvement |
|--------|----------|----------|-------------|
| **Model Loading** | 30-60s | 2-5s | **10-12x faster** |
| **First Token** | 5-10s | 0.5-1s | **5-10x faster** |
| **Tokens/Second** | 2-5 | 50-200 | **10-100x faster** |
| **Concurrent Requests** | 1-2 | 4-8 | **4x better** |
| **8B Model Usability** | Poor | Excellent | Production-ready |
| **70B Model** | Impossible | Possible | With 24GB+ VRAM |

### GPU Troubleshooting

#### Issue: 'nvidia-smi: command not found' inside container

**Diagnosis:** GPU was NOT configured when container was created.

```bash
# Confirm GPU is not configured
docker inspect standalone-ollama --format '{{json .HostConfig.DeviceRequests}}'

# If output is 'null', GPU is not configured
```

**Solution:**

```bash
# Remove container completely
docker stop standalone-ollama
docker rm standalone-ollama

# Verify docker-compose.ollama.yml has GPU config (see Step 3)
# Then recreate container
docker-compose -f docker-compose.ollama.yml up -d

# Verify again
docker exec standalone-ollama nvidia-smi
```

#### Issue: GPU not detected in Ollama logs

**Diagnosis:** GPU is passed to container but Ollama can't use it.

```bash
# Check Ollama startup logs
docker logs standalone-ollama 2>&1 | grep -A 10 "discovering available GPUs"

# Check for errors like:
# - "no GPUs detected"
# - "Failed to initialize NVML"
# - "CUDA error"
```

**Common Causes:**

1. **Driver version mismatch**
   ```bash
   # Check CUDA version compatibility
   nvidia-smi | grep "CUDA Version"
   
   # Update NVIDIA drivers on host
   # Then restart WSL:
   wsl --shutdown
   # Restart Docker Desktop
   # Recreate container
   ```

2. **CUDA libraries missing**
   ```bash
   # Use the official ollama image (not custom builds)
   docker pull ollama/ollama:latest
   docker-compose -f docker-compose.ollama.yml up -d
   ```

3. **Multiple GPU drivers conflict**
   ```bash
   # Disable integrated GPU in BIOS (if using laptop with dual GPU)
   # Or specify exact GPU:
   # In docker-compose.yml: count: 1 instead of count: all
   ```

#### Issue: Out of VRAM / GPU Memory

```bash
# Check GPU memory usage
nvidia-smi

# If VRAM is full:
# +-----------------------------------------------------------------------------+
# |   0  NVIDIA GeForce RTX 3070   | ... | 7800MiB / 8192MiB | 95% | ...      |
# +-----------------------------------------------------------------------------+
```

**Solutions:**

```bash
# 1. Reduce loaded models
# Edit docker-compose.ollama.yml:
environment:
  - OLLAMA_MAX_LOADED_MODELS=1  # Only keep 1 model in VRAM

# 2. Reduce keep-alive time
environment:
  - OLLAMA_KEEP_ALIVE=5m  # Unload after 5 minutes of inactivity

# 3. Use smaller models
docker exec standalone-ollama ollama pull llama3.2:3b  # Instead of 8B

# 4. Restart container to clear VRAM
docker restart standalone-ollama

# 5. For 70B models, need 24GB+ VRAM
# Consider cloud GPU instance or use quantized versions
```

#### Issue: Slow inference despite GPU

```bash
# 1. Verify GPU is actually being used
nvidia-smi -l 1  # Monitor GPU usage in real-time
# Then run: docker exec standalone-ollama ollama run llama3.2 "test"
# GPU-Util should spike to 70-100%

# 2. Check if model is loaded in GPU memory
docker logs standalone-ollama 2>&1 | grep "loaded"

# 3. Check GPU compute mode
nvidia-smi -q | grep "Compute Mode"
# Should be "Default" (not "Prohibited")

# 4. Ensure model fits in VRAM
# 8B models need ~6GB VRAM
# 70B models need ~40GB VRAM
```

#### Issue: 'Failed to initialize NVML: Unknown Error'

**Cause:** NVIDIA Management Library (NVML) initialization failed.

**Solution:**

```bash
# 1. Update NVIDIA drivers on Windows host
# Download from: https://www.nvidia.com/Download/index.aspx

# 2. Restart WSL and Docker
wsl --shutdown
# Restart Docker Desktop
# Wait 30 seconds

# 3. Verify driver version
nvidia-smi

# 4. Recreate container
docker-compose -f docker-compose.ollama.yml down
docker-compose -f docker-compose.ollama.yml up -d
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

**Last Updated**: November 12, 2025  
**OpenRegister Version**: v0.2.7+  
**GPU Support**: Enabled by default (v0.2.7+)

