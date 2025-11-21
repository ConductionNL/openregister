---
title: Integrations Overview
sidebar_position: 1
description: Overview of all OpenRegister integrations including LLM hosting, models, and entity extraction
keywords:
  - Open Register
  - Integrations
  - LLM
  - AI
  - Entity Extraction
---

# Integrations Overview

OpenRegister integrates with various external services and models to provide powerful AI capabilities, automation workflows, and advanced text processing. This page provides an overview of all available integrations and how they work together.

## Integration Categories

OpenRegister integrations fall into three main categories:

### 1. LLM Hosting Platforms
Services that host and run Large Language Models locally:
- **[Ollama](./ollama.md)** - Simple, native API for running LLMs
- **[Hugging Face](./huggingface.md)** - TGI/vLLM with OpenAI-compatible API

### 2. LLM Models
Specific language models that can be used:
- **[Mistral](./mistral.md)** - High-performance 7B model
- **[Dolphin](./dolphin.md)** - Document parsing and OCR model

### 3. Entity Extraction Services
Services for detecting and extracting entities from text:
- **[Presidio](./presidio.md)** - Microsoft's PII detection service

### 4. Automation Platforms
Workflow automation and integration platforms:
- **[n8n](./n8n.md)** - Workflow automation platform
- **[Windmill](./windmill.md)** - Developer-focused workflow engine
- **[Custom Webhooks](./custom-webhooks.md)** - Build your own integrations

## Integration Architecture

The following diagram shows how all integrations work together in OpenRegister:

```mermaid
graph TB
    subgraph OpenRegister[OpenRegister Core]
        OR[OpenRegister Application]
        TS[Text Extraction Service]
        VS[Vector Embedding Service]
        CS[Chat Service]
        NS[NER Service]
        ES[Event System]
    end
    
    subgraph LLM_Hosting[LLM Hosting Platforms]
        OLLAMA[Ollama<br/>Native API]
        TGI[TGI/vLLM<br/>OpenAI-Compatible]
    end
    
    subgraph LLM_Models[LLM Models]
        MISTRAL[Mistral 7B<br/>Chat & RAG]
        DOLPHIN[Dolphin VLM<br/>Document Parsing]
    end
    
    subgraph Entity_Services[Entity Extraction]
        PRESIDIO[Presidio Analyzer<br/>PII Detection]
    end
    
    subgraph Automation[Automation Platforms]
        N8N[n8n<br/>Workflows]
        WINDMILL[Windmill<br/>Scripts]
        WEBHOOKS[Custom Webhooks<br/>Your Services]
    end
    
    subgraph Data_Flow[Data Flow]
        FILES[Files & Documents]
        OBJECTS[OpenRegister Objects]
        CHUNKS[Text Chunks]
        VECTORS[Vector Embeddings]
        ENTITIES[GDPR Entities]
    end
    
    %% Text Extraction Flow
    FILES --> TS
    OBJECTS --> TS
    TS -->|OCR & Parsing| DOLPHIN
    DOLPHIN --> TS
    TS --> CHUNKS
    
    %% Entity Extraction Flow
    CHUNKS --> NS
    NS --> PRESIDIO
    PRESIDIO --> NS
    NS --> ENTITIES
    
    %% Vector Embedding Flow
    CHUNKS --> VS
    VS -->|Embeddings| OLLAMA
    VS -->|Embeddings| TGI
    OLLAMA --> MISTRAL
    TGI --> MISTRAL
    VS --> VECTORS
    
    %% Chat & RAG Flow
    CS -->|Chat Requests| OLLAMA
    CS -->|Chat Requests| TGI
    OLLAMA --> MISTRAL
    TGI --> MISTRAL
    CS -->|RAG Context| VECTORS
    
    %% Event System Flow
    OR --> ES
    ES -->|Events| N8N
    ES -->|Events| WINDMILL
    ES -->|Events| WEBHOOKS
    
    %% Styling
    style OpenRegister fill:#e3f2fd,stroke:#1976d2,stroke-width:3px
    style LLM_Hosting fill:#f3e5f5,stroke:#7b1fa2,stroke-width:2px
    style LLM_Models fill:#fff9c4,stroke:#f57f17,stroke-width:2px
    style Entity_Services fill:#ffccbc,stroke:#d84315,stroke-width:2px
    style Automation fill:#c8e6c9,stroke:#388e3c,stroke-width:2px
    style Data_Flow fill:#e1f5ff,stroke:#0277bd,stroke-width:2px
```

