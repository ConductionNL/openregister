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

To use GPU acceleration (NVIDIA):

1. Install NVIDIA Container Toolkit
2. Update docker-compose.yml:

'''yaml
ollama:
  image: ollama/ollama:latest
  deploy:
    resources:
      reservations:
        devices:
          - driver: nvidia
            count: 1
            capabilities: [gpu]
'''

3. Restart the container:

'''bash
docker-compose up -d ollama
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

**Last Updated**: November 11, 2025  
**OpenRegister Version**: v0.2.7+  
**Ollama Version**: Latest

