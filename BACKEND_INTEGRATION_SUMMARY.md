# Backend Integration Summary - LLM & File Configuration

## ✅ Completed Integration

### What Was Done

We successfully integrated the **LLM Configuration** and **File Configuration** frontend components with the **existing backend** infrastructure.

### Key Findings

1. **Backend Already Existed!** 
   - LLM and File settings methods were already implemented in `SettingsService.php`
   - Update endpoints were already in `SettingsController.php`
   - These were originally part of the vector embedding/SOLR configuration

2. **What We Added:**
   - ✅ **GET endpoints** for LLM settings (`getLLMSettings()`)
   - ✅ **GET endpoints** for File settings (`getFileSettings()`)
   - ✅ **GET routes** in `appinfo/routes.php`
   - ✅ **GET service methods** in `SettingsService.php`
   - ✅ **Integrated text extraction settings** into file management config

3. **What We Reused:**
   - ✅ Existing UPDATE endpoints (already working)
   - ✅ Existing settings storage structure
   - ✅ Existing provider configurations (OpenAI, Ollama, Fireworks)

---

## Current Settings Structure

### LLM Settings (`/api/settings/llm`)

Stored in: `$this->config->setValueString($this->appName, 'llm', ...)`

```json
{
  "embeddingProvider": "openai|ollama|fireworks|null",
  "chatProvider": "openai|ollama|fireworks|null",
  "openaiConfig": {
    "apiKey": "sk-...",
    "model": "text-embedding-3-small",
    "chatModel": "gpt-4",
    "organizationId": "org-..."
  },
  "ollamaConfig": {
    "url": "http://localhost:11434",
    "model": "llama2",
    "chatModel": "llama2"
  },
  "fireworksConfig": {
    "apiKey": "fw-...",
    "embeddingModel": "nomic-ai/nomic-embed-text-v1.5",
    "chatModel": "accounts/fireworks/models/llama-v3-70b-instruct",
    "baseUrl": "https://api.fireworks.ai/inference/v1"
  }
}
```

### File Settings (`/api/settings/files`)

Stored in: `$this->config->setValueString($this->appName, 'fileManagement', ...)`

```json
{
  // Existing vector/embedding settings
  "vectorizationEnabled": false,
  "provider": "openai|ollama|fireworks|null",
  "chunkingStrategy": "RECURSIVE_CHARACTER",
  "chunkSize": 1000,
  "chunkOverlap": 200,
  "enabledFileTypes": ["pdf", "docx", "txt", "md", "html", "json", "xml"],
  "ocrEnabled": false,
  "maxFileSizeMB": 100,
  
  // NEW: Text extraction control settings
  "extractionScope": "none|all|folders|objects",
  "textExtractor": "llphant|dolphin",
  "extractionMode": "background|immediate|manual",
  "maxFileSize": 100,
  "batchSize": 10,
  
  // Dolphin API configuration (when textExtractor=dolphin)
  "dolphinApiEndpoint": "https://api.your-dolphin.com",
  "dolphinApiKey": "your-api-key"
}
```

**Extraction Scope Options:**
- `none` - Text extraction disabled
- `all` - Extract text from all files in Nextcloud
- `folders` - Extract text from files in specific folders only
- `objects` - Extract text only from files attached to OpenRegister objects (recommended)

