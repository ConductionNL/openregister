# Hugging Face Integration

Integrate OpenRegister with Hugging Face Text Generation Inference (TGI) or vLLM to run Mistral and other Hugging Face models locally with an OpenAI-compatible API.

## Overview

Hugging Face provides two options for running local LLMs with OpenAI-compatible APIs:

- **Text Generation Inference (TGI)**: Official Hugging Face solution, optimized for production
- **vLLM**: Alternative with better throughput, full OpenAI API compatibility

Both provide:
- ✅ **OpenAI-Compatible API** - Drop-in replacement for OpenAI
- ✅ **Privacy-First** - All data stays local
- ✅ **Cost-Free** - No API fees
- ✅ **Fast** - Optimized inference engines
- ✅ **Flexible** - Choose any Hugging Face model

## Comparison

| Feature | TGI | vLLM | Ollama |
|---------|-----|------|--------|
| **OpenAI API** | ✅ Yes (v1.4.0+) | ✅ Yes | ❌ No |
| **Speed** | ⚡⚡ Fast | ⚡⚡⚡ Very Fast | ⚡ Good |
| **Models** | Hugging Face | Hugging Face | Curated list |
| **Setup** | Medium | Medium | Easy |
| **Memory** | 8-16GB | 8-16GB | 8-16GB |
| **Use Case** | Production | High throughput | Simple setup |

## Prerequisites

- Nextcloud 28+ with OpenRegister installed
- Docker and Docker Compose
- GPU recommended (8GB+ VRAM) for optimal performance
- At least 16GB RAM for larger models

## Quick Start

### Option 1: Text Generation Inference (TGI) - Recommended

**Pros**:
- Official Hugging Face solution
- Well-maintained and documented
- Optimized for production
- Automatic quantization

**Installation**:

```bash
# Start TGI with Mistral
docker-compose -f docker-compose.huggingface.yml up -d tgi-mistral

# Wait for model download (~15GB for Mistral 7B)
docker logs -f openregister-tgi-mistral
```

**Configuration in OpenRegister**:
1. Navigate to **Settings** → **OpenRegister** → **LLM Configuration**
2. Select **OpenAI** as provider (TGI is OpenAI-compatible)
3. Configure:
   - **Base URL**: `http://tgi-mistral:80` (from Nextcloud container)
   - **Model**: `mistral-7b-instruct`
   - **API Key**: `dummy` (not used for local)

### Option 2: vLLM - Alternative

**Pros**:
- Faster inference
- Better throughput for multiple requests
- Full OpenAI API compatibility
- PagedAttention optimization

**Installation**:

```bash
# Start vLLM with Mistral
docker-compose -f docker-compose.huggingface.yml up -d vllm-mistral

# Wait for model download
docker logs -f openregister-vllm-mistral
```

**Configuration in OpenRegister**:
1. Navigate to **Settings** → **OpenRegister** → **LLM Configuration**
2. Select **OpenAI** as provider
3. Configure:
   - **Base URL**: `http://vllm-mistral:8000` (from Nextcloud container)
   - **Model**: `mistral-7b-instruct`
   - **API Key**: `dummy` (not used for local)

## Configuration Details

### TGI Service Configuration

```yaml
tgi-mistral:
  image: ghcr.io/huggingface/text-generation-inference:latest
  container_name: openregister-tgi-mistral
  restart: always
  ports:
    - "8081:80"
  volumes:
    - tgi-models:/data
  environment:
    - MODEL_ID=mistralai/Mistral-7B-Instruct-v0.1
    - MAX_INPUT_LENGTH=4096
    - MAX_TOTAL_TOKENS=8192
    - MAX_CONCURRENT_REQUESTS=128
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

### vLLM Service Configuration

```yaml
vllm-mistral:
  image: vllm/vllm-openai:latest
  container_name: openregister-vllm-mistral
  restart: always
  ports:
    - "8082:8000"
  volumes:
    - vllm-models:/root/.cache/huggingface
  environment:
    - MODEL_NAME=mistralai/Mistral-7B-Instruct-v0.1
    - TENSOR_PARALLEL_SIZE=1
    - GPU_MEMORY_UTILIZATION=0.9
    - SERVED_MODEL_NAME=mistral-7b-instruct
```

## Available Models

### Recommended Models for OpenRegister

| Model | Size | Use Case | Memory Required |
|-------|------|----------|-----------------|
| **Mistral-7B-Instruct-v0.2** | 7B | General purpose, RAG | 16GB |
| **Mixtral-8x7B-Instruct** | 47B | High quality, complex | 48GB+ |
| **Llama-3-8B-Instruct** | 8B | General purpose | 16GB |
| **Phi-3-mini-instruct** | 3.8B | Fast, lightweight | 8GB |
| **Qwen2-7B-Instruct** | 7B | Multilingual, code | 16GB |

### Changing the Model

Edit `docker-compose.huggingface.yml`:

**For TGI**:
```yaml
tgi-mistral:
  environment:
    - MODEL_ID=mistralai/Mistral-7B-Instruct-v0.2  # Change this
