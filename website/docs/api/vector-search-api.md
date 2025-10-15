# Vector & Hybrid Search API Documentation

## Overview

OpenRegister provides powerful semantic and hybrid search capabilities through REST API endpoints. This document covers all vector-related endpoints for search, vectorization, and statistics.

**Base URL**: `/apps/openregister/api`

**Authentication**: All endpoints require Nextcloud authentication (Basic Auth or session)

---

## Search Endpoints

### Semantic Search

Find documents by meaning using vector similarity.

**Endpoint**: `GET /solr/search/semantic`

**Description**: Performs pure semantic search using vector embeddings. Finds conceptually similar content even if keywords don't match.

**Parameters**:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | Yes | - | Search query (natural language) |
| `limit` | integer | No | 10 | Maximum results to return (1-100) |
| `threshold` | float | No | 0.7 | Minimum similarity score (0.0-1.0) |
| `entityType` | string | No | both | Filter by type: 'file', 'object', or 'both' |

**Request Example**:
```bash
curl -X GET 'http://nextcloud.local/apps/openregister/api/solr/search/semantic?query=budget+planning&limit=5&threshold=0.75' \
  -u admin:admin \
  -H "Content-Type: application/json"
```

**Response** (200 OK):
```json
{
  "results": [
    {
      "entity_id": "file_123",
      "entity_type": "file",
      "chunk_index": 2,
      "total_chunks": 5,
      "chunk_text": "The budget planning process for 2025 includes...",
      "similarity": 0.87,
      "metadata": {
        "filename": "financial-plan-2025.pdf",
        "file_path": "/documents/finance/",
        "chunk_size": 1000
      }
    },
    {
      "entity_id": "obj_456",
      "entity_type": "object",
      "chunk_index": 0,
      "chunk_text": "Project: Budget Optimization Initiative...",
      "similarity": 0.82,
      "metadata": {
        "schema": "Project",
        "title": "Budget Optimization Initiative"
      }
    }
  ],
  "total": 2,
  "query_time_ms": 145,
  "search_mode": "semantic"
}
```

**Response Fields**:
- `results`: Array of matching documents
  - `entity_id`: Unique identifier (file ID or object ID)
  - `entity_type`: 'file' or 'object'
  - `chunk_index`: Chunk number within document
  - `total_chunks`: Total chunks in document
  - `chunk_text`: Relevant text excerpt
  - `similarity`: Cosine similarity score (0.0-1.0)
  - `metadata`: Additional context about the source
- `total`: Number of results returned
- `query_time_ms`: Query execution time in milliseconds
- `search_mode`: 'semantic'

**Error Responses**:

```json
// 400 Bad Request
{
  "error": "Query parameter is required"
}

// 500 Internal Server Error
{
  "error": "Semantic search failed: No embedding model configured"
}
```

---

### Hybrid Search

Combines keyword (SOLR) and semantic (vector) search for best results.

**Endpoint**: `GET /solr/search/hybrid`

**Description**: Performs both keyword and semantic search, then merges results using Reciprocal Rank Fusion (RRF) for optimal relevance.

**Parameters**:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | Yes | - | Search query (natural language) |
| `limit` | integer | No | 10 | Maximum results to return (1-100) |
| `k` | integer | No | 60 | RRF constant (higher = more balanced) |
| `semanticWeight` | float | No | 0.5 | Weight for semantic results (0.0-1.0) |
| `keywordWeight` | float | No | 0.5 | Weight for keyword results (0.0-1.0) |

**Request Example**:
```bash
curl -X GET 'http://nextcloud.local/apps/openregister/api/solr/search/hybrid?query=Amsterdam+projects&limit=10&semanticWeight=0.6&keywordWeight=0.4' \
  -u admin:admin \
  -H "Content-Type: application/json"
```

**Response** (200 OK):
```json
{
  "results": [
    {
      "entity_id": "obj_789",
      "entity_type": "object",
      "chunk_text": "Amsterdam Urban Renewal Project aims to...",
      "similarity": 0.91,
      "score": 12.5,
      "sources": ["keyword", "semantic"],
      "metadata": {
        "schema": "Project",
        "title": "Amsterdam Urban Renewal"
      }
    },
    {
      "entity_id": "file_234",
      "entity_type": "file",
      "chunk_text": "Infrastructure projects in the Netherlands capital...",
      "similarity": 0.75,
      "score": 8.3,
      "sources": ["semantic"],
      "metadata": {
        "filename": "netherlands-infrastructure.pdf"
      }
    }
  ],
  "total": 2,
  "query_time_ms": 287,
  "search_mode": "hybrid",
  "breakdown": {
    "keyword_results": 5,
    "semantic_results": 8,
    "merged_results": 2
  }
}
```

