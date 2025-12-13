# Model Selection for Tool-Oriented Agents

## Overview

When creating AI agents that use tools (function calling), **model selection is the most important factor** for success. Not all LLMs can reliably execute functions - many will describe what they would do instead of actually doing it.

This guide helps you select the right model for tool-oriented agents in OpenRegister.

## Quick Recommendations

### **For Production Tool Agents**

**Best Choice:** `mistral-nemo` (Ollama)
- ⭐⭐⭐⭐⭐ Excellent function calling
- Free and runs locally
- 7.1 GB model size
- Reliable parameter handling

**Alternative:** `gpt-4o-mini` (OpenAI)
- ⭐⭐⭐⭐⭐ Excellent function calling
- Requires API key and costs money
- Best overall accuracy

### **For Development/Testing**

**Good Choice:** `llama3.2:3b` (Ollama)
- ⭐⭐⭐ Good function calling
- Lightweight (2 GB)
- Fast for testing
- Some inconsistencies

## Detailed Model Comparison

### Ollama Models

#### ⭐⭐⭐⭐⭐ **Mistral Nemo** (RECOMMENDED)

**Model Name:** `mistral-nemo`

**Size:** 7.1 GB

**Function Calling Quality:**
- ✅ Consistently calls functions when appropriate
- ✅ Correctly formats all parameter types
- ✅ Handles complex nested parameters
- ✅ Low hallucination rate
- ✅ Good at asking for missing information

**Performance:**
- Speed: Fast (even on CPU)
- Memory: ~8 GB RAM recommended
- Context: 128K tokens

**Use Cases:**
- CMS content management (pages, menus)
- Object CRUD operations
- Multi-step workflows
- Production deployments

**Installation:**
```bash
docker exec openregister-ollama ollama pull mistral-nemo
```

**Agent Configuration:**
```json
{
  'provider': 'ollama',
  'model': 'mistral-nemo',
  'temperature': 0.3,
  'tools': ['opencatalogi.cms']
}
```

---

#### ⭐⭐⭐⭐ **Llama 3.1 8B**

**Model Name:** `llama3.1:8b`

**Size:** 4.7 GB

**Function Calling Quality:**
- ✅ Reliable function execution
- ✅ Good parameter handling
- ⚠️ Sometimes verbose in responses
- ✅ Handles follow-up well

**Performance:**
- Speed: Fast
- Memory: ~6 GB RAM
- Context: 128K tokens

**Use Cases:**
- General tool usage
- Conversational agents with tools
- Budget-friendly option

---

#### ⭐⭐⭐ **Llama 3.2 3B**

**Model Name:** `llama3.2:3b`

**Size:** 2.0 GB

**Function Calling Quality:**
- ✅ Basic function calling works
- ⚠️ Sometimes describes instead of executing
- ⚠️ May need explicit prompting
- ✅ Lightweight and fast

**Performance:**
- Speed: Very fast
- Memory: ~4 GB RAM
- Context: 128K tokens

**Use Cases:**
- Development and testing
- Simple tool operations
- Resource-constrained environments

---

#### ⭐⭐ **Mistral 7B** (NOT RECOMMENDED FOR TOOLS)

**Model Name:** `mistral:7b`

**Size:** 4.4 GB

**Function Calling Quality:**
- ❌ Unreliable function calling
- ❌ Often describes what it would do
- ❌ Inconsistent parameter formatting
- ⚠️ May work with very explicit prompts

**Recommendation:** **DO NOT USE** for tool-oriented agents. Use `mistral-nemo` instead.

---

#### ❌ **Not Suitable for Function Calling**

These models should **NOT** be used for agents with tools:
- `qwen2:0.5b` - Too small for function calling
- `phi3:mini` - Limited function support
- `tinyllama` - No function calling capability

---

### OpenAI Models

#### ⭐⭐⭐⭐⭐ **GPT-4o Mini** (EXCELLENT)

**Model Name:** `gpt-4o-mini`

**Function Calling Quality:**
- ✅ Excellent function execution
- ✅ Perfect parameter handling
- ✅ Minimal hallucination
- ✅ Great at multi-step workflows

**Use Cases:**
- Production deployments
- Complex workflows
- High-reliability requirements

**Cost:** Paid API (usage-based pricing)

---

## Installation Guide

### Step 1: Install Recommended Model

```bash
# Connect to your Ollama container
docker exec openregister-ollama ollama pull mistral-nemo

# Verify installation
docker exec openregister-ollama ollama list
```

### Step 2: Configure Agent

When creating a tool-oriented agent:

