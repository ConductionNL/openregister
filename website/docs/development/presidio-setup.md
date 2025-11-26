---
title: Presidio Setup for Dutch Language
sidebar_position: 12
---

# Presidio Analyzer Setup for Dutch Language Support

## Overview

Presidio Analyzer is Microsoft's open-source PII detection and Named Entity Recognition (NER) service. This guide covers setting up Presidio with Dutch language support for OpenRegister.

## Why Presidio?

**Presidio Analyzer** is recommended for production GDPR compliance because:

- ✅ **High Accuracy**: 90-98% precision for entity detection
- ✅ **Multi-language Support**: 50+ languages including Dutch
- ✅ **GDPR-Focused**: Built specifically for PII detection
- ✅ **Self-Hosted**: Run on your own infrastructure (privacy-first)
- ✅ **Extensible**: Add custom recognizers for domain-specific entities
- ✅ **Active Development**: Maintained by Microsoft with regular updates

## Dutch Language Support

### Default Language Model

The Presidio Analyzer Docker image includes **English (en)** language models by default. For Dutch language support, the container automatically downloads the required **spaCy Dutch model** on first startup.

### Required Models

For Dutch NER, Presidio uses:

- **nl_core_news_sm** - Small Dutch spaCy model (43MB, fast, good accuracy)
- **nl_core_news_md** - Medium Dutch model (optional, 90MB, better accuracy)
- **nl_core_news_lg** - Large Dutch model (optional, 545MB, best accuracy)

The small model is sufficient for most use cases and is automatically downloaded.

## Docker Compose Configuration

The docker-compose.yml already includes Presidio Analyzer with multi-language support:

```yaml
presidio-analyzer:
  image: mcr.microsoft.com/presidio-analyzer:latest
  container_name: openregister-presidio-analyzer
  restart: always
  ports:
    - "5001:5001"
  environment:
    - GRPC_PORT=5001
    - LOG_LEVEL=INFO
    # Multi-language support (Dutch included)
    - PRESIDIO_ANALYZER_LANGUAGES=en,nl,de,fr,es
  deploy:
    resources:
      limits:
        memory: 2G
      reservations:
        memory: 512M
  healthcheck:
    test: ["CMD-SHELL", "curl -f http://localhost:5001/health || exit 1"]
    interval: 30s
    timeout: 10s
    retries: 3
    start_period: 30s
```

## Starting Presidio

### 1. Start the Service

```bash
# Navigate to OpenRegister directory
cd /path/to/apps-extra/openregister

# Start all services (including Presidio)
docker-compose up -d presidio-analyzer

# Or start everything
docker-compose up -d
```

### 2. First Startup (Dutch Model Download)

On first startup, Presidio will automatically download the Dutch language model:

```bash
# Watch the logs to see model download
docker-compose logs -f presidio-analyzer
```

You should see output like:

```
Downloading Dutch model...
Collecting nl-core-news-sm
  Downloading nl_core_news_sm-3.6.0.tar.gz (43 MB)
Successfully installed nl-core-news-sm-3.6.0
✔ Download and installation successful
```

**Note**: This happens automatically. The download takes 1-3 minutes depending on your internet connection.

### 3. Verify Installation

```bash
# Check if Presidio is running
curl http://localhost:5001/health

# Response should be:
# {"status": "ok"}

# Test Dutch entity detection
curl -X POST http://localhost:5001/analyze \
  -H "Content-Type: application/json" \
  -d '{
    "text": "Jan de Vries woont in Amsterdam en zijn email is jan.devries@example.nl",
    "language": "nl"
  }'
```

Expected response:

```json
[
  {
    "entity_type": "PERSON",
    "start": 0,
    "end": 12,
    "score": 0.85,
    "analysis_explanation": {
      "recognizer": "SpacyRecognizer",
      "pattern_name": null,
      "pattern": null,
      "original_score": 0.85,
      "score": 0.85,
      "textual_explanation": null,
      "score_context_improvement": 0,
      "supportive_context_word": "",
      "validation_result": null
    }
  },
  {
    "entity_type": "LOCATION",
    "start": 22,
    "end": 31,
    "score": 0.85
  },
  {
    "entity_type": "EMAIL_ADDRESS",
    "start": 48,
    "end": 73,
    "score": 0.95
  }
]
```

## Advanced Configuration

### Using a Larger Dutch Model (Better Accuracy)

If you need higher accuracy for Dutch text, use the medium or large model:

**Option 1: Custom Dockerfile**

Create `docker/Dockerfile.presidio-nl`:

```dockerfile
FROM mcr.microsoft.com/presidio-analyzer:latest

# Install larger Dutch model
RUN python -m spacy download nl_core_news_md

# Or for best accuracy (large model)
# RUN python -m spacy download nl_core_news_lg
```

Update docker-compose.yml:

```yaml
presidio-analyzer:
  build:
    context: .
    dockerfile: docker/Dockerfile.presidio-nl
  container_name: openregister-presidio-analyzer
  # ... rest of configuration
```

**Option 2: Volume with Pre-downloaded Models**