**Response Fields**:
- All fields from semantic search, plus:
  - `score`: RRF score (higher = more relevant)
  - `sources`: Array showing which search modes found this result
  - `breakdown`: Statistics about result merging

**RRF Algorithm**:
```
RRF_score = Σ (1 / (k + rank_in_method))

where:
- k = RRF constant (default: 60)
- rank_in_method = position in keyword or semantic results
```

**Error Responses**: Same as semantic search

---

## Vectorization Endpoints

### Vectorize Single Object

Generate and store embedding for a specific object.

**Endpoint**: `POST /solr/objects/{objectId}/vectorize`

**Description**: Converts an object to text, generates its embedding, and stores it in the vector database.

**Path Parameters**:
- `objectId` (integer): ID of the object to vectorize

**Query Parameters**:
- `provider` (string, optional): Embedding provider ('openai' or 'ollama'). Defaults to configured provider.

**Request Example**:
```bash
curl -X POST 'http://nextcloud.local/apps/openregister/api/solr/objects/123/vectorize?provider=openai' \
  -u admin:admin \
  -H "Content-Type: application/json"
```

**Response** (200 OK):
```json
{
  "success": true,
  "object_id": 123,
  "vector_id": 4567,
  "text_length": 1234,
  "embedding_model": "text-embedding-3-large",
  "embedding_dimensions": 3072,
  "processing_time_ms": 850
}
```

**Error Responses**:
```json
// 404 Not Found
{
  "error": "Object not found"
}

// 500 Internal Server Error
{
  "error": "Vectorization failed: OpenAI API key not configured"
}
```

---

### Bulk Vectorize Objects

Vectorize multiple objects in batch (efficient for large datasets).

**Endpoint**: `POST /solr/objects/vectorize/bulk`

**Description**: Processes multiple objects at once with progress tracking and error handling.

**Request Body**:
```json
{
  "schemaId": 5,           // Optional: Filter by schema
  "registerId": 10,        // Optional: Filter by register
  "limit": 100,            // Max objects per batch (1-1000)
  "offset": 0,             // Pagination offset
  "provider": "openai"     // Optional: Override default provider
}
```

**Request Example**:
```bash
curl -X POST 'http://nextcloud.local/apps/openregister/api/solr/objects/vectorize/bulk' \
  -u admin:admin \
  -H "Content-Type: application/json" \
  -d '{
    "schemaId": 5,
    "limit": 100,
    "offset": 0
  }'
```

**Response** (200 OK):
```json
{
  "success": true,
  "processed": 100,
  "successful": 97,
  "failed": 3,
  "progress": {
    "total_objects": 1000,
    "processed_so_far": 100,
    "percentage": 10
  },
  "errors": [
    {
      "object_id": 145,
      "error": "Text extraction failed"
    },
    {
      "object_id": 167,
      "error": "API rate limit exceeded"
    },
    {
      "object_id": 189,
      "error": "Object has no extractable text"
    }
  ],
  "timing": {
    "total_time_ms": 12500,
    "avg_per_object_ms": 128
  },
  "pagination": {
    "limit": 100,
    "offset": 0,
    "has_more": true,
    "next_offset": 100
  }
}
```

**Response Fields**:
- `processed`: Number of objects attempted
- `successful`: Number successfully vectorized
- `failed`: Number that failed
- `progress`: Overall progress statistics
- `errors`: Array of errors (max 10 shown)
- `timing`: Performance metrics
- `pagination`: Info for next batch

**Usage Pattern** (Process all objects):
```bash
# Batch 1
curl -X POST '.../bulk' -d '{"limit":100, "offset":0}'

# Batch 2
curl -X POST '.../bulk' -d '{"limit":100, "offset":100}'

# Continue until has_more = false
```

---

### Get Vectorization Statistics

Retrieve stats about vectorized content.

**Endpoint**: `GET /solr/objects/vectorize/stats`

**Description**: Returns comprehensive statistics about vector embeddings.

**Request Example**:
```bash
curl -X GET 'http://nextcloud.local/apps/openregister/api/solr/objects/vectorize/stats' \
  -u admin:admin
```

