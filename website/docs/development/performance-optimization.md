---
title: Performance Optimization
sidebar_position: 2
description: Guide to optimizing OpenRegister performance, especially for chat responses and vector search
keywords:
  - Open Register
  - Performance
  - Optimization
  - Chat
  - Vector Search
---

# Performance Optimization Guide

This guide covers performance optimization strategies for OpenRegister, with a focus on chat responses and vector search operations.

## Performance Monitoring

OpenRegister includes detailed timing logs to track where time is spent during chat operations:

```
[ChatService] Message processed - OVERALL PERFORMANCE
- contextRetrieval: Time to search vectors and build context
- historyBuilding: Time to load conversation history
- llmGeneration: Time for LLM to generate response (includes tool calls)

[ChatService] Response generated - PERFORMANCE
- toolsLoading: Time to load agent tools
- llmGeneration: Actual LLM inference time
- overhead: Time spent in framework/serialization
```

### Checking Performance Logs

```bash
docker logs -f master-nextcloud-1 | grep "PERFORMANCE"
```

## Optimization Strategies

### 1. Streaming Responses

**Current Implementation:** Responses can be streamed using Server-Sent Events (SSE) for improved perceived performance.

**Benefits:**
- Frontend shows response as it's generated
- Perceived performance improvement of 10x+
- User sees first tokens in &lt;1s instead of waiting for complete response

**Implementation:**
- `ChatController::sendMessage()` can return SSE stream
- Use `$chat->generateStreamOfText()` for Ollama
- Frontend consumes SSE and displays progressively

### 2. Ollama Model Selection

**Model Size Impact:**
- Small models (1-3B parameters): 5-10s response time
- Medium models (7-8B parameters): 20-30s response time
- Large models (70B+ parameters): 60-120s response time

**Recommended Models:**
```bash
# Faster models for Ollama:
docker exec openregister-ollama ollama pull llama3.2:1b    # Tiny (faster)
docker exec openregister-ollama ollama pull phi3:mini      # Microsoft Phi3 (fast)
docker exec openregister-ollama ollama pull gemma2:2b      # Google Gemma (balanced)
```

**Configuration:** Select models in Settings → LLM Configuration → Chat Provider

### 3. GPU Acceleration

**Current Support:** GPU acceleration is supported for Ollama when configured in `docker-compose.yml`:

```yaml
services:
  ollama:
    image: ollama/ollama:latest
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
```

**Speed improvement:** 10-50x faster inference when GPU is available.

**Verification:**
```bash
# Check if GPU is being used
docker exec openregister-ollama nvidia-smi
```

### 4. Context Window Optimization

**Current Behavior:** Full conversation history + RAG context is sent to LLM.

**Optimization Options:**
- Limit conversation history to last N messages
- Truncate RAG context to top 3-5 most relevant chunks
- Implement smart summarization for long conversations

**Configuration:** Adjust in `buildMessageHistory()`:
```php
// Limit to last 10 messages instead of all
$limit = 10;
```

### 5. Tool Call Optimization

**Current Behavior:** Sequential tool calls require multiple LLM roundtrips.

**Example Flow:**
1. User: "List registers and schemas"
2. LLM generates → calls `list_registers` → waits
3. LLM receives result → calls `list_schemas` → waits
4. Total time accumulates for each call

**Optimization Strategies:**
- Implement parallel tool calls where possible
- Cache tool results for session
- Reduce tool call iterations with better prompting

### 6. Vector Search Optimization

**Current Implementation:** Vector search can be optimized through several methods:

**Optimization Options:**
- Disable RAG for agents that don't need it
- Use faster embedding models (nomic-embed-text)
- Implement vector search caching
- Index optimization in vector database
- Use PostgreSQL + pgvector for database-level vector operations

**Configuration:** Adjust RAG settings per agent in agent configuration.

### 7. Model Keep-Alive

**Current Configuration:** Model keep-alive is configured in `docker-compose.yml`:

```yaml
environment:
  - OLLAMA_KEEP_ALIVE=30m  # Keep model in memory
```

**Purpose:** Prevents model from unloading from memory between requests, reducing reload time.

### 8. Prompt Optimization

**Current Approach:** System prompts are optimized for clarity and conciseness.

**Best Practices:**
- Keep prompts concise
- Avoid redundant instructions
- Use tool descriptions instead of prompt examples

