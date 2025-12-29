<?php

/**
 * EntityRelation links detected entities to specific chunks with context.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class EntityRelation
 *
 * @method int getEntityId()
 * @method void setEntityId(int $entityId)
 * @method int getChunkId()
 * @method void setChunkId(int $chunkId)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method int|null getObjectId()
 * @method void setObjectId(?int $objectId)
 * @method int|null getEmailId()
 * @method void setEmailId(?int $emailId)
 * @method int getPositionStart()
 * @method void setPositionStart(int $positionStart)
 * @method int getPositionEnd()
 * @method void setPositionEnd(int $positionEnd)
 * @method float getConfidence()
 * @method void setConfidence(float $confidence)
 * @method string getDetectionMethod()
 * @method void setDetectionMethod(string $detectionMethod)
 * @method string|null getContext()
 * @method void setContext(?string $context)
 * @method bool getAnonymized()
 * @method void setAnonymized(bool $anonymized)
 * @method string|null getAnonymizedValue()
 * @method void setAnonymizedValue(?string $anonymizedValue)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class EntityRelation extends Entity implements JsonSerializable
{

    /**
     * Entity ID.
     *
     * @var integer|null
     */
    protected ?int $entityId = null;

    /**
     * Chunk ID.
     *
     * @var integer|null
     */
    protected ?int $chunkId = null;

    /**
     * Role.
     *
     * @var string|null
     */
    protected ?string $role = null;

    /**
     * File ID.
     *
     * @var integer|null
     */
    protected ?int $fileId = null;

    /**
     * Object ID.
     *
     * @var integer|null
     */
    protected ?int $objectId = null;

    /**
     * Email ID.
     *
     * @var integer|null
     */
    protected ?int $emailId = null;

    /**
     * Position start.
     *
     * @var integer
     */
    protected int $positionStart = 0;

    /**
     * Position end.
     *
     * @var integer
     */
    protected int $positionEnd = 0;

    /**
     * Confidence.
     *
     * @var float
     */
    protected float $confidence = 0.0;

    /**
     * Detection method.
     *
     * @var string|null
     */
    protected ?string $detectionMethod = null;

    /**
     * Context.
     *
     * @var string|null
     */
    protected ?string $context = null;

    /**
     * Anonymized flag.
     *
     * @var boolean
     */
    protected bool $anonymized = false;

    /**
     * Anonymized value.
     *
     * @var string|null
     */
    protected ?string $anonymizedValue = null;

    /**
     * Created at timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType('entityId', 'integer');
        $this->addType('chunkId', 'integer');
        $this->addType('role', 'string');
        $this->addType('fileId', 'integer');
        $this->addType('objectId', 'integer');
        $this->addType('emailId', 'integer');
        $this->addType('positionStart', 'integer');
        $this->addType('positionEnd', 'integer');
        $this->addType('confidence', 'float');
        $this->addType('detectionMethod', 'string');
        $this->addType('context', 'string');
        $this->addType('anonymized', 'boolean');
        $this->addType('anonymizedValue', 'string');
        $this->addType('createdAt', 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return (null|scalar)[]
     *
     * @psalm-return array{id: int, entityId: int|null, chunkId: int|null, role: null|string, fileId: int|null, objectId: int|null, emailId: int|null, positionStart: int, positionEnd: int, confidence: float, detectionMethod: null|string, context: null|string, anonymized: bool, anonymizedValue: null|string, createdAt: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->id,
            'entityId'        => $this->entityId,
            'chunkId'         => $this->chunkId,
            'role'            => $this->role,
            'fileId'          => $this->fileId,
            'objectId'        => $this->objectId,
            'emailId'         => $this->emailId,
            'positionStart'   => $this->positionStart,
            'positionEnd'     => $this->positionEnd,
            'confidence'      => $this->confidence,
            'detectionMethod' => $this->detectionMethod,
            'context'         => $this->context,
            'anonymized'      => $this->anonymized,
            'anonymizedValue' => $this->anonymizedValue,
            'createdAt'       => $this->createdAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