## Integration Flow

### 1. Text Extraction Pipeline

```mermaid
sequenceDiagram
    participant User
    participant OpenRegister
    participant Dolphin
    participant Presidio
    participant Ollama
    participant Solr
    
    User->>OpenRegister: Upload Document
    OpenRegister->>Dolphin: Extract Text (OCR)
    Dolphin-->>OpenRegister: Extracted Text
    
    OpenRegister->>OpenRegister: Create Chunks
    
    OpenRegister->>Presidio: Extract Entities
    Presidio-->>OpenRegister: PII Entities
    
    OpenRegister->>Ollama: Generate Embeddings
    Ollama-->>OpenRegister: Vector Embeddings
    
    OpenRegister->>Solr: Index Text
    OpenRegister->>OpenRegister: Store Vectors
    
    OpenRegister-->>User: Processing Complete
```

### 2. Chat & RAG Pipeline

```mermaid
sequenceDiagram
    participant User
    participant ChatService
    participant VectorDB
    participant Ollama
    participant Mistral
    
    User->>ChatService: Ask Question
    ChatService->>ChatService: Generate Query Embedding
    ChatService->>VectorDB: Search Similar Content
    VectorDB-->>ChatService: Relevant Chunks
    
    ChatService->>Ollama: Chat Request + Context
    Ollama->>Mistral: Process Request
    Mistral-->>Ollama: Response
    Ollama-->>ChatService: Answer
    
    ChatService-->>User: Answer with Citations
```

### 3. Automation Pipeline

```mermaid
sequenceDiagram
    participant OpenRegister
    participant EventSystem
    participant n8n
    participant Windmill
    participant CustomWebhook
    
    OpenRegister->>EventSystem: Object Created
    EventSystem->>n8n: Webhook Event
    EventSystem->>Windmill: Webhook Event
    EventSystem->>CustomWebhook: Webhook Event
    
    n8n->>n8n: Process Workflow
    Windmill->>Windmill: Execute Script
    CustomWebhook->>CustomWebhook: Handle Event
    
    n8n-->>OpenRegister: Update External System
    Windmill-->>OpenRegister: Sync Data
    CustomWebhook-->>OpenRegister: Custom Action
```

## Integration Comparison

### LLM Hosting Platforms

| Platform | API Type | Setup Difficulty | Performance | Best For |
|----------|----------|------------------|-------------|----------|
| **Ollama** | Native | ⭐⭐⭐⭐⭐ Easy | ⚡⚡⚡ Good | Development, simple setup |
| **TGI** | OpenAI-Compatible | ⭐⭐⭐ Medium | ⚡⚡ Fast | Production, optimized |
| **vLLM** | OpenAI-Compatible | ⭐⭐⭐ Medium | ⚡⚡⚡ Very Fast | High throughput |

### LLM Models

| Model | Size | Use Case | Hosting |
|-------|------|----------|---------|
| **Mistral 7B** | 7B | Chat, RAG, general purpose | Ollama, TGI, vLLM |
| **Dolphin** | 0.3B | Document parsing, OCR | Custom container |

### Entity Extraction

| Service | Accuracy | Languages | Best For |
|---------|----------|-----------|----------|
| **Presidio** | 90-95% | 50+ | GDPR compliance, production |
| **MITIE** | 75-85% | Limited | Fast local processing |
| **LLM-based** | 92-98% | All | Highest accuracy |

### Automation Platforms