```bash
# Download model locally
docker run --rm -v $(pwd)/presidio-models:/models \
  mcr.microsoft.com/presidio-analyzer:latest \
  python -m spacy download nl_core_news_md

# Mount in docker-compose.yml
presidio-analyzer:
  volumes:
    - ./presidio-models:/usr/local/lib/python3.9/site-packages/nl_core_news_md
```

### Configuring Recognized Entity Types

By default, Presidio detects these entity types in Dutch:

| Entity Type | Description | Example |
|-------------|-------------|---------|
| **PERSON** | Person names | Jan de Vries, Maria van der Berg |
| **LOCATION** | Places/cities | Amsterdam, Rotterdam, Nederland |
| **ORGANIZATION** | Companies/orgs | KPN, Gemeente Amsterdam |
| **EMAIL_ADDRESS** | Email addresses | jan@example.nl |
| **PHONE_NUMBER** | Phone numbers | +31 6 12345678, 06-12345678 |
| **IBAN_CODE** | Bank accounts | NL91 ABNA 0417 1643 00 |
| **NRP** (Dutch specific) | BSN numbers | 123456782 (9-digit) |
| **DATE_TIME** | Dates/times | 15 januari 2025 |
| **URL** | Web addresses | https://example.nl |
| **IP_ADDRESS** | IP addresses | 192.168.1.1 |

### Dutch-Specific Patterns

Presidio includes Dutch-specific recognizers:

1. **BSN (Burgerservicenummer)**: Dutch citizen service number
2. **Dutch phone numbers**: Multiple formats (+31 6, 06-, etc.)
3. **Dutch IBANs**: NL-prefixed bank accounts
4. **Dutch addresses**: Street patterns common in Netherlands

## Performance Considerations

### Memory Requirements

| Model Size | Memory Usage | Performance | Accuracy |
|------------|--------------|-------------|----------|
| **sm** (small) | 100-200MB | Fast (50-100ms/doc) | Good (85-90%) |
| **md** (medium) | 200-400MB | Medium (100-200ms/doc) | Better (88-93%) |
| **lg** (large) | 500MB-1GB | Slow (200-400ms/doc) | Best (90-95%) |

**Recommendation**: Use **small model** for development and most production use cases. Only upgrade to medium/large if accuracy is insufficient.

### Processing Speed

Average processing time for Dutch text:

- **Short text** (100 chars): 20-50ms
- **Medium text** (1000 chars): 50-150ms
- **Long text** (10000 chars): 200-500ms

**Tip**: Process text in chunks of ~1000 characters for optimal performance.

## Integration with OpenRegister

### Configuration in OpenRegister

Configure Presidio in your OpenRegister settings:

```php
// config/ner_config.php
return [
    'ner_enabled' => true,
    'ner_method' => 'presidio',  // Use Presidio for production
    
    'presidio' => [
        'analyzer_url' => 'http://presidio-analyzer:5001',
        'default_language' => 'nl',  // Default to Dutch
        'languages' => ['nl', 'en'],  // Support Dutch and English
        'score_threshold' => 0.6,     // Minimum confidence score
        'entities' => [
            'PERSON',
            'EMAIL_ADDRESS',
            'PHONE_NUMBER',
            'IBAN_CODE',
            'LOCATION',
            'ORGANIZATION',
            'NRP',  // Dutch BSN numbers
        ]
    ]
];
```

### Using Presidio in PHP

```php
use OCA\OpenRegister\Service\NerService;

// Initialize NER service
$nerService = $this->container->get(NerService::class);

// Extract entities from Dutch text
$dutchText = "Jan de Vries woont in Amsterdam en zijn telefoonnummer is 06-12345678.";

$entities = $nerService->extractEntities($dutchText, 'presidio', [
    'language' => 'nl'
]);

foreach ($entities as $entity) {
    echo "Type: {$entity['type']}\n";
    echo "Value: {$entity['value']}\n";
    echo "Confidence: {$entity['confidence']}\n";
    echo "Position: {$entity['start']}-{$entity['end']}\n\n";
}
```

**Output**:
```
Type: PERSON
Value: Jan de Vries
Confidence: 0.85
Position: 0-12

Type: LOCATION
Value: Amsterdam
Confidence: 0.85
Position: 22-31

Type: PHONE_NUMBER
Value: 06-12345678
Confidence: 0.95
Position: 58-69
```

### Automatic Language Detection

If your documents are mixed language, detect language first:

```php
// Detect language
$language = $nerService->detectLanguage($text);

// Use detected language for entity extraction
$entities = $nerService->extractEntities($text, 'presidio', [
    'language' => $language
]);
```

## Troubleshooting

### Presidio Container Won't Start

```bash
# Check logs
docker logs openregister-presidio-analyzer

# Common issues:
# 1. Port 5001 already in use
sudo lsof -i :5001

# 2. Insufficient memory
# Increase memory limit in docker-compose.yml
```

### Dutch Model Download Fails

