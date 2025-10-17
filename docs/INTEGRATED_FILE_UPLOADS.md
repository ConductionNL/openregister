# Integrated File Uploads in OpenRegister

**Version:** 1.0  
**Date:** October 2025  
**Status:** ✅ Complete

## Overview

OpenRegister now supports **integrated file uploads** directly within object POST/PUT operations, providing a unified approach to handling structured data (objects) and unstructured data (files) together. This aligns with the OpenRegister vision of seamless object-file integration.

## Key Features

- **Three Upload Methods:** Multipart/form-data, base64-encoded, and URL references
- **Automatic Conversion:** Files are automatically stored in Nextcloud and linked to objects
- **Schema Validation:** File types, sizes, and formats are validated against schema configuration
- **Backward Compatible:** Existing separate file endpoints continue to work
- **Flexible Response:** File properties return as full file objects with metadata when GET'ing objects

## Use Cases

### Before (Separate Operations)
```bash
# 1. Create object
curl -X POST /api/registers/documents/schemas/document/objects \
  -d '{"title": "My Document"}'
# Response: {"uuid": "abc-123", ...}

# 2. Upload file separately
curl -X POST /api/objects/documents/document/abc-123/files \
  -F "file=@document.pdf"
```

### After (Integrated)
```bash
# Single operation - create object with file
curl -X POST /api/registers/documents/schemas/document/objects \
  -F "title=My Document" \
  -F "attachment=@document.pdf"
```

## Upload Methods

### 1. Multipart/Form-Data Upload

**Use Case:** Uploading files from web forms or file inputs

**Example:**
```http
POST /index.php/apps/openregister/api/registers/documents/schemas/document/objects
Content-Type: multipart/form-data; boundary=----WebKitFormBoundary

------WebKitFormBoundary
Content-Disposition: form-data; name="title"

Annual Report 2024
------WebKitFormBoundary
Content-Disposition: form-data; name="attachment"; filename="report.pdf"
Content-Type: application/pdf

[PDF binary data]
------WebKitFormBoundary
Content-Disposition: form-data; name="thumbnail"; filename="cover.jpg"
Content-Type: image/jpeg

[JPEG binary data]
------WebKitFormBoundary--
```

**Response:**
```json
{
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "title": "Annual Report 2024",
  "attachment": {
    "id": "12345",
    "title": "report.pdf",
    "path": "/OpenRegister/registers/1/objects/550e8400-e29b-41d4-a716-446655440000/attachment.pdf",
    "accessUrl": "https://nextcloud.local/f/12345",
    "downloadUrl": "https://nextcloud.local/s/xYz789/download",
    "type": "application/pdf",
    "size": 1024000,
    "extension": "pdf",
    "modified": "2024-10-17T12:00:00Z"
  },
  "thumbnail": {
    "id": "12346",
    "title": "cover.jpg",
    "path": "/OpenRegister/registers/1/objects/550e8400-e29b-41d4-a716-446655440000/thumbnail.jpg",
    "accessUrl": "https://nextcloud.local/f/12346",
    "downloadUrl": "https://nextcloud.local/s/aBC456/download",
    "type": "image/jpeg",
    "size": 51200,
    "extension": "jpg",
    "modified": "2024-10-17T12:00:00Z"
  }
}
```

**JavaScript Example:**
```javascript
const formData = new FormData();
formData.append('title', 'Annual Report 2024');
formData.append('attachment', fileInput.files[0]);
formData.append('thumbnail', thumbnailInput.files[0]);

fetch('/index.php/apps/openregister/api/registers/documents/schemas/document/objects', {
  method: 'POST',
  body: formData,
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN'
  }
})
.then(response => response.json())
.then(data => console.log('Created:', data));
```

### 2. Base64-Encoded Files

**Use Case:** Embedding files in JSON payloads, API integrations

**Data URI Format:**
```http
POST /index.php/apps/openregister/api/registers/documents/schemas/document/objects
Content-Type: application/json

{
  "title": "Screenshot",
  "image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA..."
}
```

**Plain Base64 Format (MIME type detected):**
```json
{
  "title": "Document",
  "attachment": "JVBERi0xLjQKJeLjz9MKMyAwIG9iago8PC9MZW5ndGggMj..."
}
```

**Response:**
```json
{
  "uuid": "660f9411-f39c-52e5-b827-557766551111",
  "title": "Screenshot",
  "image": {
    "id": "12347",
    "title": "image.png",
    "path": "/OpenRegister/registers/1/objects/660f9411-f39c-52e5-b827-557766551111/image.png",
    "type": "image/png",
    "size": 15360,
    "extension": "png"
  }
}
```

