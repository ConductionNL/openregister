# File Processing: Supported Formats and Limits

## Overview

OpenRegister can extract text from a wide variety of file formats, enabling semantic search across your document library. This document details which formats are supported, their limitations, and configuration options.

## Supported File Formats

### 📄 Text Documents

#### Plain Text (.txt)
- **Encoding**: UTF-8, ISO-8859-1, Windows-1252 (auto-detected)
- **Max Size**: 100MB
- **Processing Time**: < 1 second
- **Notes**: Fastest format, directly readable

#### Markdown (.md)
- **Features**: Preserves structure (headers, lists, code blocks)
- **Max Size**: 50MB
- **Processing Time**: < 1 second
- **Notes**: HTML tags are stripped, plain text extracted

#### HTML (.html, .htm)
- **Features**: Extracts visible text, removes scripts/styles
- **Max Size**: 20MB
- **Processing Time**: 1-3 seconds
- **Libraries**: DOMDocument (PHP built-in)
- **Notes**: Handles malformed HTML gracefully

### 📃 PDF Documents (.pdf)

#### Standard PDF
- **Features**: Text extraction from text-based PDFs
- **Max Size**: 100MB
- **Processing Time**: 2-10 seconds
- **Libraries**: 
  - Primary: Smalot PdfParser (PHP)
  - Fallback: pdftotext (system command)
- **Notes**: Best for text-based PDFs

#### Scanned PDF (OCR)
- **Features**: OCR via Tesseract for image-based PDFs
- **Max Size**: 50MB
- **Processing Time**: 10-60 seconds (depends on page count)
- **Requirements**: Tesseract OCR must be installed
- **Languages**: Multi-language support (configure in settings)
- **Notes**: Slower but handles scanned documents

**Installation** (Tesseract):
```bash
# Debian/Ubuntu
sudo apt-get install tesseract-ocr

# Alpine (Docker)
apk add tesseract-ocr

# With additional languages
apt-get install tesseract-ocr-nld tesseract-ocr-deu
```

### 📝 Microsoft Office Documents

#### Word Documents (.docx)
- **Features**: Full text extraction including headers/footers
- **Max Size**: 50MB
- **Processing Time**: 2-5 seconds
- **Libraries**: PhpOffice/PhpWord
- **Notes**: Does not extract embedded images (text only)

#### Excel Spreadsheets (.xlsx)
- **Features**: Extracts cell values from all sheets
- **Max Size**: 30MB
- **Processing Time**: 3-10 seconds
- **Libraries**: PhpOffice/PhpSpreadsheet
- **Notes**: 
  - Includes cell values, not formulas
  - Sheet names included as context
  - Large spreadsheets may be memory-intensive

#### PowerPoint Presentations (.pptx)
- **Features**: Extracts text from slides, notes, and comments
- **Max Size**: 50MB
- **Processing Time**: 2-5 seconds
- **Libraries**: ZipArchive + XML parsing
- **Notes**: 
  - Slide structure preserved (numbered slides)
  - Speaker notes included
  - Does not extract embedded objects

### 🖼️ Image Files (OCR)

#### Supported Formats
- **JPEG/JPG**: Photos, scanned documents
- **PNG**: Screenshots, graphics with text
- **GIF**: Animated or static images
- **BMP**: Bitmap images
- **TIFF**: Multi-page scanned documents

#### OCR Configuration
- **Max Size**: 20MB per image
- **Processing Time**: 5-15 seconds per page
- **Requirements**: Tesseract OCR
- **Languages**: Configurable (default: English)
- **DPI**: Minimum 150 DPI recommended for accuracy

**Best Practices**:
- Use high-resolution scans (300 DPI ideal)
- Ensure text is legible and not skewed
- Black text on white background works best
- Multi-page TIFFs processed page-by-page

### 📊 Data Formats

#### JSON (.json)
- **Features**: Recursive extraction of all string and numeric values
- **Max Size**: 20MB
- **Processing Time**: 1-2 seconds
- **Notes**: 
  - Array values flattened
  - Nested objects fully traversed
  - Keys included for context

