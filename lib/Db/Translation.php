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
 * Translation row entity.
 *
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

    /**
     * UUID of the object the translation slot belongs to.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * Object property path being translated.
     *
     * @var string|null
     */
    protected ?string $property = null;

    /**
     * BCP-47 language tag for the translation.
     *
     * @var string|null
     */
    protected ?string $language = null;

    /**
     * Translated value (may be long-form text).
     *
     * @var string|null
     */
    protected ?string $value = null;

    /**
     * Workflow status of the translation slot.
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * UID of the translator who last wrote this row.
     *
     * @var string|null
     */
    protected ?string $translator = null;

    /**
     * Timestamp of the last update to this row.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Configure typed columns for the entity.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'property', type: 'string');
        $this->addType(fieldName: 'language', type: 'string');
        $this->addType(fieldName: 'value', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'translator', type: 'string');
        $this->addType(fieldName: 'updated', type: 'datetime');
    }//end __construct()

    /**
     * Flat array shape for response embedding.
     *
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
