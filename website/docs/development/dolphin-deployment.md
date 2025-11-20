---
title: Dolphin Document Parser Deployment (Optional)
sidebar_position: 13
---

# Dolphin Document Parser - Optional Deployment Guide

## Overview

[Dolphin](https://github.com/bytedance/Dolphin) is an open-source document image parsing model (0.3B parameters) developed by ByteDance. It provides advanced document processing capabilities including OCR, layout analysis, and structured extraction.

**Status**: ⚠️ **Not included in OpenRegister's default docker-compose** - Requires separate deployment

## Why Use Dolphin?

Dolphin is recommended when you need:

- ✅ **Advanced OCR**: Better accuracy on scanned documents and images
- ✅ **Layout Analysis**: Understand document structure and reading order
- ✅ **Complex Documents**: Handle multi-column layouts, mixed content types
- ✅ **Table Extraction**: Parse complex tables with high accuracy
- ✅ **Formula Recognition**: Extract mathematical formulas from documents

## When NOT to Use Dolphin

For simpler use cases, use the built-in options:

- **LLPhant** (PHP library) - Simple documents, privacy-first, no external dependencies
- **Presidio** (included in docker-compose) - Entity extraction and PII detection
- **Ollama** (included in docker-compose) - AI-powered text understanding

## Deployment Options

### Option 1: Custom Docker Container (Recommended)

Build and deploy Dolphin using Hugging Face Inference Toolkit:

**Step 1: Clone Dolphin Repository**

```bash
git clone https://github.com/bytedance/Dolphin.git
cd Dolphin
```

**Step 2: Create Dockerfile**

Create `Dockerfile` in the Dolphin directory:

```dockerfile
FROM nvidia/cuda:12.1.0-runtime-ubuntu22.04

# Install Python and dependencies
RUN apt-get update && apt-get install -y \
    python3.10 \
    python3-pip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy Dolphin files
COPY . /app/

# Install Python dependencies
RUN pip3 install --no-cache-dir -r requirements.txt

# Download Dolphin model from Hugging Face
RUN pip3 install huggingface_hub && \
    python3 -c "from huggingface_hub import snapshot_download; \
    snapshot_download(repo_id='ByteDance/Dolphin-1.5', local_dir='/app/models')"

# Expose API port
EXPOSE 5000

# Run API server
CMD ["python3", "api_server.py", "--model_path", "/app/models", "--port", "5000"]
```

**Step 3: Create API Server**

Create `api_server.py`:

```python
from flask import Flask, request, jsonify
from PIL import Image
import io
import base64

app = Flask(__name__)

# Initialize Dolphin model
# (implementation depends on Dolphin's API)

@app.route('/parse', methods=['POST'])
def parse_document():
    """Parse document image or PDF"""
    try:
        # Get file from request
        if 'file' in request.files:
            file = request.files['file']
            image = Image.open(file)
        elif 'image_base64' in request.json:
            image_data = base64.b64decode(request.json['image_base64'])
            image = Image.open(io.BytesIO(image_data))
        else:
            return jsonify({'error': 'No image provided'}), 400
        
        # Run Dolphin parsing
        result = parse_with_dolphin(image)
        
        return jsonify(result)
    
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/health', methods=['GET'])
def health():
    return jsonify({'status': 'ok'})

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
```

**Step 4: Build and Run**

```bash
# Build Docker image
docker build -t dolphin-api:latest .

# Run container
docker run -d \
  --name dolphin-api \
  --gpus all \
  -p 5000:5000 \
  -v dolphin-models:/app/models \
  dolphin-api:latest

# Check health
curl http://localhost:5000/health
```

### Option 2: Add to OpenRegister docker-compose

If you build a Dolphin container, add it to `docker-compose.yml`:

```yaml
services:
  # ... existing services ...
  
  dolphin:
    image: dolphin-api:latest
    container_name: openregister-dolphin
    restart: always
    ports:
      - "5000:5000"
    volumes:
      - dolphin-models:/app/models
    environment:
      - LOG_LEVEL=INFO
    deploy:
      resources:
        limits:
          memory: 4G
        reservations:
          memory: 2G
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost:5000/health || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 3

volumes:
  # ... existing volumes ...
  dolphin-models:
```

Then link to Nextcloud:

```yaml
nextcloud:
  links:
    - db
    - solr
    - ollama
    - presidio-analyzer
    - dolphin  # Add this
```

### Option 3: Using Hugging Face Inference Toolkit

Follow the [community guide](https://huggingface.co/luquiT4/DolphinInference):

```bash
# Clone HF Inference Toolkit
git clone https://github.com/huggingface/huggingface-inference-toolkit.git
cd huggingface-inference-toolkit

# Build GPU-enabled image
make inference-pytorch-gpu

# Run with Dolphin model
docker run -ti -p 5000:5000 --gpus all \
  -e HF_MODEL_ID=ByteDance/Dolphin-1.5 \
  -e HF_TASK=image-to-text \
  integration-test-pytorch:gpu
```

### Option 4: Replicate Cloud (External API)

Use Replicate's hosted version:

```bash
# Install Replicate client
pip install replicate

# Use API
import replicate
output = replicate.run(
    "bytedance/dolphin",
    input={"image": open("document.pdf", "rb")}
)
```

**Note**: This sends data to Replicate's cloud. Not suitable for sensitive documents.

## Integration with OpenRegister

### Configure API Endpoint

Once Dolphin is deployed, configure in OpenRegister:

**PHP Configuration** (`config/text_extraction.php`):

```php
return [
    'extractors' => [
        'llphant' => [
            'enabled' => true,
            'priority' => 1  // Try local first
        ],
        'dolphin' => [
            'enabled' => true,
            'api_url' => 'http://dolphin:5000',  // or http://localhost:5000
            'timeout' => 30,
            'priority' => 2  // Fallback to Dolphin
        ]
    ]
];
```

### Using in PHP

```php
use OCA\OpenRegister\Service\TextExtractionService;

// Initialize service
$textService = $this->container->get(TextExtractionService::class);

// Extract with Dolphin
$result = $textService->extractFile($fileId, [
    'method' => 'dolphin',
    'parse_layout' => true,
    'extract_tables' => true
]);

// Result includes:
// - text: Extracted text
// - layout: Document structure
// - tables: Parsed table data
// - reading_order: Element sequence
```

### API Endpoints

**Parse Document**:
```bash
curl -X POST http://localhost:5000/parse \
  -F "file=@document.pdf"
```

**Response**:
```json
{
  "text": "Extracted text content...",
  "layout": {
    "elements": [
      {"type": "title", "text": "Document Title", "bbox": [...]},
      {"type": "paragraph", "text": "Content...", "bbox": [...]},
      {"type": "table", "data": [[...]], "bbox": [...]}
    ],
    "reading_order": [0, 1, 2]
  },
  "metadata": {
    "pages": 1,
    "language": "en"
  }
}
```

## Performance Considerations

### Resource Requirements

| Component | Requirement |
|-----------|-------------|
| **CPU** | 4+ cores |
| **RAM** | 2-4GB |
| **GPU** | Optional but recommended (NVIDIA with CUDA) |
| **Storage** | 5GB for model + cache |

### Processing Speed

- **With GPU**: 0.5-2 seconds per page
- **CPU only**: 3-10 seconds per page
- **Batch processing**: More efficient for multiple documents

### Optimization

```yaml
# Increase workers for parallel processing
environment:
  - NUM_WORKERS=4
  - BATCH_SIZE=8
  
# Use GPU acceleration
deploy:
  resources:
    reservations:
      devices:
        - driver: nvidia
          count: all
          capabilities: [gpu]
```

## Comparison: LLPhant vs Dolphin

| Feature | LLPhant (Local) | Dolphin (API) |
|---------|----------------|---------------|
| **Setup** | ✅ Built-in PHP | ⚠️ Requires deployment |
| **Speed** | ⚡ Fast | ⚡⚡ Very fast (with GPU) |
| **Accuracy** | Good (80-85%) | Excellent (90-95%) |
| **OCR** | ❌ No | ✅ Yes |
| **Layout Analysis** | ❌ No | ✅ Yes |
| **Table Extraction** | Basic | Advanced |
| **Privacy** | ✅ 100% local | ✅ Self-hosted |
| **Cost** | Free | Free (self-hosted) |

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker logs dolphin-api

# Common issues:
# 1. GPU not available
docker run --gpus all nvidia/cuda:12.1.0-base-ubuntu22.04 nvidia-smi

# 2. Model not downloaded
docker exec dolphin-api ls -la /app/models
```

### API Timeouts

Increase timeout in OpenRegister configuration:

```php
'dolphin' => [
    'api_url' => 'http://dolphin:5000',
    'timeout' => 60,  // Increase from 30 to 60 seconds
]
```

### Low Accuracy

- Ensure GPU is being used (`--gpus all`)
- Check model version (use Dolphin-1.5)
- Verify image quality (min 300 DPI for scanned docs)

### Memory Issues

```bash
# Reduce batch size
docker run -e BATCH_SIZE=1 dolphin-api:latest

# Increase container memory
docker run --memory=8g dolphin-api:latest
```

## Alternative: Skip Dolphin

For most use cases, the built-in extractors are sufficient:

```php
// Use LLPhant for simple documents
$result = $textService->extractFile($fileId, ['method' => 'llphant']);

// Use Presidio for entity extraction (included in docker-compose)
$entities = $nerService->extractEntities($text, 'presidio');

// Use Ollama for AI understanding (included in docker-compose)
$response = $llmService->chat($text);
```

## Production Checklist

Before deploying Dolphin in production:

- [ ] Docker container built and tested
- [ ] Health endpoint responding
- [ ] GPU acceleration configured (if available)
- [ ] Memory limits set appropriately
- [ ] API timeout configured in OpenRegister
- [ ] Test documents parsed successfully
- [ ] Monitoring/logging configured
- [ ] Backup strategy for models volume
- [ ] Consider privacy implications for document data

## Related Documentation

- [Text Extraction Enhanced](../features/text-extraction-enhanced.md) - Complete extraction pipeline
- [Docker Services Overview](./docker-services.md) - All included services
- [Presidio Setup](./presidio-setup.md) - Entity extraction (included)
- [Dolphin GitHub](https://github.com/bytedance/Dolphin) - Official repository

---

**Summary**: Dolphin is a powerful but optional document parser. For most use cases, the built-in LLPhant extractor is sufficient. Deploy Dolphin only if you need advanced OCR and layout analysis capabilities.

