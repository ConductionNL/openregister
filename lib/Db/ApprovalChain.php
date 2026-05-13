<?php

/**
 * OpenRegister ApprovalChain Entity
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class representing a multi-step approval chain configuration.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getStatusField()
 * @method void setStatusField(?string $statusField)
 * @method string|null getSteps()
 * @method void setSteps(?string $steps)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class ApprovalChain extends Entity implements JsonSerializable
{

    /**
     * The uuid.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * The name.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * The schema id.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * The status field.
     *
     * @var string|null
     */
    protected ?string $statusField = 'status';

    /**
     * The steps.
     *
     * @var string|null
     */
    protected ?string $steps = null;

    /**
     * The enabled.
     *
     * @var boolean
     */
    protected bool $enabled = true;

    /**
     * The created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * The updated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Constructor for ApprovalChain entity.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'statusField', type: 'string');
        $this->addType(fieldName: 'steps', type: 'string');
        $this->addType(fieldName: 'enabled', type: 'boolean');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
    }//end __construct()

    /**
     * Get the steps as a decoded array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStepsArray(): array
    {
        if ($this->steps === null) {
            return [];
        }

        return json_decode($this->steps, true) ?? [];
    }//end getStepsArray()

    /**
     * Hydrate entity from array.
     *
     * @param array<string, mixed> $object Data to hydrate from
     *
     * @return self
     */
    public function hydrate(array $object): self
    {
        $fields = [
            'uuid',
            'name',
            'schemaId',
            'statusField',
            'steps',
            'enabled',
            'created',
            'updated',
        ];

        foreach ($object as $key => $value) {
            if (in_array($key, $fields, true) === true) {
                $setter = 'set'.ucfirst($key);
                if ($key === 'steps' && is_array($value) === true) {
                    $value = json_encode($value);
                }

                $this->$setter($value);
            }
        }

        return $this;
    }//end hydrate()

    /**
     * Serialize to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'name'        => $this->name,
            'schemaId'    => $this->schemaId,
            'statusField' => $this->statusField,
            'steps'       => $this->getStepsArray(),
            'enabled'     => $this->enabled,
            'created'     => $this->created?->format('c'),
            'updated'     => $this->updated?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