**Example**:
```json
{
  "project": "Amsterdam Renovation",
  "budget": 1500000,
  "status": "active"
}
```
**Extracted Text**: "project Amsterdam Renovation budget 1500000 status active"

#### XML (.xml)
- **Features**: Extracts tag names and text content
- **Max Size**: 20MB
- **Processing Time**: 1-3 seconds
- **Notes**:
  - Attributes included
  - Tag structure preserved
  - CDATA sections handled

---

## File Size Limits

### Default Limits

| Format | Default Max Size | Configurable | Reason |
|--------|------------------|--------------|--------|
| Text/Markdown | 100MB | Yes | Memory-efficient |
| HTML | 20MB | Yes | DOM parsing overhead |
| PDF (text) | 100MB | Yes | Direct extraction |
| PDF (OCR) | 50MB | Yes | Processing intensive |
| Office Docs | 30-50MB | Yes | Library limitations |
| Images | 20MB | Yes | OCR memory usage |
| JSON/XML | 20MB | Yes | Parsing complexity |

### Modifying Limits

Edit `lib/Service/SolrFileService.php`:
```php
// File size limits (in bytes)
private const MAX_FILE_SIZE_TEXT = 104857600;  // 100MB
private const MAX_FILE_SIZE_PDF = 104857600;   // 100MB
private const MAX_FILE_SIZE_OFFICE = 52428800; // 50MB
private const MAX_FILE_SIZE_IMAGE = 20971520;  // 20MB
```

**Warning**: Increasing limits may cause:
- Memory exhaustion
- Slow processing
- Timeouts on large files

---

## Document Chunking

### Why Chunking?

Large documents are split into smaller chunks for:
1. **Better Search Relevance**: Find specific sections, not entire documents
2. **Context Windows**: LLMs have token limits (~8k-128k tokens)
3. **Performance**: Smaller vectors = faster search
4. **Precision**: Pinpoint exact location of information

### Chunking Strategies

#### 1. Fixed Size Chunking
**How it works**: Split text into chunks of N characters with M character overlap

**Configuration**:
```php
'chunk_size' => 1000,        // Characters per chunk
'chunk_overlap' => 200,      // Overlap between chunks
```

**Use when**:
- Consistent chunk sizes needed
- Simple, predictable splitting
- No semantic boundaries matter

**Example**:
```
Text: "The quick brown fox jumps over the lazy dog. The dog was sleeping..."
Chunk 1: "The quick brown fox jumps over the lazy dog. The dog..."
Chunk 2: "...lazy dog. The dog was sleeping..."
```
(Overlap ensures no information is lost at boundaries)

#### 2. Recursive Character Chunking
**How it works**: Split at semantic boundaries (paragraphs → sentences → words)

**Configuration**:
```php
'chunk_strategy' => 'recursive',
'chunk_size' => 1000,
'separators' => ["\n\n", "\n", ". ", " "]
```

**Use when**:
- Preserving semantic meaning is important
- Documents have clear structure (paragraphs)
- Better context per chunk needed

**Example**:
```
Text: "Introduction\n\nThis project aims to...\n\nMethodology\n\nWe used..."
Chunk 1: "Introduction\n\nThis project aims to..."
Chunk 2: "Methodology\n\nWe used..."
```
(Splits at paragraph boundaries, preserving context)

### Chunking Configuration

**In File Management Dialog**:
1. Go to Settings → File Management
2. Select **Chunking Strategy**: Fixed Size or Recursive
3. Set **Chunk Size**: 500-2000 characters (default: 1000)
4. Set **Overlap**: 100-300 characters (default: 200)

**Recommendations**:

| Document Type | Strategy | Size | Overlap |
|---------------|----------|------|---------|
| Short documents (< 5 pages) | Fixed | 1500 | 200 |
| Structured (reports, articles) | Recursive | 1000 | 200 |
| Code files | Fixed | 800 | 150 |
| Books / Long-form | Recursive | 1200 | 250 |
| Chat logs | Fixed | 500 | 100 |

---

## Processing Pipeline