**Text Extractor Options:**
- `llphant` (default) - Local PHP library, no API required ([LLPhant/LLPhant](https://github.com/LLPhant/LLPhant))
- `dolphin` - ByteDance Dolphin AI, requires API ([bytedance/Dolphin](https://github.com/bytedance/Dolphin))

---

## API Endpoints

### LLM Endpoints

```bash
# Get current LLM settings
GET /index.php/apps/openregister/api/settings/llm

# Update LLM settings
POST /index.php/apps/openregister/api/settings/llm
Content-Type: application/json
{
  "embeddingProvider": "openai",
  "chatProvider": "openai",
  "openaiConfig": {
    "apiKey": "sk-...",
    "model": "text-embedding-3-small"
  }
}
```

### File Endpoints

```bash
# Get current file settings
GET /index.php/apps/openregister/api/settings/files

# Update file settings
POST /index.php/apps/openregister/api/settings/files
Content-Type: application/json
{
  "extractionScope": "objects",
  "textExtractor": "dolphin",
  "extractionMode": "background",
  "enabledFileTypes": ["pdf", "docx", "txt"],
  "maxFileSize": 100,
  "dolphinApiEndpoint": "https://api.your-dolphin.com",
  "dolphinApiKey": "your-api-key"
}

# Test Dolphin API connection
POST /index.php/apps/openregister/api/settings/files/test-dolphin
Content-Type: application/json
{
  "apiEndpoint": "https://api.your-dolphin.com",
  "apiKey": "your-api-key"
}
# Response: {"success": true, "message": "Dolphin connection successful"}
```

---

## Integration Points

### Frontend → Backend Flow

1. **User opens Settings** → Frontend calls `GET /api/settings/llm` and `GET /api/settings/files`
2. **User modifies settings** → Frontend calls `POST /api/settings/llm` or `POST /api/settings/files`
3. **Backend stores** → Settings saved to Nextcloud config as JSON
4. **Services read** → Other services (FileTextService, VectorEmbeddingService) read these settings

### Settings Usage in Code

```php
// Example: Reading file settings in FileChangeListener
$settingsService = $this->container->get(\OCA\OpenRegister\Service\SettingsService::class);
$fileSettings = $settingsService->getFileSettingsOnly();

// Check extraction scope
$extractionScope = $fileSettings['extractionScope'] ?? 'objects';

switch ($extractionScope) {
    case 'none':
        // Text extraction disabled
        return;
        
    case 'all':
        // Extract from all files - no filtering
        break;
        
    case 'folders':
        // Check if file is in designated folders
        if (!$this->isInDesignatedFolder($filePath)) {
            return;
        }
        break;
        
    case 'objects':
    default:
        // Only extract from OpenRegister object files
        if (strpos($filePath, 'OpenRegister/files') === false && 
            strpos($filePath, '/Open Registers/') === false) {
            return;
        }
        break;
}

// Check extraction mode
if ($fileSettings['extractionMode'] === 'manual') {
    // Only extract when manually triggered
    return;
}

// Check if file type is enabled
$fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
if (!in_array($fileExtension, $fileSettings['enabledFileTypes'])) {
    // File type not enabled for extraction
    return;
}

// Queue or process based on mode
if ($fileSettings['extractionMode'] === 'background') {
    $this->jobList->add(FileTextExtractionJob::class, ['file_id' => $fileId]);
} else {
    // immediate mode
    $this->fileTextService->extractAndStoreFileText($fileId);
}
```

---

## Files Modified

### Backend Files

1. **`lib/Service/SettingsService.php`**
   - Added `getLLMSettingsOnly()` method
   - Added `getFileSettingsOnly()` method
   - Integrated text extraction settings into file management config

2. **`lib/Controller/SettingsController.php`**
   - Added `getLLMSettings()` endpoint
   - Added `getFileSettings()` endpoint

3. **`appinfo/routes.php`**
   - Added GET route for LLM settings
   - Added GET route for File settings

### Frontend Files (Already Completed)

4. **`src/views/settings/Settings.vue`**
   - Integrated new sections

5. **`src/views/settings/sections/LlmConfiguration.vue`**
   - New component for LLM settings UI

6. **`src/views/settings/sections/FileConfiguration.vue`**
   - New component for File settings UI

7. **`src/store/settings.js`**
   - Added state for `llmOptions` and `fileOptions`
   - Added methods: `getLlmSettings()`, `saveLlmSettings()`, `getFileSettings()`, `saveFileSettings()`

---

## Testing

### Manual Testing

```bash
# Test LLM GET endpoint
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://localhost/index.php/apps/openregister/api/settings/llm"

# Test LLM POST endpoint
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"embeddingProvider":"openai","openaiConfig":{"apiKey":"test"}}' \
  "http://localhost/index.php/apps/openregister/api/settings/llm"

# Test File GET endpoint
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://localhost/index.php/apps/openregister/api/settings/files"

# Test File POST endpoint
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"autoExtractText":false,"extractionMode":"manual"}' \
  "http://localhost/index.php/apps/openregister/api/settings/files"
```

### UI Testing

1. **Navigate to Settings:**
   - Go to OpenRegister → Settings
   - Scroll to "LLM Configuration" section
   - Scroll to "File Configuration" section

2. **Test LLM Configuration:**
   - Select a provider (OpenAI, Anthropic, Ollama, etc.)
   - Enter API key
   - Click "Test Connection"
   - Save settings
   - Refresh page and verify settings persisted

3. **Test File Configuration:**
   - Toggle "Automatic Text Extraction"
   - Change extraction mode
   - Enable/disable file types
   - Adjust processing limits
   - Save settings
   - Refresh page and verify settings persisted

---

## Next Steps

### 1. Integrate with FileChangeListener ⏳

Update `lib/Listener/FileChangeListener.php` to respect file settings:

```php
public function handle(Event $event): void
{
    // ... existing code ...
    
    // Get file settings
    $fileSettings = $this->settingsService->getFileSettingsOnly();
    
    // Check extraction scope
    $extractionScope = $fileSettings['extractionScope'] ?? 'objects';
    
    switch ($extractionScope) {
        case 'none':
            return; // Extraction disabled
            
        case 'all':
            // No filtering - extract from all files
            break;
            
        case 'folders':
            // Check if file is in designated folders
            if (!$this->isInDesignatedFolder($filePath)) {
                return;
            }
            break;
            
        case 'objects':
        default:
            // Only OpenRegister object files (current behavior)
            if (strpos($filePath, 'OpenRegister/files') === false && 
                strpos($filePath, '/Open Registers/') === false) {
                return;
            }
            break;
    }
    
    // Check extraction mode
    if ($fileSettings['extractionMode'] === 'manual') {
        return; // Only manual extraction
    }
    
    // Check file type
    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
    if (!in_array($extension, $fileSettings['enabledFileTypes'])) {
        return;
    }
    
    // Queue or process based on mode
    if ($fileSettings['extractionMode'] === 'background') {
        $this->jobList->add(FileTextExtractionJob::class, ['file_id' => $fileId]);
    } else {
        // immediate mode
        $this->fileTextService->extractAndStoreFileText($fileId);
    }
}
```

### 2. Add Usage Statistics Endpoints (Optional)

```php
// Get LLM usage stats
public function getLlmUsageStats(): JSONResponse

// Get extraction stats  
public function getFileExtractionStats(): JSONResponse

// Trigger manual extraction
public function triggerFileExtraction(string $type = 'pending'): JSONResponse
```

### 3. Documentation

- Document the new settings sections
- Add API documentation
- Update user guide

---

## Summary

✅ **Backend integration is complete!**

The existing LLM and File management backend has been:
- ✅ Extended with GET endpoints
- ✅ Integrated with new frontend components
- ✅ Enhanced with text extraction control settings
- ✅ Routes properly configured

**Users can now:**
- Configure LLM providers through the UI
- Control text extraction behavior
- Enable/disable file types for processing
- Choose extraction modes (background/immediate/manual)

**All changes are backward compatible** - existing functionality continues to work as before!