**JavaScript Example:**
```javascript
// Convert file to base64
function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

// Upload with base64
const base64Image = await fileToBase64(imageFile);

fetch('/index.php/apps/openregister/api/registers/documents/schemas/document/objects', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer YOUR_TOKEN'
  },
  body: JSON.stringify({
    title: 'Screenshot',
    image: base64Image
  })
})
.then(response => response.json())
.then(data => console.log('Created:', data));
```

### 3. URL References

**Use Case:** Referencing remote files, importing from external sources

**Example:**
```http
POST /index.php/apps/openregister/api/registers/documents/schemas/document/objects
Content-Type: application/json

{
  "title": "External Document",
  "attachment": "https://example.com/files/document.pdf",
  "logo": "https://cdn.example.com/images/logo.png"
}
```

**Response:**
```json
{
  "uuid": "770g0522-g40d-63f6-c938-668877662222",
  "title": "External Document",
  "attachment": {
    "id": "12348",
    "title": "document.pdf",
    "path": "/OpenRegister/registers/1/objects/770g0522-g40d-63f6-c938-668877662222/attachment.pdf",
    "type": "application/pdf",
    "size": 2048000,
    "extension": "pdf"
  },
  "logo": {
    "id": "12349",
    "title": "logo.png",
    "path": "/OpenRegister/registers/1/objects/770g0522-g40d-63f6-c938-668877662222/logo.png",
    "type": "image/png",
    "size": 20480,
    "extension": "png"
  }
}
```

### 4. Mixed Upload Methods

**Example: Combining all three methods**
```http
POST /index.php/apps/openregister/api/registers/documents/schemas/document/objects
Content-Type: multipart/form-data; boundary=----WebKitFormBoundary

------WebKitFormBoundary
Content-Disposition: form-data; name="title"

Complete Package
------WebKitFormBoundary
Content-Disposition: form-data; name="mainDocument"; filename="contract.pdf"
Content-Type: application/pdf

[PDF binary data via multipart]
------WebKitFormBoundary
Content-Disposition: form-data; name="signature"

data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA...
------WebKitFormBoundary
Content-Disposition: form-data; name="reference"

https://example.com/terms.pdf
------WebKitFormBoundary--
```

## Schema Configuration

### Defining File Properties

```json
{
  "properties": {
    "title": {
      "type": "string",
      "title": "Document Title"
    },
    "attachment": {
      "type": "file",
      "title": "Main Attachment",
      "allowedTypes": ["application/pdf", "application/msword"],
      "maxSize": 10485760,
      "description": "Upload PDF or Word document (max 10MB)"
    },
    "images": {
      "type": "array",
      "title": "Gallery Images",
      "items": {
        "type": "file",
        "allowedTypes": ["image/jpeg", "image/png", "image/webp"],
        "maxSize": 5242880
      }
    }
  }
}
```

### File Configuration Options

| Option | Type | Description | Example |
|--------|------|-------------|---------|
| `type` | string | Must be `"file"` for file properties | `"file"` |
| `allowedTypes` | array | Allowed MIME types | `["application/pdf", "image/jpeg"]` |
| `maxSize` | integer | Maximum file size in bytes | `10485760` (10 MB) |
| `format` | string | File format hints | `"image"`, `"document"`, `"data-url"` |

## Array of Files

**Schema:**
```json
{
  "properties": {
    "attachments": {
      "type": "array",
      "items": {
        "type": "file"
      }
    }
  }
}
```

**Upload:**
```json
{
  "title": "Multi-File Document",
  "attachments": [
    "data:application/pdf;base64,JVBERi0xLjQKJeL...",
    "https://example.com/file2.pdf",
    "data:image/png;base64,iVBORw0KGgo..."
  ]
}
```

**Response:**
```json
{
  "uuid": "880h1633-h51e-74g7-d049-779988773333",
  "title": "Multi-File Document",
  "attachments": [
    {
      "id": "12350",
      "title": "attachments_0.pdf",
      "type": "application/pdf"
    },
    {
      "id": "12351",
      "title": "attachments_1.pdf",
      "type": "application/pdf"
    },
    {
      "id": "12352",
      "title": "attachments_2.png",
      "type": "image/png"
    }
  ]
}
```

## Update Operations

File properties work the same way with PUT/PATCH operations:

```http
PUT /index.php/apps/openregister/api/registers/documents/schemas/document/objects/abc-123
Content-Type: multipart/form-data

title=Updated Document
attachment=@new-version.pdf
```

