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
        
        // Combine all document content
        $extractedText = '';
        foreach ($documents as $document) {
            $extractedText .= $document->content . "\n";
        }
        
        return $extractedText;
        
    } catch (Exception $e) {
        $this->logger->error('[TextExtractionService] LLPhant extraction failed', [
            'fileId' => $fileId,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}
```

### 3. Add Chunking During Extraction

**Create chunks immediately after extraction:**

```php
private function createChunks(FileText $fileText, string $extractedText): void
{
    try {
        // Get chunking settings
        $chunkSize = $this->getChunkSize(); // From settings
        $chunkOverlap = $this->getChunkOverlap(); // From settings
        
        // Create Document for LLPhant
        $document = new Document();
        $document->content = $extractedText;
        
        // Use LLPhant's DocumentSplitter with overlap
        $chunks = DocumentSplitter::splitDocuments(
            [$document],
            $chunkSize,
            "\n",
            $chunkOverlap
        );
        
        // Store chunks in database
        $chunkNumber = 0;
        foreach ($chunks as $chunk) {
            $textChunk = new TextChunk();
            $textChunk->setFileTextId($fileText->getId());
            $textChunk->setChunkNumber($chunkNumber++);
            $textChunk->setContent($chunk->content);
            $textChunk->setTokenCount(strlen($chunk->content)); // or use proper token counter
            $this->textChunkMapper->insert($textChunk);
        }
        
        // Update file_text with chunk count
        $fileText->setChunked(true);
        $fileText->setChunkCount($chunkNumber);
        $this->fileTextMapper->update($fileText);
        
        $this->logger->info('[TextExtractionService] Created chunks', [
            'fileId' => $fileText->getFileId(),
            'chunkCount' => $chunkNumber
        ]);
        
    } catch (Exception $e) {
        $this->logger->error('[TextExtractionService] Chunking failed', [
            'fileId' => $fileText->getFileId(),
            'error' => $e->getMessage()
        ]);
    }
}
```

### 4. Create TextChunk Entity & Mapper

**New table needed:**
```sql
CREATE TABLE oc_openregister_text_chunks (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    file_text_id BIGINT NOT NULL,
    chunk_number INT NOT NULL,
    content MEDIUMTEXT,
    token_count INT,
    embedding_vector JSON,  -- For future vector storage
    created_at DATETIME,
    FOREIGN KEY (file_text_id) REFERENCES oc_openregister_file_texts(id) ON DELETE CASCADE
);
```

## Benefits

✅ **Simpler code** - Use LLPhant's battle-tested readers instead of manual implementation  
✅ **More format support** - LLPhant handles many formats automatically  
✅ **Proper chunking** - Chunks created with overlap during extraction  
✅ **Better performance** - One-pass extraction + chunking  
✅ **Database records** - Chunks stored in database for querying  
✅ **Vector ready** - Chunk table can store embeddings later

## Migration Path

1. Create `text_chunks` table migration
2. Update `TextExtractionService` to use FileDataReader
3. Add chunking logic with DocumentSplitter
4. Remove old PDF/Word extraction methods
5. Update composer.json to remove unused libraries
6. Test with various file formats

## Notes

- LLPhant's FileDataReader works with file paths, not streams
- May need to create temporary files for Nextcloud File objects
- DocumentSplitter supports overlap parameter (our settings already have this!)
- Existing extracted texts can be re-processed to create chunks


