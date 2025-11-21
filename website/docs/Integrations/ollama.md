# Ollama Integration

Integrate OpenRegister with Ollama to run local Large Language Models (LLMs) for AI-powered features like chat, agents, and RAG (Retrieval Augmented Generation).

## Overview

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

## Prerequisites

- Nextcloud 28+ with OpenRegister installed
- Docker and Docker Compose
- GPU recommended (8GB+ VRAM) for optimal performance
- At least 16GB RAM for larger models

## Quick Start

### Step 1: Start Ollama Container

The Ollama container is included in the docker-compose configuration:

```bash
# Start all services including Ollama
docker-compose up -d

# Or specifically start Ollama
docker-compose up -d ollama
```

### Step 2: Pull a Model

Pull one of the supported models:

```bash
# Llama 3.2 (8B) - Recommended, best balance
docker exec openregister-ollama ollama pull llama3.2

# Llama 3.2 (3B) - Lighter alternative
docker exec openregister-ollama ollama pull llama3.2:3b

# Mistral (7B) - Fast and efficient
docker exec openregister-ollama ollama pull mistral:7b

# Phi-3 Mini (3.8B) - Lightweight option
docker exec openregister-ollama ollama pull phi3:mini

# CodeLlama (7B) - Optimized for code
docker exec openregister-ollama ollama pull codellama:latest
```

### Step 3: Pull Embedding Model (for RAG)

If using RAG features, pull an embedding model:

```bash
# Nomic Embed Text - Recommended for embeddings
docker exec openregister-ollama ollama pull nomic-embed-text:latest

# Alternative: all-minilm (smaller, faster)
docker exec openregister-ollama ollama pull all-minilm:latest
```

### Step 4: Configure OpenRegister

1. Navigate to **Settings** → **OpenRegister** → **LLM Configuration**
2. Select **Ollama** as the chat provider
3. Configure the settings:

**Integrated Setup (docker-compose.yml):**
   - **Ollama URL**: `http://openregister-ollama:11434` ⚠️ **NOT** `http://localhost:11434`
   - **Chat Model**: `llama3.2:latest` (use full name including tag)
   - **Embedding Model**: `nomic-embed-text:latest`
   - **Why not localhost?** OpenRegister runs inside Nextcloud container; use container name instead

