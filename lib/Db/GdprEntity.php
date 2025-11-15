<?php

declare(strict_types=1);

/**
 * GDPR entity representing detected PII/contact information.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class GdprEntity
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getValue()
 * @method void setValue(string $value)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method int|null getBelongsToEntityId()
 * @method void setBelongsToEntityId(?int $belongsToEntityId)
 * @method array|null getMetadata()
 * @method void setMetadata(?array $metadata)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime getDetectedAt()
 * @method void setDetectedAt(DateTime $detectedAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class GdprEntity extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
    protected ?string $type = null;
    protected ?string $value = null;
    protected ?string $category = null;
    protected ?int $belongsToEntityId = null;
    protected ?array $metadata = null;
    protected ?string $owner = null;
    protected ?string $organisation = null;
    protected ?DateTime $detectedAt = null;
    protected ?DateTime $updatedAt = null;

    public const TYPE_PERSON = 'person';
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';
    public const TYPE_ORGANIZATION = 'organization';

    public const CATEGORY_PII = 'pii';
    public const CATEGORY_SENSITIVE = 'sensitive_pii';
    public const CATEGORY_BUSINESS = 'business_data';

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('type', 'string');
        $this->addType('value', 'string');
        $this->addType('category', 'string');
        $this->addType('belongsToEntityId', 'integer');
        $this->addType('metadata', 'json');
        $this->addType('owner', 'string');
        $this->addType('organisation', 'string');
        $this->addType('detectedAt', 'datetime');
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
            'type' => $this->type,
            'value' => $this->value,
            'category' => $this->category,
            'belongsToEntityId' => $this->belongsToEntityId,
            'metadata' => $this->metadata,
            'owner' => $this->owner,
            'organisation' => $this->organisation,
            'detectedAt' => $this->detectedAt?->format(DateTime::ATOM),
            'updatedAt' => $this->updatedAt?->format(DateTime::ATOM),
        ];
    }
}