**Response** (200 OK):
```json
{
  "total_vectors": 5678,
  "by_type": {
    "file": 3456,
    "object": 2222
  },
  "by_model": {
    "text-embedding-3-large": 4000,
    "text-embedding-3-small": 1678
  },
  "storage": {
    "total_bytes": 67890123,
    "total_mb": 64.7,
    "avg_per_vector_kb": 11.6
  },
  "objects": {
    "total_objects": 3000,
    "vectorized_objects": 2222,
    "pending_objects": 778,
    "percentage_complete": 74
  },
  "files": {
    "total_files": 500,
    "vectorized_files": 450,
    "pending_files": 50,
    "percentage_complete": 90,
    "total_chunks": 3456,
    "avg_chunks_per_file": 7.7
  },
  "recent_activity": {
    "last_24h": 234,
    "last_7d": 1567,
    "last_30d": 4321
  }
}
```

---

## Chat API Endpoints

### Send Chat Message

Send a message to the AI assistant and get a RAG-powered response.

**Endpoint**: `POST /chat/send`

**Description**: Processes user query, retrieves relevant context, and generates AI response.

**Request Body**:
```json
{
  "message": "What projects are we working on in Amsterdam?",
  "searchMode": "hybrid",      // 'hybrid', 'semantic', or 'keyword'
  "numSources": 5,             // 1-10
  "includeFiles": true,        // Include file content
  "includeObjects": true       // Include object data
}
```

**Request Example**:
```bash
curl -X POST 'http://nextcloud.local/apps/openregister/api/chat/send' \
  -u admin:admin \
  -H "Content-Type: application/json" \
  -d '{
    "message": "What are our sustainability goals?",
    "searchMode": "hybrid",
    "numSources": 5
  }'
```

**Response** (200 OK):
```json
{
  "response": "Based on your documents, your sustainability goals include:\n\n1. **Carbon Neutrality by 2030**: Reduce emissions by 50% by 2025, then offset remaining.\n2. **Renewable Energy**: Transition to 100% renewable sources by 2028.\n3. **Waste Reduction**: Achieve zero-waste operations by 2027.\n\nThese goals are outlined in the Sustainability Strategy 2025 document and the Board Meeting Minutes from March 2024.",
  "sources": [
    {
      "id": "file_567",
      "type": "file",
      "name": "Sustainability-Strategy-2025.pdf",
      "similarity": 0.92,
      "text": "Our primary goal is carbon neutrality by 2030..."
    },
    {
      "id": "obj_123",
      "type": "object",
      "name": "Board Meeting Minutes - March 2024",
      "similarity": 0.87,
      "text": "The board approved the waste reduction initiative..."
    }
  ],
  "conversationId": 42,
  "searchMode": "hybrid"
}
```

**Error Responses**:
```json
// 400 Bad Request
{
  "error": "Message is required"
}

// 500 Internal Server Error
{
  "error": "OpenAI chat provider is not configured"
}
```

---

### Get Chat History

Retrieve conversation history for the current user.

**Endpoint**: `GET /chat/history`

**Parameters**:
- `limit` (integer, optional): Max messages to return (default: 50)
- `offset` (integer, optional): Pagination offset (default: 0)

**Request Example**:
```bash
curl -X GET 'http://nextcloud.local/apps/openregister/api/chat/history?limit=20' \
  -u admin:admin
```

**Response** (200 OK):
```json
{
  "messages": [
    {
      "role": "user",
      "content": "What are our sustainability goals?",
      "timestamp": "2025-10-13T14:30:00Z"
    },
    {
      "role": "assistant",
      "content": "Based on your documents...",
      "sources": [...],
      "timestamp": "2025-10-13T14:30:05Z"
    }
  ],
  "count": 2
}
```

---

### Clear Chat History

Delete all conversation history for the current user.

**Endpoint**: `DELETE /chat/history`

**Request Example**:
```bash
curl -X DELETE 'http://nextcloud.local/apps/openregister/api/chat/history' \
  -u admin:admin
```

**Response** (200 OK):
```json
{
  "success": true,
  "deleted": 45
}
```

---

### Send Feedback

Submit feedback (thumbs up/down) for an AI response.

**Endpoint**: `POST /chat/feedback`

**Request Body**:
```json
{
  "messageId": 42,
  "feedback": "positive"  // 'positive', 'negative', or null
}
```

**Request Example**:
```bash
curl -X POST 'http://nextcloud.local/apps/openregister/api/chat/feedback' \
  -u admin:admin \
  -H "Content-Type: application/json" \
  -d '{"messageId": 42, "feedback": "positive"}'
```

**Response** (200 OK):
```json
{
  "success": true
}
```

---

## Rate Limits

**Current Limits** (default):
- Search endpoints: 100 requests/minute per user
- Vectorization: 10 requests/minute per user
- Chat: 20 messages/minute per user

