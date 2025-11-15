<?php

declare(strict_types=1);

/**
 * Class Chunk
 *
 * Represents a single chunk of text produced from any source.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git-id>
 * @link     https://www.openregister.nl
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class Chunk
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string getSourceType()
 * @method void setSourceType(string $sourceType)
 * @method int getSourceId()
 * @method void setSourceId(int $sourceId)
 * @method string getTextContent()
 * @method void setTextContent(string $textContent)
 * @method int getStartOffset()
 * @method void setStartOffset(int $startOffset)
 * @method int getEndOffset()
 * @method void setEndOffset(int $endOffset)
 * @method int getChunkIndex()
 * @method void setChunkIndex(int $chunkIndex)
 * @method array|null getPositionReference()
 * @method void setPositionReference(?array $positionReference)
 * @method string|null getLanguage()
 * @method void setLanguage(?string $language)
 * @method string|null getLanguageLevel()
 * @method void setLanguageLevel(?string $languageLevel)
 * @method float|null getLanguageConfidence()
 * @method void setLanguageConfidence(?float $languageConfidence)
 * @method string|null getDetectionMethod()
 * @method void setDetectionMethod(?string $detectionMethod)
 * @method bool getIndexed()
 * @method void setIndexed(bool $indexed)
 * @method bool getVectorized()
 * @method void setVectorized(bool $vectorized)
 * @method string|null getEmbeddingProvider()
 * @method void setEmbeddingProvider(?string $embeddingProvider)
 * @method int getOverlapSize()
 * @method void setOverlapSize(int $overlapSize)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class Chunk extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
    protected ?string $sourceType = null;
    protected ?int $sourceId = null;
    protected ?string $textContent = null;
    protected int $startOffset = 0;
    protected int $endOffset = 0;
    protected int $chunkIndex = 0;
    protected ?array $positionReference = null;
    protected ?string $language = null;
    protected ?string $languageLevel = null;
    protected ?float $languageConfidence = null;
    protected ?string $detectionMethod = null;
    protected bool $indexed = false;
    protected bool $vectorized = false;
    protected ?string $embeddingProvider = null;
    protected int $overlapSize = 0;
    protected ?string $owner = null;
    protected ?string $organisation = null;
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('sourceType', 'string');
        $this->addType('sourceId', 'integer');
        $this->addType('textContent', 'string');
        $this->addType('startOffset', 'integer');
        $this->addType('endOffset', 'integer');
        $this->addType('chunkIndex', 'integer');
        $this->addType('positionReference', 'json');
        $this->addType('language', 'string');
        $this->addType('languageLevel', 'string');
        $this->addType('languageConfidence', 'float');
        $this->addType('detectionMethod', 'string');
        $this->addType('indexed', 'boolean');
        $this->addType('vectorized', 'boolean');
        $this->addType('embeddingProvider', 'string');
        $this->addType('overlapSize', 'integer');
        $this->addType('owner', 'string');
        $this->addType('organisation', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    /**
     * JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'sourceType' => $this->sourceType,
            'sourceId' => $this->sourceId,
            'chunkIndex' => $this->chunkIndex,
            'startOffset' => $this->startOffset,
            'endOffset' => $this->endOffset,
            'language' => $this->language,
            'languageLevel' => $this->languageLevel,
            'languageConfidence' => $this->languageConfidence,
            'indexed' => $this->indexed,
            'vectorized' => $this->vectorized,
            'embeddingProvider' => $this->embeddingProvider,
            'overlapSize' => $this->overlapSize,
            'owner' => $this->owner,
            'organisation' => $this->organisation,
            'createdAt' => $this->createdAt?->format(DateTime::ATOM),
            'updatedAt' => $this->updatedAt?->format(DateTime::ATOM),
            'positionReference' => $this->positionReference,
        ];
    }
}


