# File Configuration UI Improvements

## Changes Made

### Problem
The original UI had redundant controls:
- ✅ "Automatic Text Extraction" toggle (enabled/disabled)
- ✅ "Extraction Mode" dropdown (background/immediate/manual)
- ❌ "Extract on Upload" toggle (redundant!)

This was confusing because:
- If "Extraction Mode" is "manual", extraction doesn't happen on upload anyway
- The "Extract on Upload" toggle didn't add meaningful control

### Solution
Replaced the toggle-based approach with a cleaner **scope-based selection**:

#### New UI Structure

**1. Extract Text From** (Dropdown)
- **None (Disabled)** - No text extraction
- **All Files** - Extract from all files in Nextcloud
- **Files in Specific Folders** - Extract only from designated folders
- **Files Attached to Objects** - Extract only from OpenRegister objects (recommended, default)

**2. Extraction Mode** (Dropdown) - Only enabled if scope ≠ "None"
- **Background Job** - Asynchronous processing (recommended)
- **Immediate** - Process during upload (slower but instant)
- **Manual Only** - Only extract when user triggers manually

### Benefits

✅ **Clearer Intent** - User explicitly chooses what files to extract from
✅ **Less Redundancy** - Removed "Extract on Upload" toggle
✅ **More Control** - Four extraction scopes instead of just on/off
✅ **Better UX** - Extraction Mode auto-disables when scope is "None"
✅ **Performance** - Users can easily disable extraction completely

---

## Technical Changes

### Frontend (`src/views/settings/sections/FileConfiguration.vue`)

**Old Structure:**
```javascript
fileSettings: {
  autoExtractText: true,        // boolean
  extractionMode: 'background', // string
  extractOnUpload: true,        // boolean (redundant)
}
```

**New Structure:**
```javascript
fileSettings: {
  extractionScope: 'objects',      // 'none' | 'all' | 'folders' | 'objects'
  textExtractor: 'llphant',        // 'llphant' | 'dolphin'
  extractionMode: 'background',    // 'background' | 'immediate' | 'manual'
  dolphinApiEndpoint: '',          // URL to Dolphin API (when textExtractor=dolphin)
  dolphinApiKey: '',               // API key for Dolphin (when textExtractor=dolphin)
}
```

### Text Extractor Options

