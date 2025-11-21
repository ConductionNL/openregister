<?php
/**
 * OpenRegister FileText Entity
 *
 * Represents extracted text content from a file for SOLR indexing and AI processing.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

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
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
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
 * @method string|null getChunksJson()
 * @method void setChunksJson(?string $chunksJson)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 * @method DateTime|null getExtractedAt()
 * @method void setExtractedAt(?DateTime $extractedAt)
 */
class FileText extends Entity implements JsonSerializable
{

    /**
     * UUID.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * File ID.
     *
     * @var integer|null
     */
    protected ?int $fileId = null;

    /**
     * File path.
     *
     * @var string|null
     */
    protected ?string $filePath = null;

    /**
     * File name.
     *
     * @var string|null
     */
    protected ?string $fileName = null;

    /**
     * MIME type.
     *
     * @var string|null
     */
    protected ?string $mimeType = null;

    /**
     * File size.
     *
     * @var integer|null
     */
    protected ?int $fileSize = null;

    /**
     * File checksum.
     *
     * @var string|null
     */
    protected ?string $fileChecksum = null;

    /**
     * Text content.
     *
     * @var string|null
     */
    protected ?string $textContent = null;

    /**
     * Text length.
     *
     * @var integer
     */
    protected int $textLength = 0;

    /**
     * Extraction method.
     *
     * @var string
     */
    protected string $extractionMethod = 'text_extract';

    /**
     * Extraction status.
     *
     * @var string
     */
    protected string $extractionStatus = 'pending';

    /**
     * Extraction error.
     *
     * @var string|null
     */
    protected ?string $extractionError = null;

    /**
     * Chunked flag.
     *
     * @var boolean
     */
    protected bool $chunked = false;

    /**
     * Chunk count.
     *
     * @var integer
     */
    protected int $chunkCount = 0;

    /**
     * Chunks JSON.
     *
     * @var string|null
     */
    protected ?string $chunksJson = null;

    /**
     * Indexed in Solr flag.
     *
     * @var boolean
     */
    protected bool $indexedInSolr = false;

    /**
     * Vectorized flag.
     *
     * @var boolean
     */
    protected bool $vectorized = false;

    /**
     * Created at timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Updated at timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updatedAt = null;

    /**
     * Extracted at timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $extractedAt = null;


    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
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
        $this->addType('chunksJson', 'string');
        $this->addType('indexedInSolr', 'boolean');
        $this->addType('vectorized', 'boolean');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
        $this->addType('extractedAt', 'datetime');

    }//end __construct()


    /**
     * Validate UUID format
     *
     * @param string $uuid The UUID to validate
     *
     * @return bool True if UUID format is valid
     */
    public static function isValidUuid(string $uuid): bool
    {
        try {
            Uuid::fromString($uuid);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }

    }//end isValidUuid()


    /**
     * JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'fileId'           => $this->fileId,
            'filePath'         => $this->filePath,
            'fileName'         => $this->fileName,
            'mimeType'         => $this->mimeType,
            'fileSize'         => $this->fileSize,
            'fileChecksum'     => $this->fileChecksum,
            'textLength'       => $this->textLength,
            'extractionMethod' => $this->extractionMethod,
            'extractionStatus' => $this->extractionStatus,
            'extractionError'  => $this->extractionError,
            'chunked'          => $this->chunked,
            'chunkCount'       => $this->chunkCount,
            'indexedInSolr'    => $this->indexedInSolr,
            'vectorized'       => $this->vectorized,
            'createdAt'        => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt'        => $this->updatedAt?->format('Y-m-d H:i:s'),
            'extractedAt'      => $this->extractedAt?->format('Y-m-d H:i:s'),
        ];

    }//end jsonSerialize()


}//end class