```

**For vLLM**:
```yaml
vllm-mistral:
  environment:
    - MODEL_NAME=mistralai/Mistral-7B-Instruct-v0.2  # Change this
  command:
    - --model
    - mistralai/Mistral-7B-Instruct-v0.2  # Change this too
```

Then restart the service:
```bash
docker-compose -f docker-compose.huggingface.yml restart tgi-mistral
# or
docker-compose -f docker-compose.huggingface.yml restart vllm-mistral
```

## API Usage

### Testing the API

**TGI (port 8081)**:
```bash
curl http://localhost:8081/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "mistral-7b-instruct",
    "messages": [
      {"role": "user", "content": "Hello! What is the capital of France?"}
    ],
    "max_tokens": 100
  }'
```

**vLLM (port 8082)**:
```bash
curl http://localhost:8082/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "mistral-7b-instruct",
    "messages": [
      {"role": "user", "content": "Hello! What is the capital of France?"}
    ],
    "max_tokens": 100
  }'
```

### Using with LLPhant (PHP)

LLPhant's OpenAI client can point to TGI/vLLM:

```php
use LLPhant\Chat\OpenAIChat;
use OpenAI\Client;

// Create OpenAI client pointing to TGI/vLLM
$client = Client::factory()
    ->withBaseUri('http://tgi-mistral:80')  // or http://vllm-mistral:8000
    ->withHttpHeader('Content-Type', 'application/json')
    ->make();

// Use with LLPhant
$chat = new OpenAIChat($client);
$response = $chat->generateText('What is the capital of France?');
```

## Use Cases

### 1. AI Chat

Enable conversational AI using local models:

1. Configure TGI or vLLM
2. Set OpenAI provider with local base URL
3. Use chat features in OpenRegister

### 2. RAG (Retrieval Augmented Generation)

Answer questions using your data:

1. Configure embedding model (separate from chat)
2. Vectorize your objects and files
3. Ask questions - AI retrieves relevant context

### 3. Function Calling

Use Mistral with OpenRegister's function calling:

- Search objects
- Create objects
- Update objects
- Query registers

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker logs openregister-tgi-mistral
# or
docker logs openregister-vllm-mistral

# Common issues:
# 1. Port already in use
sudo lsof -i :8081  # TGI
sudo lsof -i :8082  # vLLM

# 2. Insufficient memory
docker stats openregister-tgi-mistral

# 3. GPU not available
docker exec openregister-tgi-mistral nvidia-smi
```

### Model Download Fails

```bash
# Check internet connection
docker exec openregister-tgi-mistral ping -c 3 huggingface.co

# For gated models, set Hugging Face token:
# Edit docker-compose.huggingface.yml:
environment:
  - HUGGING_FACE_HUB_TOKEN=your_token_here
```

### Connection Errors from OpenRegister

**Problem**: OpenRegister can't connect to TGI/vLLM.

**Solutions**:
1. Verify base URL uses container name: `http://tgi-mistral:80`
2. Check containers are on same Docker network
3. Test connection from Nextcloud container:
   ```bash
   docker exec <nextcloud-container> curl http://tgi-mistral:80/health
   ```

### Slow Performance

**Solutions**:
1. Use GPU acceleration (10-100x faster)
2. Choose smaller model (3B instead of 7B)
3. Increase `MAX_CONCURRENT_REQUESTS` for TGI
4. Adjust `GPU_MEMORY_UTILIZATION` for vLLM

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

### Concurrent Requests

**TGI**:
```yaml
environment:
  - MAX_CONCURRENT_REQUESTS=128  # Increase for more parallel requests
```

**vLLM**:
```yaml
environment:
  - GPU_MEMORY_UTILIZATION=0.9  # Use 90% of GPU memory
```

## Further Reading

- [Hugging Face TGI Documentation](https://huggingface.co/docs/text-generation-inference)
- [vLLM Documentation](https://docs.vllm.ai)
- [Mistral Model Documentation](../Integrations/mistral.md)
- [RAG Implementation](../features/rag-implementation.md)

## Support

For issues specific to:
- **TGI setup**: Check [TGI Documentation](https://huggingface.co/docs/text-generation-inference)
- **vLLM setup**: Check [vLLM Documentation](https://docs.vllm.ai)
- **OpenRegister integration**: OpenRegister GitHub issues

