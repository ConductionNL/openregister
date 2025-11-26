# Mistral LLM Model Integration

Mistral is a high-performance open-source language model that can be used with OpenRegister through Ollama or Hugging Face integrations.

## Overview

Mistral models are available in multiple sizes and can be run locally using:
- **Ollama**: Simple setup, native API
- **Hugging Face TGI/vLLM**: OpenAI-compatible API, optimized for production

## Model Variants

| Model | Size | Parameters | Use Case | Memory Required |
|-------|------|------------|----------|-----------------|
| **Mistral 7B** | 7B | 7 billion | General purpose, RAG | 16GB |
| **Mistral 7B Instruct** | 7B | 7 billion | Chat, instructions | 16GB |
| **Mixtral 8x7B** | 47B | 47 billion | High quality, complex tasks | 48GB+ |

## Using Mistral with Ollama

### Quick Start

```bash
# Pull Mistral model
docker exec openregister-ollama ollama pull mistral:7b

# Or Mistral Instruct (recommended for chat)
docker exec openregister-ollama ollama pull mistral:latest
```

### Configuration

1. Navigate to **Settings** → **OpenRegister** → **LLM Configuration**
2. Select **Ollama** as provider
3. Configure:
   - **Ollama URL**: `http://openregister-ollama:11434`
   - **Chat Model**: `mistral:latest` or `mistral:7b`

See [Ollama Integration](./ollama.md) for detailed setup instructions.

## Using Mistral with Hugging Face

### Quick Start

```bash
# Start TGI with Mistral
docker-compose -f docker-compose.huggingface.yml up -d tgi-mistral

# Or start vLLM with Mistral
docker-compose -f docker-compose.huggingface.yml up -d vllm-mistral
```

### Configuration

1. Navigate to **Settings** → **OpenRegister** → **LLM Configuration**
2. Select **OpenAI** as provider (TGI/vLLM are OpenAI-compatible)
3. Configure:
   - **Base URL**: `http://tgi-mistral:80` (TGI) or `http://vllm-mistral:8000` (vLLM)
   - **Model**: `mistral-7b-instruct`
   - **API Key**: `dummy` (not used for local)

See [Hugging Face Integration](./huggingface.md) for detailed setup instructions.

## Use Cases

### 1. General Purpose Chat

Mistral excels at:
- Conversational AI
- Question answering
- Text generation
- Code generation

### 2. RAG (Retrieval Augmented Generation)

Use Mistral with OpenRegister's RAG features:
- Answer questions using your data
- Context-aware responses
- Citation support

### 3. Function Calling

Mistral supports function calling for:
- Object search
- Object creation
- Object updates
- Register queries

## Performance Comparison

| Setup | Speed | Quality | Ease of Use |
|-------|-------|---------|-------------|
| **Ollama** | ⚡⚡⚡ Fast | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ Easy |
| **TGI** | ⚡⚡ Fast | ⭐⭐⭐⭐ | ⭐⭐⭐ Medium |
| **vLLM** | ⚡⚡⚡ Very Fast | ⭐⭐⭐⭐ | ⭐⭐⭐ Medium |

## Recommended Configuration

### For Development

Use **Ollama** with Mistral:
- Easiest setup
- Good performance
- Native API

### For Production

Use **TGI** or **vLLM** with Mistral:
- Better throughput
- OpenAI-compatible API
- Optimized inference

## Troubleshooting

### Model Not Found (Ollama)

```bash
# List available models
docker exec openregister-ollama ollama list

# Pull Mistral if missing
docker exec openregister-ollama ollama pull mistral:latest

# Verify model name includes tag
docker exec openregister-ollama ollama show mistral:latest
```

### Slow Performance

**Solutions**:
1. Use GPU acceleration (10-100x faster)
2. Use Mistral 7B instead of Mixtral 8x7B
3. Ensure models are loaded in memory

## Further Reading

- [Ollama Integration](./ollama.md) - Run Mistral via Ollama
- [Hugging Face Integration](./huggingface.md) - Run Mistral via TGI/vLLM
- [Mistral AI Documentation](https://mistral.ai)
- [RAG Implementation](../features/rag-implementation.md)

## Support

For issues specific to:
- **Mistral models**: Check [Mistral AI Documentation](https://mistral.ai)
- **Ollama setup**: See [Ollama Integration](./ollama.md)
- **Hugging Face setup**: See [Hugging Face Integration](./huggingface.md)
- **OpenRegister integration**: OpenRegister GitHub issues



