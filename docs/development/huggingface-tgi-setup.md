---
title: Hugging Face TGI/vLLM Setup - OpenAI-Compatible API
sidebar_position: 14
---

# Hugging Face Text Generation Inference (TGI) & vLLM Setup

## Overview

Run **Mistral** and other Hugging Face models locally with an **OpenAI-compatible API**. This allows OpenRegister's LLPhant library and other OpenAI clients to use local models instead of cloud APIs.

## Why Use TGI or vLLM?

### Benefits

- ✅ **OpenAI-Compatible API** - Drop-in replacement for OpenAI
- ✅ **Privacy-First** - All data stays local
- ✅ **Cost-Free** - No API fees
- ✅ **Fast** - Optimized inference engines
- ✅ **Flexible** - Choose any Hugging Face model

### Comparison

| Feature | TGI | vLLM | Ollama |
|---------|-----|------|--------|
| **OpenAI API** | ✅ Yes (v1.4.0+) | ✅ Yes | ❌ No |
| **Speed** | ⚡⚡ Fast | ⚡⚡⚡ Very Fast | ⚡ Good |
| **Models** | Hugging Face | Hugging Face | Curated list |
| **Setup** | Medium | Medium | Easy |
| **Memory** | 8-16GB | 8-16GB | 8-16GB |
| **Use Case** | Production | High throughput | Simple setup |

## Two Options

### Option 1: Text Generation Inference (TGI) - Recommended

**Pros**:
- Official Hugging Face solution
- Well-maintained and documented
- Optimized for production
- Automatic quantization

**Installation**:
```bash
# Use the huggingface profile in docker-compose.dev.yml
docker-compose -f docker-compose.dev.yml --profile huggingface up -d tgi-mistral
```

### Option 2: vLLM - Alternative

**Pros**:
- Faster inference
- Better throughput for multiple requests
- Full OpenAI API compatibility
- PagedAttention optimization

**Installation**:
```bash
# Use the huggingface profile in docker-compose.dev.yml
docker-compose -f docker-compose.dev.yml --profile huggingface up -d vllm-mistral
```

## Quick Start

### 1. Start TGI with Mistral

```bash
cd /path/to/openregister

# Start TGI (choose ONE) - using huggingface profile
docker-compose -f docker-compose.dev.yml --profile huggingface up -d tgi-mistral

# OR start vLLM (if configured)
docker-compose -f docker-compose.dev.yml --profile huggingface up -d vllm-mistral
```

### 2. Wait for Model Download

First startup downloads the model (~15GB for Mistral 7B):

```bash
# Watch logs
docker logs -f openregister-tgi-mistral

# You'll see:
# Downloading model...
# Model loaded successfully
# Serving on port 80
```

**Time**: 5-10 minutes depending on internet speed

### 3. Test the API

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

## Integration with OpenRegister

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

### Configuration in OpenRegister

**PHP Configuration** (`config/llm_config.php`):

```php
return [
    'llm' => [
        'provider' => 'openai',  // Use OpenAI-compatible client
        'base_url' => 'http://tgi-mistral:80',  // Local TGI
        // OR
        'base_url' => 'http://vllm-mistral:8000',  // Local vLLM
        
        'model' => 'mistral-7b-instruct',
        'api_key' => 'dummy',  // Not used for local, but required by some clients
        'timeout' => 30,
        'max_tokens' => 4096
    ]
];
```

### Direct API Calls (without LLPhant)

```php
// Simple curl-based API call
function callLocalLLM(string $prompt, string $baseUrl = 'http://tgi-mistral:80'): string
{
    $data = [
        'model' => 'mistral-7b-instruct',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 1000,
        'temperature' => 0.7
    ];
    
    $ch = curl_init($baseUrl . '/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    return $result['choices'][0]['message']['content'] ?? '';
}

// Usage
$answer = callLocalLLM('Explain GDPR in simple terms');
echo $answer;
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

Edit `docker-compose.dev.yml`:

**For TGI**:
```yaml
tgi-mistral:
  environment:
    # Change this line
    - MODEL_ID=mistralai/Mixtral-8x7B-Instruct-v0.1
