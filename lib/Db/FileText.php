<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * FileText Entity
 * 
 * Represents extracted text content from a file for SOLR indexing and AI processing.
 * 
 * @category Db
 * @package  OCA\OpenRegister\Db
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * 
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getFileSize()
 * @method void setFileSize(int $fileSize)
 * @method string|null getFileChecksum()
 * @method void setFileChecksum(?string $fileChecksum)
 * @method string|null getTextContent()
 * @method void setTextContent(?string $textContent)
 * @method int getTextLength()
 * @method void setTextLength(int $textLength)
 * @method string getExtractionMethod()
 * @method void setExtractionMethod(string $extractionMethod)
 * @method string getExtractionStatus()
 * @method void setExtractionStatus(string $extractionStatus)
 * @method string|null getExtractionError()
 * @method void setExtractionError(?string $extractionError)
 * @method bool getChunked()
 * @method void setChunked(bool $chunked)
 * @method int getChunkCount()
 * @method void setChunkCount(int $chunkCount)
 * @method bool getIndexedInSolr()
 * @method void setIndexedInSolr(bool $indexedInSolr)
 * @method bool getVectorized()
 * @method void setVectorized(bool $vectorized)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 * @method DateTime|null getExtractedAt()
 * @method void setExtractedAt(?DateTime $extractedAt)
 */
class FileText extends Entity implements JsonSerializable
{
    protected ?int $fileId = null;
    protected ?string $filePath = null;
    protected ?string $fileName = null;
    protected ?string $mimeType = null;
    protected ?int $fileSize = null;
    protected ?string $fileChecksum = null;
    protected ?string $textContent = null;
    protected int $textLength = 0;
    protected string $extractionMethod = 'text_extract';
    protected string $extractionStatus = 'pending';
    protected ?string $extractionError = null;
    protected bool $chunked = false;
    protected int $chunkCount = 0;
    protected bool $indexedInSolr = false;
    protected bool $vectorized = false;
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;
    protected ?DateTime $extractedAt = null;

    public function __construct()
    {
        $this->addType('fileId', 'integer');
        $this->addType('filePath', 'string');
        $this->addType('fileName', 'string');
        $this->addType('mimeType', 'string');
        $this->addType('fileSize', 'integer');
        $this->addType('fileChecksum', 'string');
        $this->addType('textContent', 'string');
        $this->addType('textLength', 'integer');
        $this->addType('extractionMethod', 'string');
        $this->addType('extractionStatus', 'string');
        $this->addType('extractionError', 'string');
        $this->addType('chunked', 'boolean');
        $this->addType('chunkCount', 'integer');
        $this->addType('indexedInSolr', 'boolean');
        $this->addType('vectorized', 'boolean');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
        $this->addType('extractedAt', 'datetime');
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'fileId' => $this->fileId,
            'filePath' => $this->filePath,
            'fileName' => $this->fileName,
            'mimeType' => $this->mimeType,
            'fileSize' => $this->fileSize,
            'fileChecksum' => $this->fileChecksum,
            'textLength' => $this->textLength,
            'extractionMethod' => $this->extractionMethod,
            'extractionStatus' => $this->extractionStatus,
            'extractionError' => $this->extractionError,
            'chunked' => $this->chunked,
            'chunkCount' => $this->chunkCount,
            'indexedInSolr' => $this->indexedInSolr,
            'vectorized' => $this->vectorized,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
            'extractedAt' => $this->extractedAt?->format('Y-m-d H:i:s'),
        ];
    }
}