## Quick Wins

### Enable Performance Logging

Performance logging is already implemented - check logs to identify bottlenecks:

```bash
docker logs -f master-nextcloud-1 | grep "PERFORMANCE"
```

### Switch to Smaller Model

In OpenRegister settings:
- Navigate to Settings → LLM Configuration → Chat Provider
- Change Chat Model from large models (llama3.2) to smaller models (phi3:mini or gemma2:2b)

### Disable RAG for Fast Responses

For agents that don't need context search:
```javascript
// In agent settings:
enableRag: false  // For agents that don't need context search
```

## Streaming Implementation

**Backend:**
```php
public function sendMessageStream(int $conversationId): StreamResponse {
    $stream = function () use ($conversationId) {
        // ... setup ...
        
        foreach ($chat->generateStreamOfText($prompt) as $chunk) {
            yield "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
        }
        
        yield "data: [DONE]\n\n";
    };
    
    return new StreamResponse($stream);
}
```

**Frontend:**
```javascript
const eventSource = new EventSource('/api/chat/stream');
eventSource.onmessage = (event) => {
    const data = JSON.parse(event.data);
    if (data.chunk) {
        appendToMessage(data.chunk);
    }
};
```

## Response Caching

**Current Support:**
- Cache LLM responses for identical queries
- Cache tool call results for session
- Redis support for distributed caching

## Parallel Processing

**Current Capabilities:**
- Run vector search and history building in parallel
- Execute independent tool calls in parallel
- Use async PHP with ReactPHP/Amp

## Monitoring

Performance metrics can be added to response headers:

```php
$response->addHeader('X-Generation-Time', round($llmTime, 2));
$response->addHeader('X-Context-Time', round($contextTime, 2));
```

Frontend can then display: "Generated in 25.3s"

## Testing Performance

After each optimization, test with:

```bash
# Simple query (no tools)
curl -X POST /api/chat/send -d '{"message": "Hello"}'

# Complex query (with tools)
curl -X POST /api/chat/send -d '{"message": "List all registers"}'

# Measure response time
time curl -X POST /api/chat/send -d '{"message": "Hello"}'
```

## Expected Performance

With optimizations applied:
- **Simple query:** 2-5s (down from 15-20s)
- **Tool query:** 8-15s (down from 50s+)
- **With streaming:** Perceived as &lt;1s (first tokens immediate)
- **With GPU:** 0.5-2s (down from 15-20s)

## Priority Order

1. 🔴 **Enable GPU** → 10-50x speedup
2. 🔴 **Switch to smaller model** → 2-3x speedup
3. 🔴 **Implement streaming** → Perceived 10x+ improvement
4. 🟡 **Optimize context window** → 1.5-2x speedup
5. 🟡 **Tool call optimization** → 2x speedup for multi-tool queries
6. 🟢 **Prompt optimization** → 1.2x speedup

## Database Performance

For vector search operations, database choice significantly impacts performance:

- **MariaDB/MySQL**: Vector similarity calculated in PHP (slower)
- **PostgreSQL + pgvector**: Database-level vector operations (10-100x faster)

