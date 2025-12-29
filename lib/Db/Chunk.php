<?php

/**
 * Class Chunk
 *
 * Represents a single chunk of text produced from any source.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.openregister.nl
 */

declare(strict_types=1);

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
 * @method string|null getChecksum()
 * @method void setChecksum(?string $checksum)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class Chunk extends Entity implements JsonSerializable
{

    /**
     * UUID.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Source type.
     *
     * @var string|null
     */
    protected ?string $sourceType = null;

    /**
     * Source ID.
     *
     * @var integer|null
     */
    protected ?int $sourceId = null;

    /**
     * Text content.
     *
     * @var string|null
     *
     * @psalm-suppress PossiblyUnusedProperty
     */
    protected ?string $textContent = null;

    /**
     * Start offset.
     *
     * @var integer
     */
    protected int $startOffset = 0;

    /**
     * End offset.
     *
     * @var integer
     */
    protected int $endOffset = 0;

    /**
     * Chunk index.
     *
     * @var integer
     */
    protected int $chunkIndex = 0;

    /**
     * Position reference.
     *
     * @var array|null
     */
    protected ?array $positionReference = null;

    /**
     * Language.
     *
     * @var string|null
     */
    protected ?string $language = null;

    /**
     * Language level.
     *
     * @var string|null
     */
    protected ?string $languageLevel = null;

    /**
     * Language confidence.
     *
     * @var float|null
     */
    protected ?float $languageConfidence = null;

    /**
     * Detection method.
     *
     * @var string|null
     *
     * @psalm-suppress PossiblyUnusedProperty
     */
    protected ?string $detectionMethod = null;

    /**
     * Indexed flag.
     *
     * @var boolean
     */
    protected bool $indexed = false;

    /**
     * Vectorized flag.
     *
     * @var boolean
     */
    protected bool $vectorized = false;

    /**
     * Embedding provider.
     *
     * @var string|null
     */
    protected ?string $embeddingProvider = null;

    /**
     * Overlap size.
     *
     * @var integer
     */
    protected int $overlapSize = 0;

    /**
     * Owner.
     *
     * @var string|null
     */
    protected ?string $owner = null;

    /**
     * Organisation.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Checksum.
     *
     * @var string|null
     */
    protected ?string $checksum = null;

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
        $this->addType('checksum', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return (array|null|scalar)[]
     *
     * @psalm-return array{id: int, uuid: null|string, sourceType: null|string, sourceId: int|null, chunkIndex: int, startOffset: int, endOffset: int, language: null|string, languageLevel: null|string, languageConfidence: float|null, indexed: bool, vectorized: bool, embeddingProvider: null|string, overlapSize: int, owner: null|string, organisation: null|string, checksum: null|string, createdAt: null|string, updatedAt: null|string, positionReference: array|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'sourceType'         => $this->sourceType,
            'sourceId'           => $this->sourceId,
            'chunkIndex'         => $this->chunkIndex,
            'startOffset'        => $this->startOffset,
            'endOffset'          => $this->endOffset,
            'language'           => $this->language,
            'languageLevel'      => $this->languageLevel,
            'languageConfidence' => $this->languageConfidence,
            'indexed'            => $this->indexed,
            'vectorized'         => $this->vectorized,
            'embeddingProvider'  => $this->embeddingProvider,
            'overlapSize'        => $this->overlapSize,
            'owner'              => $this->owner,
            'organisation'       => $this->organisation,
            'checksum'           => $this->checksum,
            'createdAt'          => $this->createdAt?->format(DateTime::ATOM),
            'updatedAt'          => $this->updatedAt?->format(DateTime::ATOM),
            'positionReference'  => $this->positionReference,
        ];
    }//end jsonSerialize()
}//end class
