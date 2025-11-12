# Using Ollama with OpenRegister

OpenRegister supports Ollama for running local Large Language Models (LLMs) for AI-powered features like chat, agents, and RAG (Retrieval Augmented Generation).

## What is Ollama?

Ollama allows you to run open-source LLMs locally on your machine, providing:
- **Privacy**: Your data never leaves your infrastructure
- **Cost-effective**: No API costs for inference
- **Customization**: Run any compatible model
- **Offline capability**: Works without internet connection

### Technical Implementation

OpenRegister uses LLPhant's native Ollama support, which provides:
- **Direct API Integration**: Uses Ollama's native `/api/` endpoints (not OpenAI-compatible layer)
- **Better Performance**: Optimized for Ollama's specific API structure
- **Simpler Configuration**: No need for API key workarounds
- **Full Feature Support**: Native support for chat, embeddings, and function calling

## Quick Start

### 1. Start Ollama Container

The Ollama container is included in the docker-compose configuration:

'''bash
# Start all services including Ollama
docker-compose up -d

# Or specifically start Ollama
docker-compose up -d ollama
'''

### 2. Pull a Model

Pull one of the supported models:

'''bash
# Llama 2 (7B) - Good balance of performance and quality
docker exec openregister-ollama ollama pull llama2

# Llama 3.1 (8B) - Latest Llama model
docker exec openregister-ollama ollama pull llama3.1

# Mistral (7B) - Fast and efficient
docker exec openregister-ollama ollama pull mistral

# Phi-3 Mini (3.8B) - Lightweight option
docker exec openregister-ollama ollama pull phi3

# CodeLlama (7B) - Optimized for code
docker exec openregister-ollama ollama pull codellama
'''

### 3. Configure OpenRegister

1. Navigate to **Settings** → **OpenRegister** → **AI Settings**
2. Select **Ollama** as the chat provider
3. Configure the settings based on your setup:

**Integrated Setup (docker-compose.yml):**
   - **Ollama URL**: `http://openregister-ollama:11434` ⚠️ **NOT** `http://localhost:11434`
   - **Chat Model**: `llama3.2` (or model you pulled)
   - **Embedding Model**: `nomic-embed-text`
   - **Why not localhost?** OpenRegister runs inside Nextcloud container; use container name instead