See [Database Status Tile](../features/configurations.md#database-status-tile) in LLM Configuration settings for current database status and recommendations.

## Database Index Optimization

### Performance Issues Identified

#### Issue 1: Missing Database Indexes
**Problem**: Object queries taking 30 seconds first time

**Root Cause**: Missing indexes on commonly searched fields (uuid, slug, name, summary, description)

#### Issue 2: External App Cache Miss
**Problem**: External app queries taking 4 seconds every time (no caching)

**Root Cause**: External app context detection creating different cache keys each time

### Comprehensive Database Index Migration

Created migration with **12 strategic performance indexes**:

```sql
-- Critical single-column indexes
CREATE INDEX objects_uuid_perf_idx ON openregister_objects (uuid);
CREATE INDEX objects_slug_perf_idx ON openregister_objects (slug);
CREATE INDEX objects_name_perf_idx ON openregister_objects (name);
CREATE INDEX objects_summary_perf_idx ON openregister_objects (summary);
CREATE INDEX objects_description_perf_idx ON openregister_objects (description);

-- Critical composite indexes  
CREATE INDEX objects_main_search_idx ON openregister_objects (register, schema, organisation, published);
CREATE INDEX objects_publication_filter_idx ON openregister_objects (published, depublished, created);
CREATE INDEX objects_rbac_tenancy_idx ON openregister_objects (owner, organisation, register, schema);
CREATE INDEX objects_ultimate_perf_idx ON openregister_objects (register, schema, organisation, published, owner);
```

**Run the Migration:**
```bash
# Apply the new indexes
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ upgrade

# Verify indexes were created
docker exec -u 33 master-nextcloud-1 mysql -u nextcloud -p nextcloud \
  -e 'SHOW INDEX FROM openregister_objects WHERE Key_name LIKE "%perf%";'
```

### Expected Performance Improvements

**After Index Migration:**

| Endpoint | Before | After | Improvement |
|----------|---------|--------|-------------|
| `/api/objects/voorzieningen/product` | 30s | &lt;1s | **97% faster** |
| `/api/objects/voorzieningen/organisatie` | 30s | &lt;1s | **97% faster** |
| General object searches | 5-15s | 0.1-0.5s | **95% faster** |

**After Cache Fix:**

| Endpoint | Before | After | Improvement |
|----------|---------|--------|-------------|
| `/api/apps/opencatalogi/api/publications` | 4s every time | 4s → 50ms | **Cache Hit: 98% faster** |

## Search Optimization

### Database Index Optimization

**Migration**: `lib/Migration/Version1Date20250102120000.php`

Added critical indexes for search performance:

#### Single-Column Search Indexes
- `objects_name_search_idx` - Index on `name` column
- `objects_summary_search_idx` - Index on `summary` column  
- `objects_description_search_idx` - Index on `description` column

#### Composite Search Indexes
- `objects_name_deleted_published_idx` - Combined search + lifecycle filtering
- `objects_summary_deleted_published_idx` - Summary search + lifecycle filtering
- `objects_description_deleted_published_idx` - Description search + lifecycle filtering
- `objects_name_register_schema_idx` - Name search + register/schema filtering
- `objects_summary_register_schema_idx` - Summary search + register/schema filtering
- `objects_name_organisation_deleted_idx` - Name search + multi-tenancy filtering
- `objects_summary_organisation_deleted_idx` - Summary search + multi-tenancy filtering

**Performance Impact**: These indexes enable fast lookup on frequently searched metadata columns, reducing query times from seconds to milliseconds.

### Schema Caching System

**Service**: `lib/Service/SchemaCacheService.php`

Implemented comprehensive schema caching with:
- **In-memory caching** for frequently accessed schemas
- **Database-backed cache** with configurable TTL
- **Batch schema loading** for multiple schemas
- **Automatic cache invalidation** when schemas are updated
- **Cache statistics and monitoring**

**Performance Benefits**:
- Eliminates repeated database queries for schema loading
- Reduces schema processing overhead
- Enables predictable performance for schema-dependent operations
- Supports high-concurrency scenarios

### Optimized Search Query Execution

**Handler**: `lib/Db/ObjectHandlers/MariaDbSearchHandler.php`

Enhanced search performance with prioritized search strategy:

#### Search Priority Order
1. **PRIORITY 1**: Indexed metadata columns (name, summary, description)
   - Fastest performance using database indexes
   - Direct column access with LOWER() function
   
2. **PRIORITY 2**: Other metadata fields (image, etc.)
   - Moderate performance with direct column access
   - No indexes but faster than JSON search
   
3. **PRIORITY 3**: JSON object search
   - Comprehensive fallback using JSON_SEARCH()
   - Slowest but ensures complete search coverage

**Performance Impact**:
- Dramatic improvement in search response times
- Leverages database indexes for common searches
- Maintains comprehensive search coverage

### Performance Expected Improvements

#### Search Performance
- **Metadata searches**: 10-50x improvement using indexes
- **Full-text searches**: 3-10x improvement with prioritized strategy
- **Complex searches**: 5-15x improvement with composite indexes

#### Schema Loading Performance
- **Individual schemas**: 5-10x improvement with caching
- **Batch schema loading**: 10-20x improvement with bulk operations
- **Schema-dependent operations**: Consistent sub-millisecond performance

## Related Documentation

- [Ollama Setup](./ollama.md) - Ollama configuration and GPU setup
- [Vector Search Backends](../technical/vector-search-backends.md) - Vector search backend options
- [Search Optimization](./search-optimization.md) - Detailed search optimization guide

