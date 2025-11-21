---
title: LLPhant Refactoring Plan
sidebar_position: 80
---

# Refactoring Plan: Use LLPhant FileDataReader & DocumentSplitter

## Current Problems

1. **No chunks being created** - Text is extracted and stored, but not chunked into database records
2. **Manual PDF/DOCX extraction** - We're using separate libraries (smalot/pdfparser, phpoffice/phpword)
3. **Chunking happens in SOLR service** - Should happen during extraction
4. **Missing overlap support** - Current chunking doesn't use overlap parameter from settings

## LLPhant Built-in Features

According to [LLPhant documentation](https://github.com/LLPhant/LLPhant?tab=readme-ov-file#read-data):

### FileDataReader
Reads various file formats automatically:
- PDF
- DOCX, PPTX
- TXT, MD, HTML, JSON, XML, CSV
- And more...

```php
use LLPhant\Embeddings\DataReader\FileDataReader;

$reader = new FileDataReader($filePath, Document::class);
$documents = $reader->getDocuments(); // Returns array of Document objects
```

### DocumentSplitter
Splits documents into chunks with overlap support:

```php
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;

// Split with overlap!
$splitDocuments = DocumentSplitter::splitDocuments(
    $documents,
    800,        // maxLength (chunk size)
    "\n",       // separator
    100         // overlap (YES, supported!)
);
```

## Proposed Refactoring

### 1. Remove Manual Document Extraction

**Remove from composer.json:**
```json
"smalot/pdfparser": "^2.9",
"phpoffice/phpword": "^1.2"
```

**Remove from TextExtractionService.php:**
- `extractPdf()` method
- `extractWord()` method
- `extractSpreadsheet()` method
- Import statements for those libraries

### 2. Update TextExtractionService

**New approach:**

```php
use LLPhant\Embeddings\DataReader\FileDataReader;
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;

private function performTextExtraction(int $fileId, array $ncFile): ?string
{
    $mimeType = $ncFile['mimetype'] ?? '';
    
    // Get file from Nextcloud
    $nodes = $this->rootFolder->getById($fileId);
    if (empty($nodes)) {
        throw new Exception("File not found");
    }
    
    $file = $nodes[0];
    if (!$file instanceof \OCP\Files\File) {
        throw new Exception("Node is not a file");
    }
    
    // Images need Dolphin AI (OCR)
    if (strpos($mimeType, 'image/') === 0) {
        $this->logger->info('[TextExtractionService] Image files require Dolphin AI for OCR');
        return null;
    }
    
    try {
        // Use LLPhant FileDataReader
        // Note: FileDataReader expects a file path, so create temp file
        $tempFile = tmpfile();
        $tempPath = stream_get_meta_data($tempFile)['uri'];
        fwrite($tempFile, $file->getContent());
        
        $reader = new FileDataReader($tempPath);
        $documents = $reader->getDocuments();
        
        fclose($tempFile);
        
        if (empty($documents)) {
            return null;
        }
        
        // Combine all documents into single text
        $fullText = '';
        foreach ($documents as $doc) {
            $fullText .= $doc->content . "\n\n";
        }
        
        return trim($fullText);
    } catch (\Exception $e) {
        $this->logger->error('[TextExtractionService] LLPhant extraction failed: ' . $e->getMessage());
        return null;
    }
}
```

### 3. Add Chunking During Extraction

**Update `extractTextFromFile()`:**

```php
public function extractTextFromFile(int $fileId, array $ncFile): array
{
    // Extract text using LLPhant
    $fullText = $this->performTextExtraction($fileId, $ncFile);
    
    if (empty($fullText)) {
        return ['text' => null, 'chunks' => []];
    }
    
    // Get chunk settings from config
    $chunkSize = $this->settingsService->getChunkSize(); // e.g., 1000
    $chunkOverlap = $this->settingsService->getChunkOverlap(); // e.g., 200
    
    // Use LLPhant DocumentSplitter
    $documents = [new Document($fullText)];
    $chunks = DocumentSplitter::splitDocuments(
        $documents,
        $chunkSize,
        "\n",  // separator
        $chunkOverlap
    );
    
    // Convert LLPhant chunks to our format
    $chunkArray = [];
    foreach ($chunks as $chunk) {
        $chunkArray[] = [
            'text' => $chunk->content,
            'start_offset' => 0, // LLPhant doesn't provide this
            'end_offset' => strlen($chunk->content)
        ];
    }
    
    return [
        'text' => $fullText,
        'chunks' => $chunkArray
    ];
}
```

## Benefits

1. **Simplified Code**: One library instead of multiple
2. **Better Chunking**: Overlap support built-in
3. **More Formats**: LLPhant supports more file types
4. **Consistent**: Same chunking logic for all services
5. **Maintained**: LLPhant is actively maintained

## Migration Steps

1. ✅ Install LLPhant (already in composer.json)
2. ⏳ Update TextExtractionService to use FileDataReader
3. ⏳ Update chunking to use DocumentSplitter
4. ⏳ Remove manual extraction libraries
5. ⏳ Test with various file formats
6. ⏳ Update SOLR service to use pre-chunked data

## Related Documentation

- [LLPhant Setup](./llphant-setup.md) - LLPhant installation guide
- [Text Extraction Implementation](../technical/text-extraction-implementation.md) - Current text extraction implementation

