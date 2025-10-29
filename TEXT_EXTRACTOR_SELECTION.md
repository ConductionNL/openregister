# Text Extractor Selection Feature

## Overview

Users can now choose between two text extraction engines for processing documents in OpenRegister:

1. **LLPhant** 🐘 - Local PHP library (default)
2. **Dolphin** 🐬 - ByteDance's advanced document parsing AI

## User Interface

### Text Extractor Dropdown

Located in **Settings → File Configuration → Text Extraction**

```
┌─────────────────────────────────────┐
│ Text Extractor                      │
├─────────────────────────────────────┤
│ 🐘 LLPhant (selected)               │
│    Local PHP library for text       │
│    extraction (default, no API)     │
│                                     │
│ 🐬 Dolphin                          │
│    ByteDance Dolphin AI for         │
│    advanced document parsing        │
│    (requires API)                   │
└─────────────────────────────────────┘
```

### Conditional API Configuration

When **Dolphin** is selected, additional fields appear:

```
┌─────────────────────────────────────┐
│ Dolphin API Configuration           │
├─────────────────────────────────────┤
│ Dolphin API Endpoint                │
│ ┌─────────────────────────────────┐ │
│ │ https://api.your-dolphin.com    │ │
│ └─────────────────────────────────┘ │
│ URL to your Dolphin API instance    │
│                                     │
│ Dolphin API Key                     │
│ ┌─────────────────────────────────┐ │
│ │ ••••••••••••••••••••            │ │
│ └─────────────────────────────────┘ │
│ Your Dolphin API authentication key │
│                                     │
│ [Test Connection]                   │
└─────────────────────────────────────┘
```

## Text Extractors Comparison

> **📚 Detailed File Type Compatibility**: See [FILE_TYPE_COMPATIBILITY.md](FILE_TYPE_COMPATIBILITY.md) for comprehensive information about which file types work best with each extractor.

### LLPhant (Default)

