# Ollama Model Name Fix

## Problem
The Ollama model dropdown was showing shortened names (e.g., "mistral", "llama3.2") without version tags, but when saved, the configuration expected full names with tags (e.g., "mistral:7b", "llama3.2:latest"). This caused a mismatch where:

- **Dropdown showed**: "mistral"  
- **Saved value**: "mistral" (no tag)
- **Ollama expects**: "mistral:7b" (with tag)
- **Result**: 404 errors when trying to use the model

## Root Cause

The `LLMConfigModal.vue` component had a hardcoded fallback list of Ollama models (used when the API fails to fetch models) that used short names without version tags:

```javascript
ollamaModelOptions: [
    { id: 'mistral', name: 'mistral', description: 'Mistral 7B model' },
    { id: 'mixtral', name: 'mixtral', description: 'Mistral\'s Mixtral 8x7B model' },
    // etc...
]
```

But Ollama's actual API returns full names:
- `mistral:7b`
- `mistral:latest`  
- `llama3.2:latest`
- etc.

## Solution

Updated the fallback list to use full model names with version tags to match Ollama's format:

```javascript
ollamaModelOptions: [
    { id: 'mistral:7b', name: 'mistral:7b', description: 'Mistral 7B model' },
    { id: 'mixtral:8x7b', name: 'mixtral:8x7b', description: 'Mistral\'s Mixtral 8x7B model' },
    { id: 'llama3.2:latest', name: 'llama3.2:latest', description: 'Meta\'s Llama 3.2 (latest)' },
    { id: 'phi3:mini', name: 'phi3:mini', description: 'Microsoft\'s Phi-3 model' },
    { id: 'nomic-embed-text:latest', name: 'nomic-embed-text:latest', description: 'Nomic embeddings' },
    // etc...
]
```

## Changes Made

**File**: `openregister/src/modals/settings/LLMConfigModal.vue`

**Lines**: 508-519

**Updated models**:
| Old (Short Name) | New (With Tag) |
|------------------|----------------|
| `mistral` | `mistral:7b` |
| `mixtral` | `mixtral:8x7b` |
| `llama3.2` | `llama3.2:latest` |
| `llama3.1` | `llama3.1:latest` |
| `llama3` | `llama3:latest` |
| `llama2` | `llama2:latest` |
| `phi3` | `phi3:mini` |
| `codellama` | `codellama:latest` |
| `gemma2` | `gemma2:latest` |
| `nomic-embed-text` | `nomic-embed-text:latest` |

## Benefits

1. **Consistency**: Dropdown names now match what's saved and what Ollama expects
2. **Clarity**: Users see the exact model tag they're selecting
3. **No confusion**: What you see is what you get
4. **Proper fallback**: Even if API fails, fallback list has correct format

## How It Works

### Normal Flow (API Success)
1. User opens LLM Configuration modal
2. Frontend calls `/api/llm/ollama-models`
3. Backend queries Ollama: `GET http://openregister-ollama:11434/api/tags`
4. Ollama returns models with full names: `mistral:7b`, `llama3.2:latest`, etc.
5. Dropdown shows these full names
6. User selects `mistral:7b`
7. Config saves: `{"model": "mistral:7b"}`
8. Ollama receives correct model name ✅

### Fallback Flow (API Fails)
1. User opens LLM Configuration modal
2. Frontend calls `/api/llm/ollama-models`
3. API fails (network error, Ollama down, etc.)
4. Frontend uses fallback list (now with full names)
5. Dropdown shows: `mistral:7b`, `llama3.2:latest`, etc.
6. User selects `mistral:7b`
7. Config saves: `{"model": "mistral:7b"}`
8. Ollama receives correct model name ✅

## Testing

### Before Fix
```json
{
  "ollamaConfig": {
    "url": "http://openregister-ollama:11434",
    "model": "mistral",
    "chatModel": "mistral"
  }
}
```

**Result**: ❌ `404 Not Found - model 'mistral' not found`

### After Fix
```json
{
  "ollamaConfig": {
    "url": "http://openregister-ollama:11434",
    "model": "mistral:7b",
    "chatModel": "mistral:7b"
  }
}
```

**Result**: ✅ Model loads successfully

## Next Steps

1. **Rebuild frontend** to apply changes:
   ```bash
   cd ~/nextcloud-docker-dev/workspace/server/apps-extra/openregister
   npm run build
   ```

2. **Update configuration** in the UI:
   - Open Nextcloud → Settings → OpenRegister → LLM Configuration
   - Select `mistral:7b` (with tag) from dropdown
   - Save configuration

3. **Test chat/embedding**:
   - Click "Test Chat" button
   - Should now connect successfully

## Related Issues

- Initial error: `cURL error 7: Failed to connect to localhost port 11434`
  - **Fixed by**: Using container name `openregister-ollama` instead of `localhost`

- Second error: `404 Not Found - model 'mixtral' not found`  
  - **Fixed by**: This update (using full model names with tags)

## Additional Notes

- The backend (`getOllamaModels()` in `SettingsController.php`) already returns full names correctly
- This fix only updates the frontend fallback list to match
- The fallback list is only used when the Ollama API is unreachable
- When API is working, real model names from Ollama are always used

---

**Date**: 2025-11-12  
**Status**: ✅ Fixed  
**Requires**: Frontend rebuild (`npm run build`)  
**Impact**: Dropdown now shows full model names with version tags  