### Step-by-Step Flow

```
┌─────────────────────────────────────────────────────────┐
│ 1. File Upload                                          │
│    - User uploads file to Nextcloud                     │
│    - File stored in data directory                      │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ 2. File Type Detection                                  │
│    - Check MIME type                                    │
│    - Validate file extension                            │
│    - Check if format is enabled                         │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ 3. Text Extraction                                      │
│    - Use format-specific extractor                      │
│    - Handle encoding issues                             │
│    - Clean/normalize text                               │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ 4. Document Chunking                                    │
│    - Apply selected strategy                            │
│    - Create overlapping chunks                          │
│    - Preserve metadata                                  │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ 5. Embedding Generation                                 │
│    - Batch chunks (up to 100)                           │
│    - Call OpenAI/Ollama API                             │
│    - Receive vector embeddings                          │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ 6. Storage                                              │
│    - Store chunks in fileCollection (SOLR)              │
│    - Store vectors in oc_openregister_vectors (MySQL)   │
│    - Link chunks to source file                         │
└─────────────────────────────────────────────────────────┘
```

### Processing Time Estimates

| File Type | Size | Extraction | Chunking | Embedding | Total |
|-----------|------|------------|----------|-----------|-------|
| TXT | 1MB | 0.1s | 0.2s | 2s | ~2.3s |
| PDF (text) | 5MB | 3s | 0.5s | 5s | ~8.5s |
| PDF (OCR) | 5MB | 30s | 0.5s | 5s | ~35.5s |
| DOCX | 2MB | 2s | 0.3s | 3s | ~5.3s |
| XLSX | 5MB | 8s | 0.5s | 4s | ~12.5s |
| JPG (OCR) | 3MB | 10s | 0.1s | 1s | ~11.1s |

*Note: Times are approximate and vary based on server specs*

---

## Enabling/Disabling Formats

### Via UI (Recommended)

1. Navigate to **Settings → Administration → OpenRegister**
2. Click **Actions → File Management**
3. Toggle format switches:
   - ✅ PDF Documents
   - ✅ Microsoft Office
   - ✅ Images (OCR)
   - ✅ Text Files
   - ✅ Data Files (JSON/XML)

### Via Configuration File

Edit app configuration (for advanced users):
```php
'file_processing' => [
    'enabled_formats' => [
        'txt' => true,
        'pdf' => true,
        'docx' => true,
        'xlsx' => true,
        'pptx' => true,
        'jpg' => true,  // Requires OCR
        'png' => true,  // Requires OCR
        'json' => true,
        'xml' => true,
    ],
    'ocr_enabled' => true,
    'ocr_languages' => ['eng', 'nld', 'deu'],
]
```

---

## Error Handling

### Common Errors

#### "File too large"
**Cause**: File exceeds format-specific size limit  
**Solution**: 
- Reduce file size
- Split into multiple files
- Increase limit in configuration (if server allows)

#### "Format not supported"
**Cause**: File format is not enabled or recognized  
**Solution**:
- Enable format in File Management dialog
- Convert file to supported format
- Check file extension matches content

#### "Text extraction failed"
**Cause**: Corrupted file or missing dependencies  
**Solution**:
- Verify file is not corrupted
- Check if required libraries are installed (e.g., Tesseract for OCR)
- Try alternative file format

#### "OCR failed"
**Cause**: Tesseract not installed or image quality too low  
**Solution**:
- Install Tesseract: `apt-get install tesseract-ocr`
- Use higher resolution scan (300 DPI minimum)
- Ensure text is legible

#### "Memory exhausted"
**Cause**: File too large for available memory  
**Solution**:
- Increase PHP memory limit in php.ini: `memory_limit = 512M`
- Process smaller files
- Enable chunked processing

---

## Performance Optimization

### Tips for Fast Processing

1. **Batch Processing**: Process multiple files in bulk (Settings → File Management)
2. **Prioritize Formats**: Enable only needed formats to reduce overhead
3. **Optimize Chunk Size**: Larger chunks = fewer API calls but less precision
4. **Use Local Ollama**: Avoid network latency for embeddings
5. **Schedule Off-Hours**: Run bulk vectorization during low-traffic periods

