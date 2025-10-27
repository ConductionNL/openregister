# File Type Compatibility Guide

## Overview

OpenRegister supports two text extraction engines with different file type capabilities:

1. **LLPhant** 🐘 - Local PHP library ([LLPhant/LLPhant](https://github.com/LLPhant/LLPhant))
2. **Dolphin** 🐬 - ByteDance AI ([bytedance/Dolphin](https://github.com/bytedance/Dolphin))

## Compatibility Matrix

| File Type | Extension | LLPhant Support | Dolphin Support | Recommended For |
|-----------|-----------|-----------------|-----------------|-----------------|
| **Text Files** | `.txt` | ✓ Native | ✓ Excellent | LLPhant |
| **Markdown** | `.md` | ✓ Native | ✓ Excellent | LLPhant |
| **HTML** | `.html` | ✓ Native | ✓ Excellent | LLPhant |
| **JSON** | `.json` | ✓ Native | ✓ Excellent | LLPhant |
| **XML** | `.xml` | ✓ Native | ✓ Excellent | LLPhant |
| **CSV** | `.csv` | ✓ Native | ✓ Excellent | LLPhant |
| **PDF** | `.pdf` | ○ Library | ✓ Excellent | Dolphin (complex), LLPhant (simple) |
| **Word (Modern)** | `.docx` | ○ Library | ✓ Excellent | Dolphin |
| **Word (Legacy)** | `.doc` | ○ Library | ✓ Excellent | Dolphin |
| **Excel (Modern)** | `.xlsx` | ○ Library | ✓ Excellent | Dolphin |
| **Excel (Legacy)** | `.xls` | ○ Library | ✓ Excellent | Dolphin |
| **PowerPoint** | `.pptx` | ⚠️ Limited | ✓ Excellent | Dolphin |
| **OpenDocument** | `.odt` | ⚠️ Limited | ✓ Excellent | Dolphin |
| **Rich Text** | `.rtf` | ⚠️ Limited | ✓ Excellent | Dolphin |
| **JPEG Images** | `.jpg`, `.jpeg` | ✗ None | ✓ OCR | Dolphin (OCR) |
| **PNG Images** | `.png` | ✗ None | ✓ OCR | Dolphin (OCR) |
| **GIF Images** | `.gif` | ✗ None | ✓ OCR | Dolphin (OCR) |
| **WebP Images** | `.webp` | ✗ None | ✓ OCR | Dolphin (OCR) |

### Legend

- ✓ **Native**: Built-in PHP support, works perfectly out of the box
- ○ **Library**: Requires PHP libraries (automatically included), good quality
- ⚠️ **Limited**: Basic extraction only, may miss content
- ✗ **None**: No LLPhant support, requires Dolphin
- ✓ **Excellent**: AI-powered, handles complex layouts, tables, formulas
- ✓ **OCR**: Optical Character Recognition for scanned documents and images

---

## LLPhant Support Details

### ✓ Native Support (Recommended)

These formats use native PHP functionality and work excellently with LLPhant:

#### Text Files (`.txt`)
- **Quality**: Perfect
- **Speed**: Very fast
- **Requirements**: None
- **Best For**: Plain text documents, logs, source code

#### Markdown (`.md`)
- **Quality**: Perfect
- **Speed**: Very fast
- **Requirements**: None
- **Best For**: Documentation, README files, notes

#### HTML (`.html`)
- **Quality**: Excellent
- **Speed**: Fast
- **Requirements**: Native PHP DOM
- **Best For**: Web pages, email templates
- **Notes**: Strips HTML tags, preserves text content

#### JSON (`.json`)
- **Quality**: Perfect
- **Speed**: Very fast
- **Requirements**: Native PHP JSON
- **Best For**: Configuration files, API responses, data exports

#### XML (`.xml`)
- **Quality**: Excellent
- **Speed**: Fast
- **Requirements**: Native PHP SimpleXML
- **Best For**: Structured data, SOAP responses, RSS feeds

#### CSV (`.csv`)
- **Quality**: Excellent
- **Speed**: Fast
- **Requirements**: Native PHP
- **Best For**: Spreadsheet data, database exports, tabular data

---

### ○ Library Support (Good Quality)

These formats require additional PHP libraries but work well:

#### PDF (`.pdf`)
- **Quality**: Good (simple PDFs), Fair (complex PDFs)
- **Speed**: Medium
- **Requirements**: `smalot/pdfparser` PHP library
- **Best For**: Simple text-based PDFs, documents without complex layouts
- **Limitations**:
  - May struggle with multi-column layouts
  - Image-based PDFs need OCR (not included)
  - Complex tables may lose structure
  - Formulas and equations not preserved
- **Recommendation**: Use **Dolphin** for complex PDFs with tables/formulas

#### Word Documents (`.docx`)
- **Quality**: Good
- **Speed**: Medium
- **Requirements**: `phpoffice/phpword` library
- **Best For**: Simple Word documents, text-heavy content
- **Limitations**:
  - Basic text extraction only
  - Complex formatting lost
  - Tables may lose structure
  - Images and diagrams skipped
- **Recommendation**: Use **Dolphin** for documents with complex formatting

#### Excel Spreadsheets (`.xlsx`, `.xls`)
- **Quality**: Fair
- **Speed**: Medium
- **Requirements**: `phpoffice/phpspreadsheet` library
- **Best For**: Simple spreadsheets with text data
- **Limitations**:
  - Formulas extracted as results, not formulas themselves
  - Charts and graphs ignored
  - Pivot tables not processed
  - Cell formatting lost
- **Recommendation**: Use **Dolphin** for spreadsheets with formulas/pivot tables

---

### ⚠️ Limited Support (Use Dolphin Instead)

These formats have poor extraction quality with LLPhant:

#### PowerPoint (`.pptx`)
- **Quality**: Poor
- **Speed**: Slow
- **Requirements**: `phpoffice/phppresentation` library
- **Limitations**:
  - Only extracts slide notes and text boxes
  - Visual layouts completely lost
  - Speaker notes may be missed
  - Animations and transitions ignored
- **Recommendation**: **Always use Dolphin** for presentations

#### OpenDocument Text (`.odt`)
- **Quality**: Fair
- **Speed**: Medium
- **Requirements**: Custom PHP parsing
- **Limitations**:
  - Basic text extraction only
  - Complex formatting lost
- **Recommendation**: Use **Dolphin** for best results

#### Rich Text Format (`.rtf`)
- **Quality**: Fair
- **Speed**: Medium
- **Requirements**: Custom PHP parsing
- **Limitations**:
  - May miss special characters
  - Formatting lost
- **Recommendation**: Use **Dolphin** for best results

---

### ✗ No Support (Dolphin Required)

These formats have **no LLPhant support** and require Dolphin's OCR capabilities:

#### Image Formats (`.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`)
- **Quality with LLPhant**: None
- **Quality with Dolphin**: Excellent (OCR)
- **Requirements**: Dolphin AI with OCR
- **Use Cases**:
  - Scanned documents
  - Screenshots of text
  - Photos of documents
  - Infographics with text
  - Receipts and invoices
- **Dolphin Features**:
  - Advanced OCR (Optical Character Recognition)
  - Multi-language support
  - Table extraction from images
  - Layout understanding
  - Formula recognition in images
- **Best Practices**:
  - Use high-resolution images (300+ DPI recommended)
  - Ensure good lighting and contrast
  - Avoid heavy compression
  - Straighten skewed documents
- **Recommendation**: **Always use Dolphin** for image text extraction

---

## Dolphin Support Details

### ✓ Excellent Support (All File Types)

Dolphin uses advanced AI parsing and handles **all file types** with superior quality:

#### Key Advantages

1. **Complex Layouts**: Understands multi-column documents, text flow
2. **Tables**: Perfect table extraction with structure preserved
3. **Formulas**: Extracts mathematical formulas in readable format
4. **Natural Reading Order**: Content extracted in logical reading order
5. **Visual Understanding**: AI can "see" the document layout
6. **OCR Capabilities**: Extracts text from images and scanned documents
   - JPEG/JPG images
   - PNG images
   - GIF images  
   - WebP images
   - Scanned PDFs (converts to images internally)
   - Multi-language OCR support
   - Handwriting recognition (limited)

#### Performance Metrics (OmniDocBench)

| Metric | Score |
|--------|-------|
| Overall | 83.21 |
| Text Accuracy | 0.092 (lower is better) |
| Formula Recognition | 80.78 |
| Table Extraction (TEDS) | 78.06 |
| Reading Order | 0.080 (lower is better) |

#### Best Use Cases for Dolphin

- ✅ Scientific papers with formulas
- ✅ Financial reports with complex tables
- ✅ Multi-column layouts (newspapers, magazines)
- ✅ Presentations with text in images
- ✅ Scanned documents with OCR needs
- ✅ Image files with text (screenshots, photos, scans)
- ✅ Receipts and invoices (photos)
- ✅ Infographics with embedded text
- ✅ Historical documents (scanned archives)
- ✅ Any complex document where layout matters

#### OCR-Specific Use Cases

**When to Enable Image Formats:**

1. **Document Digitization**
   - Scanning paper archives
   - Converting physical documents to searchable text
   - Historical document preservation

2. **Receipt/Invoice Processing**
   - Photo receipts from mobile devices
   - Scanned invoices
   - Bank statements (scanned)

3. **Screenshot Analysis**
   - Extract text from application screenshots
   - Process error messages in images
   - Documentation from visual content

4. **Social Media/Web Images**
   - Extract text from infographics
   - Process memes with text
   - Analyze image-based announcements

5. **Quality Requirements**:
   - Minimum 150 DPI (300+ recommended)
   - Clear, high-contrast images
   - Minimal blur or distortion
   - Properly oriented (not rotated)

---

## Choosing the Right Extractor

### Use LLPhant When:

✅ You have **privacy-sensitive data** (everything stays local)  
✅ Working with **simple text formats** (TXT, MD, HTML, JSON, XML, CSV)  
✅ You want **zero API costs**  
✅ You need **offline processing**  
✅ Your PDFs are **simple text-only documents**  
✅ You don't need table/formula extraction  

**Estimated Performance**: ~5-10 files/second on average hardware

---

### Use Dolphin When:

✅ You have **complex documents** with tables, formulas, or multi-column layouts  
✅ You need **superior accuracy** (83.21 overall score)  
✅ Working with **scientific or technical documents**  
✅ You need **formula extraction** (equations, chemical formulas)  
✅ Your PDFs have **complex layouts**  
✅ You're processing **presentations** (PPTX)  
✅ You need **OCR capabilities** for scanned documents  

**Estimated Performance**: ~1-2 files/second (API-dependent)

---

## Configuration Guide

### Default Configuration (LLPhant)

```json
{
  "extractionScope": "objects",
  "textExtractor": "llphant",
  "enabledFileTypes": [
    "txt", "md", "html", "json", "xml", "csv",  // Native support
    "pdf", "docx", "xlsx"                        // Library support
  ]
}
```

**Best For**: General use, privacy-conscious deployments, simple documents

---

### High-Accuracy Configuration (Dolphin)

```json
{
  "extractionScope": "objects",
  "textExtractor": "dolphin",
  "dolphinApiEndpoint": "https://api.your-dolphin.com",
  "dolphinApiKey": "sk-xxxxx",
  "enabledFileTypes": [
    "txt", "md", "html", "json", "xml", "csv",
    "pdf", "docx", "doc", "xlsx", "xls", "pptx",
    "odt", "rtf"
  ]
}
```

**Best For**: Research institutions, complex documents, maximum accuracy

---

### Hybrid Configuration (Recommended)

Use **LLPhant for simple formats**, **Dolphin for complex ones**:

```json
{
  "extractionScope": "objects",
  "textExtractor": "llphant",  // Default
  "enabledFileTypes": [
    "txt", "md", "html", "json", "xml", "csv",  // LLPhant handles these
    "pdf", "docx", "xlsx"                        // Basic LLPhant support
  ]
}
```

Then, for specific registers with complex documents, switch to Dolphin:

```json
{
  "extractionScope": "objects",
  "textExtractor": "dolphin",  // Override for complex docs
  "enabledFileTypes": [
    "pdf", "docx", "xlsx", "pptx"  // Complex formats
  ]
}
```

*(Future feature: per-register extractor selection)*

---

## PHP Library Requirements

### For LLPhant

These libraries are automatically included via Composer:

```json
{
  "require": {
    "smalot/pdfparser": "^2.0",           // PDF extraction
    "phpoffice/phpword": "^1.0",          // DOCX extraction
    "phpoffice/phpspreadsheet": "^1.28",  // XLSX extraction
    "phpoffice/phppresentation": "^1.0"   // PPTX extraction (limited)
  }
}
```

### Installation

```bash
composer require smalot/pdfparser phpoffice/phpword phpoffice/phpspreadsheet
```

Already included in OpenRegister's `composer.json`.

---

## UI Indicators

### Compatibility Notes

When you select **LLPhant**, you'll see:

```
ℹ️ LLPhant compatibility:
   ✓ Native: TXT, MD, HTML, JSON, XML, CSV
   ○ Library: PDF, DOCX, XLSX (requires PhpOffice, PdfParser)
   ⚠️ Limited: PPTX, ODT, RTF (consider using Dolphin)
```

When you select **Dolphin**, you'll see:

```
✓ Dolphin AI: All file types fully supported with advanced 
  parsing for tables, formulas, and complex layouts.
```

### File Type Indicators

Each file type shows its support level:

- **✓** = Native PHP support (excellent with LLPhant)
- **⚠️** = Limited support (use Dolphin instead)

---

## Testing Recommendations

### Test Suite for LLPhant

Upload these test files to verify extraction quality:

1. **✓ Simple TXT** - Should be perfect
2. **✓ Markdown with tables** - Should preserve structure
3. **✓ HTML with formatting** - Should extract clean text
4. **○ Simple PDF** - Should extract most text
5. **⚠️ Complex PDF with tables** - May lose structure → Test with Dolphin

### Test Suite for Dolphin

Upload these test files to verify AI parsing:

1. **Complex PDF with tables** - Should preserve table structure
2. **Scientific paper with formulas** - Should extract LaTeX/formulas
3. **Multi-column document** - Should follow reading order
4. **Presentation with text boxes** - Should extract all text

---

## Troubleshooting

### LLPhant Issues

**Problem**: PDF extraction returns gibberish or missing text

**Solutions**:
1. PDF may be image-based → Enable OCR or use Dolphin
2. PDF has complex layout → Switch to Dolphin
3. PDF is encrypted → Decrypt first

**Problem**: DOCX tables lose structure

**Solution**: Use Dolphin for documents with complex tables

**Problem**: XLSX formulas not extracted

**Expected**: LLPhant extracts formula *results*, not formulas  
**Solution**: Use Dolphin to extract actual formulas

---

### Dolphin Issues

**Problem**: API returns 401 Unauthorized

**Solutions**:
1. Check API key is correct
2. Verify endpoint URL
3. Ensure account has credits

**Problem**: Extraction is slow

**Expected**: Dolphin processes ~1-2 files/second (AI processing)  
**Solution**: Use background mode for async processing

---

## Cost Comparison

### LLPhant

- **Cost**: $0 (free, local processing)
- **Infrastructure**: Your server resources
- **Privacy**: 100% local, no data leaves your server

### Dolphin

- **Cost**: API pricing (varies by provider)
- **Infrastructure**: Cloud API
- **Privacy**: Data sent to external API

---

## Future Enhancements

### Planned Features

1. **Automatic Extractor Selection**
   - Analyze document complexity
   - Auto-route to best extractor

2. **Fallback Chain**
   - Try Dolphin first
   - Fallback to LLPhant if API unavailable

3. **Per-Register Configuration**
   - Different extractors for different registers
   - Example: Public docs → LLPhant, Research → Dolphin

4. **Quality Metrics**
   - Track extraction success rates
   - Display statistics per extractor
   - Help users optimize configuration

---

## References

- **LLPhant**: https://github.com/LLPhant/LLPhant
- **Dolphin**: https://github.com/bytedance/Dolphin
- **Dolphin Paper**: "Dolphin: Document Image Parsing via Heterogeneous Anchor Prompting" (ACL 2025)
- **PhpOffice**: https://github.com/PHPOffice
- **PDF Parser**: https://github.com/smalot/pdfparser

---

## Summary

✅ **LLPhant**: Perfect for simple text formats, privacy-friendly, zero cost  
✅ **Dolphin**: Superior for complex documents, AI-powered, best accuracy  
✅ **Default**: LLPhant for most use cases  
✅ **Upgrade**: Switch to Dolphin when you need advanced parsing  

**Choose wisely based on your document complexity and privacy requirements!** 🎯