**Note:** Updating a file property replaces the previous file.

## Error Handling

### Invalid MIME Type
```json
{
  "error": "File at attachment has invalid type 'application/zip'. Allowed types: application/pdf, application/msword"
}
```

### File Too Large
```json
{
  "error": "File at attachment exceeds maximum size (10485760 bytes). File size: 15728640 bytes"
}
```

### Upload Error
```json
{
  "error": "Failed to read uploaded file for field 'attachment'"
}
```

### URL Download Failure
```json
{
  "error": "Unable to fetch file from URL: https://example.com/missing.pdf"
}
```

## Backward Compatibility

✅ **Existing file endpoints remain unchanged:**

- `POST /api/objects/{register}/{schema}/{id}/files`
- `GET /api/objects/{register}/{schema}/{id}/files`
- `DELETE /api/objects/{register}/{schema}/{id}/files/{fileId}`

Both approaches work and can be used interchangeably.

## Performance Considerations & Method Comparison

### 🏆 Multipart/Form-Data (AANBEVOLEN)

**Waarom dit de voorkeur heeft:**
- ✅ **Meest efficiënt:** Geen encoding overhead, bestanden worden direct overgedragen
- ✅ **Volledige metadata:** Originele bestandsnaam en MIME type worden behouden
- ✅ **Geen giswerk:** Extensie en bestandsnaam zijn exact zoals geüpload
- ✅ **Beste bestandskwaliteit:** Geen conversie- of inferentiefouten
- ✅ **Lage memory footprint:** Kan direct van disk naar disk streamen
- ✅ **Snelste methode:** Directe transfer zonder tussenliggende conversies

**Gebruik voor:** 
- Alle bestanden > 1 MB
- Situaties waar bestandsnaam belangrijk is
- Wanneer je controle wilt over MIME types
- Productie-omgevingen

---

### ⚠️ Base64-Encoding (GEBRUIK MET VOORZICHTIGHEID)

**Waarom dit beperkt bruikbaar is:**
- ❌ **+33% groter:** Base64 encoding vergroot bestanden met ongeveer een derde
- ❌ **Verlies van metadata:** Originele bestandsnaam gaat verloren
- ❌ **Giswerk vereist:** Systeem moet extensie afleiden van MIME type
  - `image/jpeg` → gok: `.jpg` (maar kan ook `.jpeg` zijn)
  - Plain base64 zonder MIME → moet content sniffing doen
- ❌ **Generieke namen:** Bestanden krijgen automatische namen zoals `image.png`, `attachment.pdf`
- ❌ **Hogere memory gebruik:** Hele bestand moet in geheugen gedecodeerd worden
- ❌ **Langzamer:** Extra CPU voor encoding/decoding

**Nadelen voor object kwaliteit:**
```json
// Je stuurt:
{
  "attachment": "JVBERi0xLjQKJeLjz9MK..."  ← Geen naam!
}

// Je krijgt terug:
{
  "attachment": {
    "title": "attachment.pdf",  ← Generieke naam!
    "extension": "pdf"          ← Geraden van MIME type
  }
}
```

**Wanneer wél te gebruiken:**
- Kleine bestanden (< 100 KB)
- API integraties waar multipart niet mogelijk is
- JSON-only workflows
- Embedded images in JSON

---

### 🐌 URL References (TRAAGSTE METHODE)

**Waarom dit traag is:**
- ❌ **Backend moet downloaden:** Server moet externe URL ophalen
- ❌ **Netwerk latency:** Afhankelijk van externe server response tijd
- ❌ **Dubbele transfer:** Bestand gaat: externe server → OpenRegister → Nextcloud
- ❌ **Timeout risico:** Externe servers kunnen langzaam zijn of niet reageren
- ❌ **Extra foutbronnen:** Externe URLs kunnen offline zijn, 404's geven, etc.

**Performantie impact:**
```
Multipart upload:  50ms (directe upload)
URL reference:     500-5000ms (afhankelijk van externe server)
                   ↑
                   10-100x langzamer!
```