**Standalone Setup:**
   - **Via Docker Network**: `http://standalone-ollama:11434` (after connecting networks)
   - **Via Host IP**: `http://YOUR_HOST_IP:11434` (e.g., `http://192.168.1.100:11434`)
   - ❌ **NOT**: `http://localhost:11434` (won't work from inside container)

## Configuration Details

### Ollama Service Configuration

The Ollama service is configured in `docker-compose.yml`:

```yaml
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
    - OLLAMA_NUM_PARALLEL=4
    - OLLAMA_KEEP_ALIVE=30m
  deploy:
    resources:
      limits:
        memory: 16G
      reservations:
        memory: 8G
        devices:
          - driver: nvidia
            count: all
            capabilities: [gpu]
```

### Accessing Ollama

**Important: Docker Container Communication**

When configuring Ollama in OpenRegister, you MUST use the Docker service name, not `localhost`:

- ✅ **Correct (from Nextcloud container)**: `http://openregister-ollama:11434` or `http://ollama:11434`
- ❌ **Wrong**: `http://localhost:11434` (this only works from your host machine, not from inside containers)

**Access Points:**
- **From host machine** (terminal, browser): `http://localhost:11434`
- **From Nextcloud container** (OpenRegister settings): `http://openregister-ollama:11434`
- **From other Docker containers**: `http://openregister-ollama:11434`

**Why?** Inside a Docker container, `localhost` refers to the container itself, not your host machine. Containers communicate with each other using service names defined in docker-compose.yml.

### Model Naming

**Important**: Ollama models must be referenced with their full name including version tags:

- ✅ **Correct**: `mistral:7b`, `llama3.2:latest`, `phi3:mini`
- ❌ **Wrong**: `mistral`, `llama3.2`, `phi3` (without tags)

The OpenRegister UI dropdown shows full model names with tags to ensure compatibility.

## Recommended Models

### For Chat & Agents

| Model | Size | RAM Required | Speed | Quality | Use Case |
|-------|------|-------------|-------|---------|----------|
| **Llama 3.2 (8B)** | 8B | 16GB | ⚡⚡ | ⭐⭐⭐⭐ | General purpose, best balance |
| **Llama 3.2 (3B)** | 3B | 8GB | ⚡⚡⚡ | ⭐⭐⭐ | Fast, lightweight |
| **Mistral (7B)** | 7B | 16GB | ⚡⚡⚡ | ⭐⭐⭐⭐ | Fast and efficient |
| **Phi-3 Mini** | 3.8B | 8GB | ⚡⚡⚡ | ⭐⭐⭐ | Very fast, good quality |
| **CodeLlama** | 7B | 16GB | ⚡⚡ | ⭐⭐⭐⭐ | Code generation, technical |

### For Embeddings (RAG)

| Model | Size | Dimensions | Speed | Quality | Use Case |
|-------|------|------------|-------|---------|----------|
| **nomic-embed-text** | 137M | 768 | ⚡⚡⚡ | ⭐⭐⭐⭐ | General purpose, recommended |
| **all-minilm** | 22M | 384 | ⚡⚡⚡⚡ | ⭐⭐⭐ | Fast, smaller vectors |

## Use Cases

### 1. AI Chat

Enable conversational AI in OpenRegister:

```php
// Configured via Settings → LLM Configuration
// Select Ollama as provider
// Choose chat model (e.g., llama3.2:latest)
```

### 2. RAG (Retrieval Augmented Generation)

Answer questions using your data:

1. Configure embedding model: `nomic-embed-text:latest`
2. Vectorize your objects and files
3. Ask questions - AI retrieves relevant context and answers

### 3. Function Calling

Use Ollama with OpenRegister's function calling capabilities:

- Search objects
- Create objects
- Update objects
- Query registers

## Troubleshooting

### Ollama Container Won't Start

```bash
# Check logs
docker logs openregister-ollama

# Common issues:
# 1. Port 11434 already in use
sudo lsof -i :11434

# 2. Insufficient memory
docker stats openregister-ollama

# 3. GPU not available
docker exec openregister-ollama nvidia-smi
```

### Model Not Found

```bash
# List available models
docker exec openregister-ollama ollama list

# Pull missing model
docker exec openregister-ollama ollama pull llama3.2:latest

# Verify model name includes tag
docker exec openregister-ollama ollama show llama3.2:latest
```

### Connection Errors from OpenRegister

**Problem**: OpenRegister can't connect to Ollama.

**Solutions**:
1. Verify Ollama URL uses container name: `http://openregister-ollama:11434`
2. Check containers are on same Docker network: `docker network ls`
3. Test connection from Nextcloud container:
   ```bash
   docker exec <nextcloud-container> curl http://openregister-ollama:11434/api/tags
   ```

### Slow Performance

**Solutions**:
1. Use GPU acceleration (10-100x faster)
2. Choose smaller model (3B instead of 8B)
3. Increase `OLLAMA_KEEP_ALIVE` to keep models loaded
4. Use quantization (smaller model variants)

## Performance Optimization

### GPU Acceleration

For best performance, use GPU:

```yaml
deploy:
  resources:
    devices:
      - driver: nvidia
        count: all
        capabilities: [gpu]
```

**Performance Gain**: 10-100x faster inference with GPU

### Model Loading

Keep models loaded in memory:

```yaml
environment:
  - OLLAMA_KEEP_ALIVE=30m  # Keep models loaded for 30 minutes
```

### Parallel Requests

Handle multiple requests:

```yaml
environment:
  - OLLAMA_NUM_PARALLEL=4  # Process 4 requests simultaneously
```

## API Usage

### Direct API Calls

Test Ollama directly:

```bash
# List models
curl http://localhost:11434/api/tags

# Chat completion
curl http://localhost:11434/api/chat -d '{
  "model": "llama3.2:latest",
  "messages": [
    {"role": "user", "content": "Hello!"}
  ]
}'

# Generate embeddings
curl http://localhost:11434/api/embeddings -d '{
  "model": "nomic-embed-text:latest",
  "prompt": "Your text here"
}'
```

## Further Reading

- [Ollama Official Documentation](https://ollama.ai/docs)
- [LLPhant Ollama Integration](../development/ollama.md)
- [RAG Implementation](../features/rag-implementation.md)
- [AI Chat Features](../features/ai.md)

## Support

For issues specific to:
- **Ollama setup**: Check [Ollama Documentation](https://ollama.ai/docs)
- **OpenRegister integration**: OpenRegister GitHub issues
- **Model selection**: See recommended models above