```bash
# Manually download Dutch model
docker exec -it openregister-presidio-analyzer \
  python -m spacy download nl_core_news_sm

# Verify model is installed
docker exec -it openregister-presidio-analyzer \
  python -m spacy info nl_core_news_sm
```

### Low Accuracy for Dutch Text

**Solutions**:

1. **Upgrade to larger model** (see Advanced Configuration)
2. **Lower confidence threshold** in configuration
3. **Add custom recognizers** for domain-specific terms
4. **Use hybrid approach** with multiple NER methods

### Connection Errors from Nextcloud

```bash
# Test connectivity from Nextcloud container
docker exec nextcloud curl http://presidio-analyzer:5001/health

# If fails, check if services are on same network
docker network ls
docker network inspect openregister_default
```

## Custom Dutch Recognizers

For domain-specific Dutch entities (e.g., Dutch postcode patterns):

**Create custom recognizer**: `custom_recognizers/nl_postcode_recognizer.yaml`

```yaml
- name: nl_postcode
  supported_language: nl
  patterns:
    - name: dutch_postcode
      regex: '\b[1-9][0-9]{3}\s?[A-Z]{2}\b'
      score: 0.85
  context:
    - postcode
    - adres
    - woonplaats
```

**Load custom recognizers**:

```bash
docker exec openregister-presidio-analyzer \
  curl -X POST http://localhost:5001/recognizers \
  -H "Content-Type: application/yaml" \
  --data-binary @custom_recognizers/nl_postcode_recognizer.yaml
```

## Testing Dutch Entity Detection

### Test Script

```bash
# test-dutch-ner.sh
#!/bin/bash

echo "Testing Dutch entity detection..."

# Test 1: Person and location
curl -X POST http://localhost:5001/analyze \
  -H "Content-Type: application/json" \
  -d '{
    "text": "Jan de Vries woont in Amsterdam.",
    "language": "nl"
  }' | jq

# Test 2: Email and phone
curl -X POST http://localhost:5001/analyze \
  -H "Content-Type: application/json" \
  -d '{
    "text": "Neem contact op via jan@example.nl of bel 06-12345678.",
    "language": "nl"
  }' | jq

# Test 3: IBAN
curl -X POST http://localhost:5001/analyze \
  -H "Content-Type: application/json" \
  -d '{
    "text": "Maak het bedrag over naar NL91 ABNA 0417 1643 00.",
    "language": "nl"
  }' | jq

# Test 4: Organization
curl -X POST http://localhost:5001/analyze \
  -H "Content-Type: application/json" \
  -d '{
    "text": "De Gemeente Amsterdam heeft een nieuwe website gelanceerd.",
    "language": "nl"
  }' | jq

echo "Tests completed!"
```

Run tests:

```bash
chmod +x test-dutch-ner.sh
./test-dutch-ner.sh
```

## Resource Management

### Monitor Presidio

```bash
# Check resource usage
docker stats openregister-presidio-analyzer

# Check health
curl http://localhost:5001/health

# View logs
docker logs -f openregister-presidio-analyzer --tail 100
```

### Restart Presidio

```bash
# Restart service
docker-compose restart presidio-analyzer

# Or rebuild (if Dockerfile changed)
docker-compose up -d --build presidio-analyzer
```

## Production Checklist

Before deploying to production:

- [ ] Presidio Analyzer is running and healthy
- [ ] Dutch model is installed (verify with test query)
- [ ] Memory limits are appropriate (2GB recommended)
- [ ] Health checks are working
- [ ] Confidence threshold is configured (0.6-0.8 recommended)
- [ ] Logging level is set to INFO (not DEBUG)
- [ ] Custom recognizers are loaded (if needed)
- [ ] Integration tests pass with Dutch text samples
- [ ] Performance is acceptable (&lt;200ms per 1000 chars)

## Alternative: MITIE for Development

For development without Docker dependencies, use **MITIE** (local PHP library):

```php
// Development/testing with MITIE
$entities = $nerService->extractEntities($text, 'mitie');

// Production with Presidio
$entities = $nerService->extractEntities($text, 'presidio', [
    'language' => 'nl'
]);
```

See [NER & NLP Concepts](../features/ner-nlp-concepts.md) for MITIE setup.

## Related Documentation

- [NER & NLP Concepts](../features/ner-nlp-concepts.md) - Understanding entity recognition
- [Docker Services Overview](./docker-services.md) - All services in the stack
- [Text Extraction Enhanced](../features/text-extraction-enhanced.md) - Complete extraction pipeline
- [Entity Relationships](../technical/entity-relationships-addition.md) - GDPR entity data model

## External Resources

- [Presidio Documentation](https://microsoft.github.io/presidio/)
- [Presidio GitHub](https://github.com/microsoft/presidio)
- [spaCy Dutch Models](https://spacy.io/models/nl)
- [Dutch PII Detection Best Practices](https://www.autoriteitpersoonsgegevens.nl/)

---

**Summary**: The default Presidio Analyzer setup automatically supports Dutch language. No additional configuration is required beyond the docker-compose setup. The Dutch spaCy model downloads automatically on first startup.

