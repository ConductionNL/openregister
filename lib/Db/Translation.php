<?php

/**
 * OpenRegister Translation entity.
 *
 * One row in `openregister_translations` — represents a single
 * (object × property × language) translation slot, holding the
 * denormalised value plus its workflow status.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getProperty()
 * @method void setProperty(?string $property)
 * @method string|null getLanguage()
 * @method void setLanguage(?string $language)
 * @method string|null getValue()
 * @method void setValue(?string $value)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getTranslator()
 * @method void setTranslator(?string $translator)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 */
class Translation extends Entity implements JsonSerializable
{

    public const STATUS_DRAFT = 'draft';
    public const STATUS_MACHINE_TRANSLATED = 'machine_translated';
    public const STATUS_HUMAN_REVIEWED     = 'human_reviewed';
    public const STATUS_APPROVED           = 'approved';

    public const ALL_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_MACHINE_TRANSLATED,
        self::STATUS_HUMAN_REVIEWED,
        self::STATUS_APPROVED,
    ];

    protected ?string $objectUuid = null;

    protected ?string $property = null;

    protected ?string $language = null;

    protected ?string $value = null;

    protected ?string $status = null;

    protected ?string $translator = null;

    protected ?DateTime $updated = null;

    public function __construct()
    {
        $this->addType('objectUuid', 'string');
        $this->addType('property', 'string');
        $this->addType('language', 'string');
        $this->addType('value', 'string');
        $this->addType('status', 'string');
        $this->addType('translator', 'string');
        $this->addType('updated', 'datetime');
    }//end __construct()

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'objectUuid' => $this->objectUuid,
            'property'   => $this->property,
            'language'   => $this->language,
            'value'      => $this->value,
            'status'     => $this->status,
            'translator' => $this->translator,
            'updated'    => $this->updated?->format(\DateTimeInterface::ATOM),
        ];
    }//end jsonSerialize()
}//end class