**GitHub:** [LLPhant/LLPhant](https://github.com/LLPhant/LLPhant)

**Pros:**
- ✅ No external dependencies
- ✅ No API costs
- ✅ Works offline
- ✅ Simple setup
- ✅ Privacy-friendly (all local)
- ✅ Native support for: TXT, MD, HTML, JSON, XML, CSV
- ✅ Library support for: PDF, DOCX, XLSX (via PhpOffice, PdfParser)

**Cons:**
- ⚠️ Basic extraction capabilities for complex documents
- ⚠️ May struggle with multi-column layouts
- ⚠️ Limited table/formula support
- ⚠️ Poor PowerPoint extraction

**Best For:**
- Simple text documents (TXT, MD, HTML)
- Structured data (JSON, XML, CSV)
- Simple PDFs without complex layouts
- Privacy-sensitive data
- Offline environments
- Cost-conscious deployments

---

### Dolphin

**GitHub:** [bytedance/Dolphin](https://github.com/bytedance/Dolphin)  
**Paper:** "Dolphin: Document Image Parsing via Heterogeneous Anchor Prompting" (ACL 2025)

**Pros:**
- ✅ Advanced AI parsing (0.3B parameters)
- ✅ Superior table extraction (TEDS: 78.06)
- ✅ Formula recognition (CDM: 80.78)
- ✅ Natural reading order
- ✅ Handles complex layouts
- ✅ Lightweight architecture

**Cons:**
- ⚠️ Requires external API
- ⚠️ API costs may apply
- ⚠️ Network dependency
- ⚠️ Requires API key management

**Best For:**
- Complex documents (PDFs with tables/formulas)
- High-accuracy requirements
- Scientific/technical documents
- Multi-page document parsing

**Performance (OmniDocBench):**
| Metric          | Score     |
|----------------|-----------|
| Overall        | 83.21     |
| Text (Edit↓)   | 0.092     |
| Formula (CDM↑) | 80.78     |
| Table (TEDS↑)  | 78.06     |
| Read Order↓    | 0.080     |

## Technical Implementation

### Data Structure

```javascript
// Frontend (Vue)
fileSettings: {
  extractionScope: 'objects',      // none, all, folders, objects
  textExtractor: 'llphant',        // llphant, dolphin
  extractionMode: 'background',    // background, immediate, manual
  dolphinApiEndpoint: '',
  dolphinApiKey: '',
}
```

```php
// Backend (PHP)
[
    'extractionScope' => 'objects',
    'textExtractor' => 'llphant',
    'extractionMode' => 'background',
    'dolphinApiEndpoint' => '',
    'dolphinApiKey' => '',
]
```

### API Endpoints

#### Test Dolphin Connection

**POST** `/api/settings/files/test-dolphin`

```bash
curl -X POST \
  -u 'admin:admin' \
  -H "Content-Type: application/json" \
  -d '{
    "apiEndpoint": "https://api.your-dolphin.com",
    "apiKey": "your-api-key"
  }' \
  "http://localhost/index.php/apps/openregister/api/settings/files/test-dolphin"
```

**Success Response:**
```json
{
  "success": true,
  "message": "Dolphin connection successful"
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Connection failed: Could not resolve host"
}
```

#### Save Settings

**POST** `/api/settings/files`

```json
{
  "extractionScope": "objects",
  "textExtractor": "dolphin",
  "extractionMode": "background",
  "dolphinApiEndpoint": "https://api.your-dolphin.com",
  "dolphinApiKey": "your-key-here"
}
```

## Usage Flow

### Setup Flow (Dolphin)

1. User navigates to **Settings → File Configuration**
2. User selects **"Dolphin"** from Text Extractor dropdown
3. API configuration fields appear
4. User enters:
   - API Endpoint: `https://api.your-dolphin.com`
   - API Key: `sk-xxxxxxxxxxxxx`
5. User clicks **"Test Connection"**
6. System validates connection
7. If successful: ✅ Green checkmark appears
8. Settings auto-save

### Extraction Flow

#### With LLPhant

```
File Upload
    ↓
File Listener (FileChangeListener.php)
    ↓
Check Settings → textExtractor = 'llphant'
    ↓
Queue Background Job (FileTextExtractionJob.php)
    ↓
LLPhant Library
    ↓
Extract Text Locally
    ↓
Store in FileText entity
```

#### With Dolphin

```
File Upload
    ↓
File Listener (FileChangeListener.php)
    ↓
Check Settings → textExtractor = 'dolphin'
    ↓
Queue Background Job (FileTextExtractionJob.php)
    ↓
API Call to Dolphin
    ↓
Receive Parsed JSON/Markdown
    ↓
Store in FileText entity
```

## Configuration Examples

### Example 1: Privacy-First Setup (LLPhant)

```json
{
  "extractionScope": "objects",
  "textExtractor": "llphant",
  "extractionMode": "background"
}
```

**Use Case:** Government/healthcare handling sensitive documents locally

---

### Example 2: High-Accuracy Setup (Dolphin)

```json
{
  "extractionScope": "objects",
  "textExtractor": "dolphin",
  "extractionMode": "background",
  "dolphinApiEndpoint": "https://dolphin.your-org.com",
  "dolphinApiKey": "sk-proj-xxxxx"
}
```

**Use Case:** Research institution processing complex scientific papers

---

### Example 3: Hybrid Setup

Configure different registers with different extractors:

1. **"Public Documents"** register → LLPhant (simple PDFs)
2. **"Research Papers"** register → Dolphin (complex layouts)

*(Future feature: per-register extractor selection)*

## Implementation Notes

### FileTextService.php Integration

```php
public function extractAndStoreFileText(int $fileId): array
{
    // Get file settings
    $settings = $this->settingsService->getFileSettingsOnly();
    $extractor = $settings['textExtractor'] ?? 'llphant';
    
    switch ($extractor) {
        case 'dolphin':
            return $this->extractWithDolphin($fileId, $settings);
        case 'llphant':
        default:
            return $this->extractWithLLPhant($fileId);
    }
}
```

### Dolphin Integration

```php
private function extractWithDolphin(int $fileId, array $settings): array
{
    $endpoint = $settings['dolphinApiEndpoint'];
    $apiKey = $settings['dolphinApiKey'];
    
    // Send file to Dolphin API
    $ch = curl_init($endpoint . '/parse');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: multipart/form-data',
        ],
        CURLOPT_POSTFIELDS => [
            'file' => new \CURLFile($localPath),
            'mode' => 'page', // page-level parsing
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        // Extract text from Dolphin's JSON response
        return [
            'success' => true,
            'text' => $result['markdown'] ?? $result['text'],
            'metadata' => $result['metadata'] ?? [],
        ];
    }
    
    throw new \Exception("Dolphin API error: HTTP $httpCode");
}
```

## Testing

### Manual Testing

1. **Test LLPhant (Default)**
   ```bash
   # Upload a simple text file
   # Verify extraction works without any API config
   ```

2. **Test Dolphin Connection**
   ```bash
   # Set invalid endpoint → Should show error
   # Set valid endpoint + invalid key → Should show auth error
   # Set valid endpoint + valid key → Should show success ✅
   ```

3. **Test Dolphin Extraction**
   ```bash
   # Upload complex PDF with tables
   # Verify superior extraction quality
   ```

### Automated Tests

```php
public function testTextExtractorSelection()
{
    // Test default is LLPhant
    $settings = $this->service->getFileSettingsOnly();
    $this->assertEquals('llphant', $settings['textExtractor']);
    
    // Test switching to Dolphin
    $this->service->updateFileSettingsOnly([
        'textExtractor' => 'dolphin',
        'dolphinApiEndpoint' => 'https://test.dolphin.com',
        'dolphinApiKey' => 'test-key',
    ]);
    
    $updated = $this->service->getFileSettingsOnly();
    $this->assertEquals('dolphin', $updated['textExtractor']);
}
```

## Future Enhancements

### 1. More Extractors

- Tesseract OCR
- AWS Textract
- Google Document AI
- Microsoft Azure Form Recognizer

### 2. Per-Register Configuration

Allow different registers to use different extractors:

```json
{
  "registers": {
    "public-docs": { "extractor": "llphant" },
    "research": { "extractor": "dolphin" }
  }
}
```

### 3. Fallback Chain

```
Try Dolphin → If fails → Fallback to LLPhant
```

### 4. Quality Metrics

Track and display extraction quality per extractor:

```
📊 Extraction Statistics
├─ LLPhant: 450 files, 95% success
└─ Dolphin: 120 files, 99% success
```

## Troubleshooting

### Dolphin Connection Fails

**Symptom:** "Connection failed: Could not resolve host"

**Solutions:**
1. Check endpoint URL format
2. Verify network connectivity from server
3. Check firewall rules
4. Ensure API key is valid

### Dolphin Returns 401

**Symptom:** "Dolphin API returned HTTP 401"

**Solutions:**
1. Verify API key is correct
2. Check key hasn't expired
3. Confirm account has credits/quota

### Extraction Quality Poor

**With LLPhant:**
- Consider switching to Dolphin for complex documents
- Check if OCR is needed (scanned documents)

**With Dolphin:**
- Check API quota/limits
- Verify endpoint is correct version
- Review Dolphin logs for errors

## References

- **LLPhant GitHub:** https://github.com/LLPhant/LLPhant
- **Dolphin GitHub:** https://github.com/bytedance/Dolphin
- **Dolphin Paper:** "Dolphin: Document Image Parsing via Heterogeneous Anchor Prompting" (ACL 2025)
- **Implementation PR:** [Link to PR]

## Summary

✅ **Flexibility:** Choose between local and cloud extraction  
✅ **Privacy:** LLPhant for sensitive data  
✅ **Quality:** Dolphin for complex documents  
✅ **Easy Setup:** Test connection before using  
✅ **Transparent:** Clear indicators of which extractor is active  

**Result:** Users get the best of both worlds - simple local extraction by default, with the option to upgrade to state-of-the-art AI parsing when needed! 🎉