1. Navigate to **OpenRegister > Agents**
2. Create new agent
3. Set **Provider:** `ollama`
4. Set **Model:** `mistral-nemo`
5. Add tools (e.g., `opencatalogi.cms`)
6. Set **Temperature:** `0.3` (lower = more reliable)

### Step 3: Test Function Calling

```bash
# Test via API
curl -X POST http://localhost/index.php/apps/openregister/api/chat/send \
  -H 'Content-Type: application/json' \
  -u 'admin:admin' \
  -d '{
    'agentUuid': 'your-agent-uuid',
    'message': 'Create a menu called Test'
  }'
```

**Expected Behavior:**
- ✅ Agent should ASK for required information (items, position)
- ✅ Once info gathered, should EXECUTE the tool
- ✅ Should return success message with created object details

**Bad Behavior (wrong model):**
- ❌ Agent outputs JSON instead of executing
- ❌ Agent describes what it would do
- ❌ Agent ignores tool availability

---

## Troubleshooting

### Issue: Agent describes actions but doesn't execute

**Cause:** Model doesn't support function calling well

**Solution:** Switch to `mistral-nemo` or `llama3.1:8b`

### Issue: Agent calls functions with wrong parameters

**Cause:** Model has poor parameter understanding

**Solution:** 
1. Switch to `mistral-nemo`
2. Lower temperature to 0.2-0.3
3. Improve system prompt with parameter examples

### Issue: Agent never calls functions

**Cause:** 
- Model doesn't support tools
- Tools not properly registered
- Agent not configured with tools

**Solution:**
1. Verify model supports function calling (see table above)
2. Check tools are registered: See [Tool Registration](../development/tool-registration.md)
3. Verify agent has tools assigned in configuration

---

## Performance Optimization

### For Tool-Oriented Agents

**Optimal Settings:**

```json
{
  'provider': 'ollama',
  'model': 'mistral-nemo',
  'temperature': 0.3,
  'maxTokens': 2000,
  'topP': 0.9,
  'frequencyPenalty': 0.0,
  'presencePenalty': 0.0
}
```

**Why these settings?**
- **Low temperature (0.3)**: More deterministic function calling
- **Higher maxTokens (2000)**: Allows detailed responses after tool execution
- **Standard topP (0.9)**: Balanced sampling
- **No penalties**: Avoid interfering with function formatting

### For Chat-Only Agents (No Tools)

**Optimal Settings:**

```json
{
  'provider': 'ollama',
  'model': 'llama3.2:3b',
  'temperature': 0.7,
  'maxTokens': 1000
}
```

---

## Migration Guide

### Upgrading from mistral:7b to mistral-nemo

1. **Install mistral-nemo:**
   ```bash
   docker exec openregister-ollama ollama pull mistral-nemo
   ```

2. **Update agent configuration:**
   - Navigate to agent settings
   - Change model from `mistral:7b` to `mistral-nemo`
   - Lower temperature to 0.3
   - Save configuration

3. **Test thoroughly:**
   - Test each tool the agent uses
   - Verify parameter handling
   - Check multi-step workflows

4. **Expected improvements:**
   - ✅ Functions actually execute (vs. being described)
   - ✅ Correct parameter formatting
   - ✅ Better conversation flow
   - ✅ Fewer hallucinations

---

## Real-World Examples

### CMS Content Manager Agent

**Configuration:**
```json
{
  'name': 'CMS Content Manager',
  'provider': 'ollama',
  'model': 'mistral-nemo',
  'temperature': 0.3,
  'tools': ['opencatalogi.cms'],
  'prompt': 'You are a helpful CMS assistant...'
}
```

**Behavior:**
- Asks for menu title, items, position
- Executes `cms_create_menu` function when complete
- Returns confirmation with created menu details

---

### Document Search Agent  

**Configuration:**
```json
{
  'name': 'Document Search',
  'provider': 'ollama',
  'model': 'llama3.2:3b',
  'temperature': 0.5,
  'tools': ['openregister.objects'],
  'ragEnabled': true,
  'searchMode': 'hybrid'
}
```

**Behavior:**
- Searches documents using RAG
- Can execute searches with filters
- Lower resource requirements

---

## Related Documentation

- [Function Calling Overview](./function-calling.md) - Complete tool documentation
- [Tool Registration](../development/tool-registration.md) - Creating custom tools
- [Chat & RAG](./chat-rag-deepdive.md) - Understanding conversational AI

---

## Summary

For **tool-oriented agents**, use **`mistral-nemo`**. It provides the best balance of:
- ✅ Reliability
- ✅ Performance  
- ✅ Cost (free)
- ✅ Function calling quality

Avoid using `mistral:7b`, `qwen2:0.5b`, or `phi3:mini` for agents that need to execute functions.