```

**For vLLM** (if configured):
```yaml
vllm-mistral:
  command:
    - --model
    - mistralai/Mixtral-8x7B-Instruct-v0.1  # Change this
```

Then restart:
```bash
docker-compose -f docker-compose.dev.yml --profile huggingface up -d --force-recreate tgi-mistral
```

## OpenAI API Compatibility

### Supported Endpoints

**TGI** (v1.4.0+):
- ✅ `/v1/chat/completions` - Chat completions
- ✅ `/v1/completions` - Text completions
- ✅ `/health` - Health check
- ✅ `/info` - Model info

**vLLM**:
- ✅ `/v1/chat/completions` - Chat completions
- ✅ `/v1/completions` - Text completions
- ✅ `/v1/models` - List models
- ✅ `/health` - Health check
- ✅ `/metrics` - Prometheus metrics

### Compatibility Notes

**Works with**:
- ✅ LLPhant (PHP) - with custom base URL
- ✅ OpenAI Python library - `openai.base_url = "http://localhost:8081"`
- ✅ LangChain - OpenAI integration with custom base URL
- ✅ LlamaIndex - OpenAI LLM with custom base URL
- ✅ Any OpenAI-compatible client

**Features supported**:
- ✅ Streaming responses
- ✅ Temperature, top_p, max_tokens
- ✅ System messages
- ✅ Function calling (vLLM only)
- ❌ Embeddings (use separate embedding model)
- ❌ Image generation (not applicable)

## Performance Optimization

### GPU Acceleration

Both TGI and vLLM require NVIDIA GPU:

```bash
# Check GPU availability
nvidia-smi

# Test GPU in container
docker run --gpus all nvidia/cuda:12.1.0-base-ubuntu22.04 nvidia-smi
```

### Memory Management

**Mistral 7B Requirements**:
- **Minimum**: 8GB GPU VRAM (with quantization)
- **Recommended**: 16GB GPU VRAM
- **Optimal**: 24GB GPU VRAM

**Reduce memory usage**:

```yaml
# TGI with quantization
tgi-mistral:
  environment:
    - MODEL_ID=mistralai/Mistral-7B-Instruct-v0.1
    - QUANTIZE=bitsandbytes-nf4  # 4-bit quantization
```

```yaml
# vLLM with reduced memory
vllm-mistral:
  environment:
    - GPU_MEMORY_UTILIZATION=0.7  # Use 70% of GPU memory
```

### Batch Processing

For multiple requests:

**vLLM** has better batch processing:
```yaml
vllm-mistral:
  command:
    - --max-num-seqs
    - "256"  # Process up to 256 sequences in parallel
```

## Troubleshooting

### Model Download Fails

```bash
# Check logs
docker logs openregister-tgi-mistral

# If authentication required (gated models)
# Add Hugging Face token
docker-compose -f docker-compose.dev.yml --profile huggingface down
# Edit docker-compose.dev.yml:
# - HUGGING_FACE_HUB_TOKEN=hf_your_token_here
docker-compose -f docker-compose.dev.yml --profile huggingface up -d
```

### Out of Memory

```bash
# Reduce GPU memory usage
# For vLLM, set GPU_MEMORY_UTILIZATION=0.5

# OR use smaller model
# Change to phi-3-mini-instruct (3.8B) or similar
```

### Slow Inference

```bash
# Check if using GPU
docker logs openregister-tgi-mistral | grep "CUDA"

# Should see: "Using GPU: NVIDIA ..."
# If not, GPU is not available

# Ensure --gpus all flag is set in docker-compose
```

### API Connection Refused

```bash
# Check if service is running
docker ps | grep tgi

# Check if port is open
curl http://localhost:8081/health