| Platform | Language | Use Case | Best For |
|----------|----------|----------|----------|
| **n8n** | Visual/JS | Workflow automation | Non-developers |
| **Windmill** | Python/TS/Go/Bash | Script execution | Developers |
| **Custom Webhooks** | Any | Custom integrations | Full control |

## Quick Start Guide

### For AI Chat & RAG

1. **Choose LLM Hosting**: Start with [Ollama](./ollama.md) for easiest setup
2. **Pull Model**: Download [Mistral](./mistral.md) or Llama 3.2
3. **Configure**: Set up in OpenRegister Settings → LLM Configuration
4. **Enable RAG**: Vectorize your objects and files

### For Document Processing

1. **Deploy Dolphin**: Start [Dolphin](./dolphin.md) container for OCR
2. **Configure**: Set Dolphin as extraction method
3. **Process Files**: Upload documents for automatic processing

### For GDPR Compliance

1. **Start Presidio**: Presidio is included in docker-compose
2. **Configure**: Enable entity extraction in settings
3. **Monitor**: Track PII in GDPR register

### For Automation

1. **Choose Platform**: [n8n](./n8n.md) for workflows or [Windmill](./windmill.md) for scripts
2. **Set Up Webhooks**: Register webhook endpoints
3. **Create Workflows**: Build automation for your use cases

## Integration Requirements

### Minimum Requirements

- **CPU**: 4+ cores recommended
- **RAM**: 16GB minimum (32GB recommended for larger models)
- **Storage**: 50GB+ for models and data
- **GPU**: Optional but recommended (8GB+ VRAM for LLMs)

### Docker Requirements

- Docker 20.10+
- Docker Compose 2.0+
- NVIDIA Docker runtime (for GPU support)

## Configuration Overview

### LLM Configuration

```yaml
# docker-compose.yml
services:
  ollama:
    image: ollama/ollama:latest
    # ... configuration
  
  tgi-mistral:
    image: ghcr.io/huggingface/text-generation-inference:latest
    # ... configuration
```

### Entity Extraction Configuration

```yaml
services:
  presidio-analyzer:
    image: mcr.microsoft.com/presidio-analyzer:latest
    # ... configuration
```

### Document Processing Configuration

```yaml
services:
  dolphin-vlm:
    build: ./docker/dolphin
    # ... configuration
```

## Best Practices

### 1. Start Simple

Begin with Ollama for LLM hosting - it's the easiest to set up and configure.

### 2. Use GPU When Available

GPU acceleration provides 10-100x performance improvement for LLMs and document processing.

### 3. Choose Right Model Size

- **Development**: Use smaller models (3B-7B) for faster iteration
- **Production**: Use larger models (7B-13B) for better quality

### 4. Monitor Resource Usage

Keep an eye on:
- Memory usage (models can be memory-intensive)
- GPU utilization
- API response times

### 5. Implement Fallbacks

Always have fallback options:
- LLPhant for text extraction if Dolphin unavailable
- MITIE for entity extraction if Presidio unavailable
- Database search if vector search unavailable

## Troubleshooting

### Common Issues

1. **Container Communication**: Always use container names, not localhost
2. **Model Not Found**: Ensure model names include version tags
3. **Out of Memory**: Reduce model size or increase available RAM
4. **Slow Performance**: Enable GPU acceleration

### Getting Help

- Check individual integration documentation
- Review [Development Guides](../development/)
- Open GitHub issues for bugs
- Check Docker logs for errors

## Next Steps

- **[Ollama Integration](./ollama.md)** - Get started with local LLMs
- **[Hugging Face Integration](./huggingface.md)** - Production-ready LLM hosting
- **[Presidio Integration](./presidio.md)** - GDPR-compliant entity extraction
- **[n8n Integration](./n8n.md)** - Workflow automation
- **[Custom Webhooks](./custom-webhooks.md)** - Build your own integrations

## Related Documentation

- [Text Extraction](../features/text-extraction-sources.md)
- [RAG Implementation](../features/rag-implementation.md)
- [Entity Extraction](../features/ner-nlp-concepts.md)
- [AI Features](../features/ai.md)