### Resource Requirements

**Minimum**:
- CPU: 2 cores
- RAM: 4GB
- Disk: 10GB for vectors + original files

**Recommended**:
- CPU: 4+ cores
- RAM: 8GB+
- Disk: SSD with 50GB+ space

**For OCR**:
- Additional CPU: +2 cores
- Additional RAM: +2GB

---

## Dependencies

### PHP Libraries (Installed via Composer)

```json
{
  "smalot/pdfparser": "^2.0",           // PDF text extraction
  "phpoffice/phpword": "^1.0",          // Word document processing
  "phpoffice/phpspreadsheet": "^1.0"    // Excel processing
}
```

### System Commands

| Command | Purpose | Installation |
|---------|---------|--------------|
| `pdftotext` | PDF extraction fallback | `apt-get install poppler-utils` |
| `tesseract` | OCR for images/scanned PDFs | `apt-get install tesseract-ocr` |

### Checking Dependencies

```bash
# Check if pdftotext is available
which pdftotext

# Check Tesseract version
tesseract --version

# Test OCR
tesseract test-image.png output
```

---

## Troubleshooting

### Debugging File Processing

**Enable Debug Logging**:
1. Go to Settings → File Management
2. Enable "Detailed Logging"
3. Check logs at: `data/nextcloud.log` or via Docker: `docker logs nextcloud-container`

**Look for**:
```
[SolrFileService] Processing file: document.pdf
[SolrFileService] Extraction method: pdfParser
[SolrFileService] Extracted text length: 45678
[SolrFileService] Created 12 chunks
[SolrFileService] Generated 12 embeddings
```

### Performance Issues

**If processing is slow**:
1. Check file size and format
2. Monitor memory usage: `docker stats`
3. Reduce chunk size to decrease API calls
4. Use faster embedding model (ada-002 vs 3-large)
5. Consider Ollama for local processing

### Quality Issues

**If text extraction is poor**:
1. Verify source file quality
2. For scanned documents, ensure 300+ DPI
3. Use native PDF over scanned when possible
4. Test with different OCR languages
5. Check for corrupted files

---

## Best Practices

### For Administrators

✅ **Do**:
- Enable only needed file formats
- Set reasonable size limits
- Monitor storage growth
- Schedule bulk processing off-hours
- Keep dependencies updated

❌ **Don't**:
- Process unnecessary file types
- Set extremely high size limits
- Skip dependency checks
- Run bulk processing during peak hours

### For Users

✅ **Do**:
- Use text-based PDFs when possible
- Provide high-quality scans (300 DPI)
- Use consistent file naming
- Organize files in logical folders

❌ **Don't**:
- Upload password-protected files (won't extract)
- Use low-resolution scans
- Mix unrelated content in single file
- Rely on OCR for perfect accuracy

---

## Future Enhancements

Planned features:
- [ ] Support for more formats (RTF, ODT, Pages)
- [ ] Improved OCR with multiple engines
- [ ] Real-time processing on upload
- [ ] Incremental re-processing for updated files
- [ ] Format-specific metadata extraction
- [ ] Custom format handlers via plugins

---

## FAQ

**Q: Can I process password-protected files?**  
A: No, password-protected files cannot be extracted. Remove password first.

**Q: How accurate is OCR?**  
A: 90-98% accuracy for good quality scans (300 DPI, clear text). Lower for poor scans.

**Q: Can I process files retroactively?**  
A: Yes! Use bulk vectorization in File Management dialog.

**Q: Do I need to re-process files after changing chunk strategy?**  
A: Yes, existing chunks won't update automatically. Re-vectorize to apply new strategy.

**Q: What happens to old chunks when I re-process?**  
A: Old chunks are replaced with new ones. Vector database is updated accordingly.

**Q: Can I see extracted text before vectorization?**  
A: Check logs with debug mode enabled. Text is logged before chunking.

---

*Last updated: October 13, 2025*  
*OpenRegister Version: 2.4.0+*