# Check from Nextcloud container
docker exec nextcloud curl http://tgi-mistral:80/health
```

## Comparison with Ollama

| Feature | TGI/vLLM | Ollama |
|---------|----------|--------|
| **OpenAI API** | ✅ Yes | ❌ No (custom API) |
| **Setup** | Medium (Docker) | Easy (one command) |
| **Models** | Any Hugging Face | Curated list |
| **Performance** | ⚡⚡⚡ Optimized | ⚡⚡ Good |
| **LLPhant Support** | ✅ Yes (via OpenAI client) | ❌ Requires adapter |
| **Production** | ✅ Yes | ✅ Yes |

**Recommendation**:
- Use **TGI/vLLM** if you need OpenAI API compatibility
- Use **Ollama** if you want simpler setup and don't need OpenAI API

## Integration Examples

### Example 1: RAG with Local Mistral

```php
use LLPhant\Chat\OpenAIChat;
use LLPhant\Embeddings\VectorStores\Qdrant;

// Configure OpenAI client to use local TGI
$config = [
    'base_uri' => 'http://tgi-mistral:80',
    'timeout' => 30
];

$chat = new OpenAIChat($config);

// Use for RAG
$context = $vectorStore->search($query, 5);
$prompt = "Based on this context: {$context}\n\nQuestion: {$query}";
$answer = $chat->generateText($prompt);
```

### Example 2: Entity Extraction with Local Mistral

```php
function extractEntities(string $text): array
{
    $prompt = "Extract all person names, organizations, and locations from this text. Return as JSON.\n\nText: {$text}";
    
    $response = callLocalLLM($prompt, 'http://tgi-mistral:80');
    return json_decode($response, true);
}

$text = "Jan de Vries works at Gemeente Amsterdam in the Netherlands.";
$entities = extractEntities($text);

// Result:
// {
//   "persons": ["Jan de Vries"],
//   "organizations": ["Gemeente Amsterdam"],
//   "locations": ["Netherlands"]
// }
```

### Example 3: Document Summarization

```php
function summarizeDocument(string $content): string
{
    $prompt = "Summarize the following document in 3-5 sentences:\n\n{$content}";
    return callLocalLLM($prompt, 'http://tgi-mistral:80');
}
```

## Production Deployment

### Resource Allocation

```yaml
# Production TGI configuration
tgi-mistral:
  deploy:
    replicas: 2  # For high availability
    resources:
      limits:
        memory: 32G
        cpus: '8'
      reservations:
        memory: 16G
        devices:
          - driver: nvidia
            device_ids: ['0', '1']  # Use specific GPUs
            capabilities: [gpu]
```

### Monitoring

```bash
# Check TGI metrics
curl http://localhost:8081/metrics

# vLLM metrics (Prometheus format)
curl http://localhost:8082/metrics
```

### Load Balancing

For multiple instances, use nginx:

```nginx
upstream tgi_backend {
    server tgi-mistral-1:80;
    server tgi-mistral-2:80;
}

location /v1/ {
    proxy_pass http://tgi_backend;
}
```

## Related Documentation

- [Docker Setup](./docker-setup.md) - Complete Docker development setup guide
- [Presidio Setup](./presidio-setup.md) - Entity extraction
- [Ollama Configuration](../development/docker-setup.md) - Alternative local LLM
- [NER & NLP Concepts](../features/ner-nlp-concepts.md) - Entity recognition

## External Resources

- [Text Generation Inference](https://github.com/huggingface/text-generation-inference) - Official TGI repo
- [vLLM Documentation](https://docs.vllm.ai/) - vLLM docs
- [Hugging Face Models](https://huggingface.co/models) - Browse models
- [TGI Messages API Guide](https://huggingface.co/blog/tgi-messages-api) - OpenAI compatibility

---

**Summary**: TGI and vLLM provide OpenAI-compatible APIs for local Hugging Face models like Mistral. This allows LLPhant and other OpenAI clients to use local models instead of cloud APIs, ensuring privacy and cost savings.

