<?php

declare(strict_types=1);

/**
 * EntityRelation links detected entities to specific chunks with context.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 */

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
    protected ?int $entityId = null;
    protected ?int $chunkId = null;
    protected ?string $role = null;
    protected ?int $fileId = null;
    protected ?int $objectId = null;
    protected ?int $emailId = null;
    protected int $positionStart = 0;
    protected int $positionEnd = 0;
    protected float $confidence = 0.0;
    protected ?string $detectionMethod = null;
    protected ?string $context = null;
    protected bool $anonymized = false;
    protected ?string $anonymizedValue = null;
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
            'entityId' => $this->entityId,
            'chunkId' => $this->chunkId,
            'role' => $this->role,
            'fileId' => $this->fileId,
            'objectId' => $this->objectId,
            'emailId' => $this->emailId,
            'positionStart' => $this->positionStart,
            'positionEnd' => $this->positionEnd,
            'confidence' => $this->confidence,
            'detectionMethod' => $this->detectionMethod,
            'context' => $this->context,
            'anonymized' => $this->anonymized,
            'anonymizedValue' => $this->anonymizedValue,
            'createdAt' => $this->createdAt?->format(DateTime::ATOM),
        ];
    }
}