**Standalone Setup (docker-compose.ollama.yml):**
   - **Via Docker Network** (recommended): `http://standalone-ollama:11434` (after connecting networks)
   - **Via Host IP**: `http://YOUR_HOST_IP:11434` (e.g., `http://192.168.1.100:11434`)
   - **Different Host**: `http://your-server-ip:11434`
   - ❌ **NOT**: `http://localhost:11434` (won't work from inside container)
   - **Chat Model**: `llama3.2`
   - **Embedding Model**: `nomic-embed-text`

### 4. Pull Embedding Model (for RAG)

If using RAG features, pull an embedding model:

'''bash
# Nomic Embed Text - Recommended for embeddings
docker exec openregister-ollama ollama pull nomic-embed-text

# Alternative: all-minilm (smaller, faster)
docker exec openregister-ollama ollama pull all-minilm
'''

## Configuration Details

### Ollama Service Configuration

The Ollama service is configured in `docker-compose.yml`:

'''yaml
ollama:
  image: ollama/ollama:latest
  container_name: openregister-ollama
  restart: always
  ports:
    - "11434:11434"
  volumes:
    - ollama:/root/.ollama
  environment:
    - OLLAMA_HOST=0.0.0.0
  healthcheck:
    test: ["CMD-SHELL", "curl -f http://localhost:11434/api/tags || exit 1"]
    interval: 30s
    timeout: 10s
    retries: 3
'''

### Accessing Ollama

**Important: Docker Container Communication**

When configuring Ollama in OpenRegister, you MUST use the Docker service name, not `localhost`:

- ✅ **Correct (from Nextcloud container)**: `http://openregister-ollama:11434` or `http://ollama:11434`
- ❌ **Wrong**: `http://localhost:11434` (this only works from your host machine, not from inside containers)

**Access Points:**
- **From host machine** (terminal, browser): `http://localhost:11434`
- **From Nextcloud container** (OpenRegister settings): `http://openregister-ollama:11434` or `http://ollama:11434`
- **From other Docker containers**: `http://openregister-ollama:11434`

**Why?** Inside a Docker container, `localhost` refers to the container itself, not your host machine. Containers communicate with each other using service names defined in docker-compose.yml.

### OpenRegister Configuration

Configure in the OpenRegister settings UI or via config:

'''json
{
  "llm": {
    "chatProvider": "ollama",
    "ollamaConfig": {
      "url": "http://openregister-ollama:11434",
      "chatModel": "llama3.2",
      "embeddingModel": "nomic-embed-text",
      "temperature": 0.7
    }
  }
}
'''

**Note:** Use `http://openregister-ollama:11434` (container name) or `http://ollama:11434` (service name), NOT `http://localhost:11434`.

## Recommended Models

### For Chat & Agents

| Model | Size | RAM Required | Speed | Quality | Use Case |
|-------|------|-------------|-------|---------|----------|
| **llama3.2:8b** ⭐ | 4.7GB | 8-16GB | Fast | Excellent | **RECOMMENDED** - Latest, best balance |
| **llama3.2:3b** | 2.0GB | 4-8GB | Very Fast | Very Good | Lighter, faster alternative |
| **llama3.1:8b** | 4.7GB | 8-16GB | Fast | Excellent | Previous gen, still great |
| **llama3.2:70b** 🔥 | 40GB | 64-80GB | Slow | Best | **HIGH-END** - Maximum quality |
| **mistral:7b** | 4.1GB | 8GB | Very Fast | Very Good | Fast responses |
| **phi3:mini** | 2.3GB | 4GB | Very Fast | Good | Low-resource environments |
| **codellama:7b** | 3.8GB | 8GB | Fast | Excellent (code) | Code generation/analysis |

**Memory Configuration Notes:**
- Our docker-compose files are configured with **16GB limit** (suitable for 8B models)
- For 70B models, increase memory limit to **80GB** in docker-compose
- Shared memory (`shm_size`) set to **2GB** for efficient model loading

### For Embeddings (RAG)

| Model | Size | Dimensions | Use Case |
|-------|------|-----------|----------|
| **nomic-embed-text** | 274MB | 768 | Recommended for most uses |
| **all-minilm** | 45MB | 384 | Lightweight, faster |
| **mxbai-embed-large** | 670MB | 1024 | Highest quality |

## Using Ollama Features

### 1. Chat

Once configured, the chat feature works automatically:

1. Go to any page in OpenRegister
2. Click the chat icon in the sidebar
3. Start a conversation with your local AI

### 2. RAG (Retrieval Augmented Generation)

Enable RAG for agents to search your documents:

1. Create/edit an agent
2. Enable **RAG** in agent settings
3. Configure:
   - **Search Mode**: Vector, Hybrid, or Full-text
   - **Number of Sources**: How many documents to retrieve
   - **Include Files**: Search uploaded files
   - **Include Objects**: Search OpenRegister objects

### 3. Agents with Tools

Create agents that can interact with your data:

1. Create a new agent
2. Enable tools:
   - **Register Tool**: Query registers
   - **Schema Tool**: Access schemas
   - **Objects Tool**: Manipulate objects
3. Set the system prompt
4. Chat with the agent

## Managing Models

### List Installed Models

'''bash
docker exec openregister-ollama ollama list
'''

### Remove a Model

'''bash
docker exec openregister-ollama ollama rm llama2
'''

### Update a Model

'''bash
docker exec openregister-ollama ollama pull llama2
'''

### Check Ollama Status

'''bash
# Check if Ollama is running
curl http://localhost:11434/api/tags

# View running models
docker exec openregister-ollama ollama ps
'''

## Performance Tuning

### GPU Support

GPU acceleration can provide **10-100x speedup** for model inference, dramatically improving response times and enabling larger models to run smoothly.

#### Prerequisites

1. **NVIDIA GPU** with CUDA support (check compatibility at https://developer.nvidia.com/cuda-gpus)
2. **NVIDIA drivers** installed on Windows/Linux host
3. **WSL2** (if using Windows with WSL)
4. **NVIDIA Container Toolkit** for Docker

#### Verify WSL GPU Support (Windows + WSL)

First, check if your GPU is accessible from WSL2:

'''bash
# Check GPU is visible in WSL
nvidia-smi

# Should show your GPU, driver version, and CUDA version
# Example output:
# NVIDIA-SMI 546.30    Driver Version: 546.30    CUDA Version: 12.3
# GPU Name: NVIDIA GeForce RTX 3070 Laptop GPU
'''

If 'nvidia-smi' is not found, you need to:
1. Install latest NVIDIA drivers on Windows host
2. Update WSL2 kernel: 'wsl --update'
3. Restart WSL: 'wsl --shutdown' then reopen

#### Verify Docker GPU Support

Check if Docker can access the GPU:

'''bash
# Test GPU access from Docker
docker run --rm --gpus all nvidia/cuda:12.3.0-base-ubuntu20.04 nvidia-smi

# Should show the same GPU information
# If this fails, install NVIDIA Container Toolkit:
# https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/install-guide.html
'''

#### Enable GPU in Docker Compose

The GPU configuration is already included in our docker-compose files. Update the ollama service configuration:

'''yaml
ollama:
  image: ollama/ollama:latest
  container_name: openregister-ollama
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
  # ... rest of configuration
'''

#### Apply GPU Configuration

If using docker-compose:

'''bash
# Stop and remove the old container
docker stop openregister-ollama
docker rm openregister-ollama

# Recreate with GPU support
docker-compose up -d ollama
'''

If using docker run (alternative method):

'''bash
# Stop old container
docker stop openregister-ollama
docker rm openregister-ollama

# Start with GPU support
docker run -d \
  --name openregister-ollama \
  --restart always \
  -p 11434:11434 \
  -v openregister_ollama:/root/.ollama \
  -e OLLAMA_HOST=0.0.0.0 \
  -e OLLAMA_NUM_PARALLEL=4 \
  -e OLLAMA_KEEP_ALIVE=30m \
  --shm-size=2gb \
  --gpus all \
  ollama/ollama:latest
'''

#### Verify GPU is Working

After restarting Ollama with GPU support:

'''bash
# 1. Check GPU is accessible inside container
docker exec openregister-ollama nvidia-smi

# Should show GPU information from inside the container

# 2. Check Docker device configuration
docker inspect openregister-ollama --format '{{json .HostConfig.DeviceRequests}}'

# Should show: [{"Driver":"","Count":-1,"DeviceIDs":null,"Capabilities":[["gpu"]],"Options":{}}]

# 3. Check Ollama logs for GPU detection
docker logs openregister-ollama | grep -i gpu

# Should show lines like:
# inference compute id=GPU-xxx library=CUDA compute=8.6 name=CUDA0
# description="NVIDIA GeForce RTX 3070 Laptop GPU"
# total="8.0 GiB" available="6.2 GiB"

# 4. Test inference speed (should be much faster)
docker exec openregister-ollama ollama run llama3.2 "What is 2+2?"
'''

#### Performance Comparison

| Mode | Loading Time | First Token | Tokens/sec | Use Case |
|------|-------------|-------------|------------|----------|
| **CPU** | 30-60s | 5-10s | 2-5 | Testing only |
| **GPU** | 2-5s | 0.5-1s | 50-200 | Production use |

#### GPU Troubleshooting

**Issue: 'nvidia-smi: command not found' in container**

This means GPU is NOT configured. Check:

'''bash
# Verify DeviceRequests is not null
docker inspect openregister-ollama --format '{{json .HostConfig.DeviceRequests}}'

# If null, container was created without GPU support
# Solution: Remove and recreate with --gpus flag or proper docker-compose config
'''

**Issue: GPU not detected in Ollama logs**

'''bash
# Check Ollama startup logs
docker logs openregister-ollama 2>&1 | grep -A 5 "discovering available GPUs"

# If no GPU found, check:
# 1. NVIDIA drivers installed on host
# 2. nvidia-smi works on host
# 3. Docker GPU support working (test with nvidia/cuda image)
'''

**Issue: 'Failed to initialize NVML: Unknown Error'**

'''bash
# This usually means driver mismatch
# Solution: Update NVIDIA drivers on host machine
# Then restart Docker and WSL:
wsl --shutdown
# Restart Docker Desktop
# Recreate Ollama container
'''

**Issue: Out of VRAM**

'''bash
# Check GPU memory usage
nvidia-smi

# If VRAM is full:
# 1. Use smaller model (3B instead of 8B)
# 2. Reduce OLLAMA_MAX_LOADED_MODELS
# 3. Set shorter OLLAMA_KEEP_ALIVE (e.g., 5m)
# 4. Restart container to clear VRAM
docker restart openregister-ollama
'''

### Memory Configuration

Adjust model context size in OpenRegister settings:

- **Max Tokens**: Control response length (default: 2048)
- **Temperature**: Control randomness (0.0-1.0, default: 0.7)

### Concurrent Requests

Ollama can handle multiple concurrent requests. Monitor resource usage:

'''bash
# Check container resource usage
docker stats openregister-ollama
'''

## Troubleshooting

### Connection Error: "Failed to connect to localhost port 11434"

**Symptoms:**
- Error: "cURL error 7: Failed to connect to localhost port 11434"
- Error: "Could not connect to server"
- Chat test fails with connection refused

**Cause:** You're using `http://localhost:11434` in OpenRegister settings, but inside the Nextcloud container, `localhost` refers to the container itself, not the host machine.

**Solution:**

1. **Update Ollama URL in OpenRegister settings:**
   - Go to **Settings** → **OpenRegister** → **AI Settings**
   - Change Ollama URL from `http://localhost:11434` to `http://openregister-ollama:11434`
   - Click **Save** and **Test Connection**

2. **Verify containers are on the same network:**

'''bash
# Check if Nextcloud can reach Ollama
docker exec master-nextcloud-1 curl -s http://openregister-ollama:11434/api/tags

# Should return JSON with model list
'''

3. **If still failing, connect containers to the same network:**

'''bash
# Find your Nextcloud network
docker network ls | grep master

# Connect Ollama to the same network
docker network connect master_default openregister-ollama

# Test again
docker exec master-nextcloud-1 curl -s http://openregister-ollama:11434/api/tags
'''

**Remember:** Always use the **container/service name**, not `localhost`, when configuring services inside Docker.

### Ollama Container Not Starting

'''bash
# Check logs
docker logs openregister-ollama

# Restart the container
docker-compose restart ollama
'''

### Model Not Found

'''bash
# List available models
docker exec openregister-ollama ollama list

# Pull the model if missing
docker exec openregister-ollama ollama pull llama3.2
'''

### Out of Memory

1. Use a smaller model (phi3, mistral)
2. Reduce max tokens in settings
3. Allocate more RAM to Docker
4. Stop other services temporarily

### Slow Responses

1. Use a faster model (mistral, phi3)
2. Reduce context window
3. Enable GPU support
4. Check system resources: `docker stats`

### Connection Issues from Nextcloud

Make sure the Nextcloud container can reach Ollama:

'''bash
# Test from Nextcloud container
docker exec nextcloud curl http://ollama:11434/api/tags

# Should return JSON with model list
'''

## Model Recommendations by Use Case

### General Customer Support
- **Model**: llama3.1:8b or mistral:7b
- **Why**: Good balance of quality and speed

### Technical Documentation
- **Model**: codellama:7b
- **Why**: Better understanding of technical content

### Low Resource Environments
- **Model**: phi3:mini
- **Why**: Smallest footprint while maintaining quality

### Maximum Quality
- **Model**: llama3.1:70b (requires 64GB+ RAM)
- **Why**: Best possible responses (if you have the resources)

## Security Considerations

1. **Network Isolation**: Ollama is only accessible within the Docker network by default
2. **No External API Calls**: All processing happens locally
3. **Data Privacy**: Your data never leaves your infrastructure
4. **Model Safety**: Use official models from Ollama library

## Resources

- **Ollama Documentation**: https://ollama.ai/
- **Model Library**: https://ollama.ai/library
- **GitHub**: https://github.com/ollama/ollama
- **OpenRegister AI Docs**: See `website/docs/features/ai.md`

## FAQ

**Q: Can I use multiple models simultaneously?**  
A: Yes, configure different models for chat vs embeddings.

**Q: How much disk space do I need?**  
A: Plan for 5-10GB per model. Embedding models are smaller (~500MB).

**Q: Can I use custom models?**  
A: Yes, see Ollama documentation on creating custom Modelfiles.

**Q: Does it work on ARM (Apple Silicon)?**  
A: Yes, Ollama supports ARM64 architecture.

**Q: Can I use Ollama with other OpenRegister apps?**  
A: Yes, any app in the workspace can access the Ollama container.

---

**Last Updated**: November 12, 2025  
**OpenRegister Version**: v0.2.7+  
**Ollama Version**: Latest  
**GPU Support**: Enabled by default (v0.2.7+)

