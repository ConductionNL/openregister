# Performance Optimization Guide

## Current Performance Issues

Chat responses can take 50+ seconds, which is too slow for a good user experience.

## Performance Monitoring

We've added detailed timing logs to track where time is spent:

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

## Check Logs

```bash
docker logs -f master-nextcloud-1 | grep "PERFORMANCE"
```

## Optimization Strategies

### 1. **Streaming Responses** (High Impact) 🔴

**Problem:** Currently waiting for complete response before returning anything.

**Solution:** Implement Server-Sent Events (SSE) streaming:
- Frontend shows response as it's generated
- Perceived performance improvement of 10x+
- User sees first tokens in <1s instead of waiting 50s

**Implementation:**
- Modify `ChatController::sendMessage()` to return SSE stream
- Use `$chat->generateStreamOfText()` for Ollama
- Update frontend to consume SSE and display progressively

### 2. **Ollama Model Optimization** (High Impact) 🔴

**Current Model:** llama3.2 (large, slow)

**Alternatives:**
```bash
# Faster models for Ollama:
docker exec openregister-ollama ollama pull llama3.2:1b    # Tiny (faster)
docker exec openregister-ollama ollama pull phi3:mini      # Microsoft Phi3 (fast)
docker exec openregister-ollama ollama pull gemma2:2b      # Google Gemma (balanced)
```

**Configuration:**
- Small models (1-3B parameters): 5-10s response time
- Medium models (7-8B parameters): 20-30s response time
- Large models (70B+ parameters): 60-120s response time

### 3. **GPU Acceleration** (Very High Impact) 🔴

**Problem:** Ollama running on CPU only (very slow).

**Solution:** Enable GPU support in `docker-compose.yml`:

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

**Speed improvement:** 10-50x faster inference.

### 4. **Context Window Optimization** (Medium Impact) 🟡

**Problem:** Sending too much context to LLM.

**Current:** Full conversation history + RAG context.

**Optimizations:**
- Limit conversation history to last N messages
- Truncate RAG context to top 3-5 most relevant chunks
- Implement smart summarization for long conversations

**In `buildMessageHistory()`:**
```php
// Limit to last 10 messages instead of all
$limit = 10;
```

### 5. **Tool Call Optimization** (Medium Impact) 🟡

**Problem:** Sequential tool calls require multiple LLM roundtrips.

**Example Flow:**
1. User: "List registers and schemas"
2. LLM generates → calls `list_registers` → waits (25s)
3. LLM receives result → calls `list_schemas` → waits (25s)
4. Total: 50s

**Optimization:**
- Implement parallel tool calls where possible
- Cache tool results for session
- Reduce tool call iterations with better prompting

### 6. **Vector Search Optimization** (Low-Medium Impact) 🟡

**Current:** Synchronous vector search can be slow.

**Optimizations:**
- Disable RAG for agents that don't need it
- Use faster embedding models (nomic-embed-text)
- Implement vector search caching
- Index optimization in vector database

### 7. **Model Keep-Alive** (Low Impact) 🟢

**Problem:** Model unloads from memory between requests.

**Solution:** Already configured in `docker-compose.yml`:
```yaml
environment:
  - OLLAMA_KEEP_ALIVE=30m  # Keep model in memory
```

### 8. **Prompt Optimization** (Low Impact) 🟢

**Problem:** Long system prompts increase token count.

**Optimization:**
- Keep prompts concise
- Avoid redundant instructions
- Use tool descriptions instead of prompt examples

## Quick Wins (Implement Now)

### ✅ Enable Performance Logging
Already implemented - check logs to identify bottlenecks.

### ✅ Switch to Smaller Model
```bash
# In OpenRegister settings:
# Change Chat Model from: llama3.2
# Change to: phi3:mini or gemma2:2b
```

### ✅ Disable RAG for Fast Responses
```javascript
// In agent settings:
enableRag: false  // For agents that don't need context search
```

## Future Improvements

### Streaming Implementation (Priority 1)

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

### Response Caching

- Cache LLM responses for identical queries
- Cache tool call results for session
- Implement Redis for distributed caching

### Parallel Processing

- Run vector search and history building in parallel
- Execute independent tool calls in parallel
- Use async PHP with ReactPHP/Amp

## Monitoring

Add to `ChatController` for response headers:
```php
$response->addHeader('X-Generation-Time', round($llmTime, 2));
$response->addHeader('X-Context-Time', round($contextTime, 2));
```

Frontend can then display: "Generated in 25.3s"

## Testing

After each optimization, test with:
```bash
# Simple query (no tools)
curl -X POST /api/chat/send -d '{"message": "Hello"}'

# Complex query (with tools)
curl -X POST /api/chat/send -d '{"message": "List all registers"}'

# Measure response time
time curl -X POST /api/chat/send -d '{"message": "Hello"}'
```

## Expected Results

With all optimizations:
- **Simple query:** 2-5s (down from 15-20s)
- **Tool query:** 8-15s (down from 50s+)
- **With streaming:** Perceived as <1s (first tokens immediate)
- **With GPU:** 0.5-2s (down from 15-20s)

## Priority Order

1. 🔴 **Enable GPU** → 10-50x speedup
2. 🔴 **Switch to smaller model** → 2-3x speedup
3. 🔴 **Implement streaming** → Perceived 10x+ improvement
4. 🟡 **Optimize context window** → 1.5-2x speedup
5. 🟡 **Tool call optimization** → 2x speedup for multi-tool queries
6. 🟢 **Prompt optimization** → 1.2x speedup