**Rate Limit Headers** (in responses):
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1697203200
```

**Rate Limit Response** (429 Too Many Requests):
```json
{
  "error": "Rate limit exceeded",
  "retry_after_seconds": 45
}
```

---

## Best Practices

### Search Optimization

1. **Use Hybrid Mode**: Best balance of accuracy and recall
2. **Set Appropriate Thresholds**: 0.7-0.8 for semantic search
3. **Limit Results**: Request only what you need (10-20 typical)
4. **Cache Common Queries**: Implement client-side caching

### Vectorization Best Practices

1. **Batch Processing**: Use bulk endpoint for > 10 objects
2. **Off-Peak Processing**: Run large batches during low-traffic hours
3. **Error Handling**: Retry failed objects after delay
4. **Monitor Progress**: Check stats endpoint to track completion

### Chat Integration

1. **Stream Responses**: Implement SSE for real-time feel (future feature)
2. **Handle Errors Gracefully**: Show user-friendly messages
3. **Provide Feedback UI**: Enable thumbs up/down for quality tracking
4. **Cache History**: Load recent messages on page load

---

## SDK Examples

### JavaScript (Axios)

```javascript
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://nextcloud.local/apps/openregister/api',
  auth: {
    username: 'admin',
    password: 'admin'
  }
});

// Semantic search
async function semanticSearch(query, limit = 10) {
  try {
    const response = await api.get('/solr/search/semantic', {
      params: { query, limit }
    });
    return response.data.results;
  } catch (error) {
    console.error('Search failed:', error.response.data);
  }
}

// Bulk vectorization
async function vectorizeObjects(schemaId, limit = 100, offset = 0) {
  try {
    const response = await api.post('/solr/objects/vectorize/bulk', {
      schemaId,
      limit,
      offset
    });
    return response.data;
  } catch (error) {
    console.error('Vectorization failed:', error.response.data);
  }
}

// Chat
async function sendChatMessage(message) {
  try {
    const response = await api.post('/chat/send', {
      message,
      searchMode: 'hybrid',
      numSources: 5
    });
    return response.data;
  } catch (error) {
    console.error('Chat failed:', error.response.data);
  }
}
```

### Python (requests)

```python
import requests
from requests.auth import HTTPBasicAuth

BASE_URL = 'http://nextcloud.local/apps/openregister/api'
AUTH = HTTPBasicAuth('admin', 'admin')

def semantic_search(query, limit=10):
    """Perform semantic search"""
    response = requests.get(
        f'{BASE_URL}/solr/search/semantic',
        params={'query': query, 'limit': limit},
        auth=AUTH
    )
    response.raise_for_status()
    return response.json()['results']

def vectorize_objects(schema_id, limit=100, offset=0):
    """Bulk vectorize objects"""
    response = requests.post(
        f'{BASE_URL}/solr/objects/vectorize/bulk',
        json={
            'schemaId': schema_id,
            'limit': limit,
            'offset': offset
        },
        auth=AUTH
    )
    response.raise_for_status()
    return response.json()

def send_chat_message(message):
    """Send message to AI chat"""
    response = requests.post(
        f'{BASE_URL}/chat/send',
        json={
            'message': message,
            'searchMode': 'hybrid',
            'numSources': 5
        },
        auth=AUTH
    )
    response.raise_for_status()
    return response.json()

# Usage
results = semantic_search('budget planning')
for result in results:
    print(f"{result['similarity']:.2f}: {result['chunk_text'][:100]}...")
```

---

## Troubleshooting

### Common Issues

#### "No embedding model configured"
**Solution**: Configure OpenAI or Ollama in Settings → LLM Configuration

#### "Search returns empty results"
**Solution**: 
- Ensure content is vectorized (check stats endpoint)
- Lower similarity threshold
- Try hybrid mode instead of semantic

#### "Vectorization fails with API error"
**Solution**:
- Verify API key is correct
- Check API quota/billing
- Try Ollama for local processing

#### "Chat responses are slow"
**Solution**:
- Reduce numSources (default: 5)
- Use keyword mode for faster (but less accurate) results
- Consider using Ollama locally

---

## Changelog

### v2.4.0 (2025-10-13)
- Added hybrid search endpoint
- Added bulk vectorization
- Added chat API endpoints
- Improved error handling

### v2.3.0 (2025-10-01)
- Initial semantic search implementation
- Single object vectorization
- Vector statistics endpoint

---

*Last updated: October 13, 2025*  
*API Version: 2.4.0*