**1. LLPhant (Default)** 🐘
- Local PHP library for text extraction
- Based on [LLPhant/LLPhant](https://github.com/LLPhant/LLPhant)
- No external API required
- Good for basic document parsing

**2. Dolphin** 🐬
- ByteDance's advanced document parsing AI
- Based on [bytedance/Dolphin](https://github.com/bytedance/Dolphin)
- Requires API endpoint and key
- Superior performance for complex documents (0.3B parameter model)
- Supports tables, formulas, layouts, and natural reading order

### Backend (`lib/Service/SettingsService.php`)

**Updated Default Configuration:**
```php
[
    'extractionScope' => 'objects',      // none, all, folders, objects
    'textExtractor' => 'llphant',        // llphant, dolphin
    'extractionMode' => 'background',    // background, immediate, manual
    'maxFileSize' => 100,
    'batchSize' => 10,
    'dolphinApiEndpoint' => '',          // Dolphin API URL
    'dolphinApiKey' => '',               // Dolphin API key
]
```

### New API Endpoint

**POST `/api/settings/files/test-dolphin`**

Test Dolphin API connectivity:

```bash
curl -X POST \
  -u 'admin:admin' \
  -H "Content-Type: application/json" \
  -d '{
    "apiEndpoint": "https://api.your-dolphin.com",
    "apiKey": "your-api-key-here"
  }' \
  "http://localhost/index.php/apps/openregister/api/settings/files/test-dolphin"
```

Response:
```json
{
  "success": true,
  "message": "Dolphin connection successful"
}
```

### Store (`src/store/settings.js`)

**Updated State:**
```javascript
fileOptions: {
  extractionScope: 'objects',    // none, all, folders, objects
  extractionMode: 'background',  // background, immediate, manual
  maxFileSize: 100,
  batchSize: 10,
  enabledFileTypes: ['txt', 'pdf', 'docx', ...],
}
```

---

## Implementation Details

### Extraction Scope Logic

```php
// In FileChangeListener.php

$extractionScope = $fileSettings['extractionScope'] ?? 'objects';

switch ($extractionScope) {
    case 'none':
        return; // Extraction disabled - skip all files
        
    case 'all':
        // No filtering - process all files in Nextcloud
        break;
        
    case 'folders':
        // Only process files in designated folders
        // Example: Files in /Documents/Contracts/
        if (!$this->isInDesignatedFolder($filePath)) {
            return;
        }
        break;
        
    case 'objects':
    default:
        // Only OpenRegister object files (current behavior)
        // Paths: /OpenRegister/files/* or /Open Registers/*
        if (strpos($filePath, 'OpenRegister/files') === false && 
            strpos($filePath, '/Open Registers/') === false) {
            return;
        }
        break;
}
```

### UI Behavior

1. **User selects "None (Disabled)"**
   - Extraction Mode dropdown becomes disabled (grayed out)
   - No text extraction occurs
   - Manual extraction buttons still work

2. **User selects "All Files"**
   - Extraction Mode dropdown becomes enabled
   - ALL Nextcloud files trigger extraction
   - ⚠️ Warning: May impact performance with many files

3. **User selects "Files in Specific Folders"**
   - Extraction Mode dropdown becomes enabled
   - Future: Add folder picker UI
   - Only files in selected folders are processed

4. **User selects "Files Attached to Objects" (Default)**
   - Extraction Mode dropdown becomes enabled
   - Only OpenRegister object files are processed
   - ✅ Recommended for performance

---

## Migration from Old Settings

The system handles both old and new format gracefully:

```javascript
// Loading settings
if (settings.extractionScope) {
    // New format
    this.fileSettings.extractionScope = settings.extractionScope
} else if (settings.autoExtractText === false) {
    // Old format: autoExtractText = false
    this.fileSettings.extractionScope = { id: 'none', label: 'None (Disabled)' }
} else {
    // Old format: autoExtractText = true (assume objects)
    this.fileSettings.extractionScope = { id: 'objects', label: 'Files Attached to Objects' }
}
```

---

## Testing

### Manual Testing Steps

1. **Navigate to Settings**
   ```
   OpenRegister → Settings → File Configuration section
   ```

2. **Test Scope Selection**
   - Select "None (Disabled)"
     - ✅ Extraction Mode should be disabled
     - ✅ Upload a file → No extraction should occur
   
   - Select "Files Attached to Objects"
     - ✅ Extraction Mode should be enabled
     - ✅ Upload file to object → Extraction should occur
     - ✅ Upload regular Nextcloud file → No extraction

3. **Test Mode Selection**
   - Set Mode to "Manual Only"
     - ✅ Upload file → No automatic extraction
     - ✅ Click "Extract Pending Files" → Extraction occurs
   
   - Set Mode to "Background Job"
     - ✅ Upload file → Background job queued
     - ✅ Check logs for async processing

4. **Test Persistence**
   - Change settings → Save → Refresh page
   - ✅ Settings should be preserved

### API Testing

```bash
# Get current settings
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://localhost/index.php/apps/openregister/api/settings/files"

# Update to disable extraction
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"extractionScope":"none"}' \
  "http://localhost/index.php/apps/openregister/api/settings/files"

# Update to extract from all files
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"extractionScope":"all","extractionMode":"background"}' \
  "http://localhost/index.php/apps/openregister/api/settings/files"
```

---

## Future Enhancements

### 1. Folder Selection UI (for "folders" scope)

Add a folder picker when "Files in Specific Folders" is selected:

```vue
<div v-if="fileSettings.extractionScope.id === 'folders'" class="setting-item">
  <label>Designated Folders</label>
  <NcSelect v-model="fileSettings.designatedFolders"
    :multiple="true"
    :options="availableFolders"
    placeholder="Select folders...">
  </NcSelect>
</div>
```

### 2. Path Pattern Matching

Allow regex/glob patterns for advanced users:

```
extractionScope: 'pattern'
extractionPattern: '/Documents/**/*.pdf'
```

### 3. Real-time Stats in UI

Show live extraction activity:
```
📊 Active Extractions
├─ Queued: 15 files
├─ Processing: 3 files
├─ Completed today: 142 files
└─ Failed: 2 files (view details)
```

---

## Summary

✅ **Simplified UI** - Removed redundant "Extract on Upload" toggle
✅ **Added Flexibility** - 4 extraction scopes instead of binary on/off
✅ **Better UX** - Clearer user intent and control
✅ **Backward Compatible** - Handles old settings gracefully
✅ **Well Documented** - Clear API and usage examples

**Result**: Users can now precisely control which files have text extracted, improving both usability and performance! 🎉