**Wanneer wél te gebruiken:**
- Importeren van externe content
- Migratie scenarios
- Trusted externe bronnen (CDN's)

---

## Best Practices

1. **✅ ALTIJD gebruik Multipart voor user uploads**
   - Gebruikers verwachten dat bestandsnamen behouden blijven
   - Voorkomt verwarring over "waarom heet mijn bestand attachment.pdf?"

2. **⚠️ Base64 alleen voor API's**
   - Wanneer je API client geen multipart ondersteunt
   - Documenteer dat bestandsnamen verloren gaan
   - Gebruik altijd data URI format met MIME type

3. **🐌 URLs alleen voor trusted sources**
   - Gebruik timeout limits (max 30 seconden)
   - Valideer content-length headers vooraf
   - Implementeer retry logic

4. **📝 Documenteer de keuze**
   - Als je base64 of URL gebruikt, leg uit waarom
   - Maak gebruikers bewust van de trade-offs

5. **🧪 Test performance**
   - Meet upload tijden in productie omgeving
   - Monitor failure rates bij URL downloads

## Security

- ✅ File types are validated against schema configuration
- ✅ File sizes are enforced
- ✅ External URLs are validated before download
- ✅ Filenames are sanitized
- ✅ RBAC permissions apply to file operations
- ✅ MIME types are detected from content (not just extension)

## Complete Example: Document Management

**Create document with attachments:**
```bash
curl -X POST 'https://nextcloud.local/index.php/apps/openregister/api/registers/documents/schemas/contract/objects' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -F 'contractNumber=2024-001' \
  -F 'title=Software License Agreement' \
  -F 'mainContract=@contract.pdf' \
  -F 'signature=@signature.png' \
  -F 'annexes[]=@annex-a.pdf' \
  -F 'annexes[]=@annex-b.pdf'
```

**Get document with files:**
```bash
curl -X GET 'https://nextcloud.local/index.php/apps/openregister/api/registers/documents/schemas/contract/objects/abc-123' \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

**Response includes full file objects:**
```json
{
  "uuid": "abc-123",
  "contractNumber": "2024-001",
  "title": "Software License Agreement",
  "mainContract": {
    "id": "12345",
    "path": "/OpenRegister/registers/1/objects/abc-123/mainContract.pdf",
    "accessUrl": "https://nextcloud.local/f/12345",
    "downloadUrl": "https://nextcloud.local/s/xYz789/download",
    "type": "application/pdf",
    "size": 524288,
    "modified": "2024-10-17T14:30:00Z"
  },
  "signature": {
    "id": "12346",
    "path": "/OpenRegister/registers/1/objects/abc-123/signature.png",
    "type": "image/png",
    "size": 15360
  },
  "annexes": [
    {
      "id": "12347",
      "path": "/OpenRegister/registers/1/objects/abc-123/annexes_0.pdf",
      "type": "application/pdf"
    },
    {
      "id": "12348",
      "path": "/OpenRegister/registers/1/objects/abc-123/annexes_1.pdf",
      "type": "application/pdf"
    }
  ]
}
```

## Testing

Run the integrated file upload tests:

```bash
cd openregister
./vendor/bin/phpunit tests/Unit/Service/ObjectHandlers/IntegratedFileUploadTest.php
```

## Implementation Details

- **SaveObject Handler:** Processes uploaded files and converts to Nextcloud files
- **File Detection:** Automatic detection of file properties based on schema
- **Base64 Decoding:** Supports both data URIs and plain base64
- **URL Downloads:** HTTP/HTTPS with timeout and redirect support
- **File Storage:** Uses existing FileService for consistent file management
- **RenderObject Handler:** Hydrates file IDs back to full file objects on GET

## Migration Guide

### Updating Existing Code

**Before:**
```javascript
// Create object
const objectResponse = await fetch('/api/registers/docs/schemas/doc/objects', {
  method: 'POST',
  body: JSON.stringify({ title: 'My Doc' })
});
const object = await objectResponse.json();

// Upload file separately
const formData = new FormData();
formData.append('file', fileInput.files[0]);
await fetch(`/api/objects/docs/doc/${object.uuid}/files`, {
  method: 'POST',
  body: formData
});
```

**After:**
```javascript
// Single request with file
const formData = new FormData();
formData.append('title', 'My Doc');
formData.append('attachment', fileInput.files[0]);

const object = await fetch('/api/registers/docs/schemas/doc/objects', {
  method: 'POST',
  body: formData
}).then(r => r.json());

// File is already attached, no second request needed
console.log(object.attachment.downloadUrl);
```

## Support

For questions or issues:
- GitHub: https://github.com/OpenCatalogi/OpenRegister/issues
- Documentation: https://openregister.app/docs
- Email: dev@conduction.nl

## Changelog

**Version 1.0 (October 2025)**
- ✅ Initial release
- ✅ Multipart/form-data support
- ✅ Base64 encoding support
- ✅ URL reference support
- ✅ Mixed upload methods
- ✅ Array of files support
- ✅ Schema validation
- ✅ Comprehensive tests
- ✅ Full documentation

