<?php

/**
 * OpenRegister ApprovalStep Entity
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
 * Entity class representing a single approval step for an object in a chain.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int|null getChainId()
 * @method void setChainId(?int $chainId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method int getStepOrder()
 * @method void setStepOrder(int $stepOrder)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(?string $decidedBy)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method DateTime|null getDecidedAt()
 * @method void setDecidedAt(?DateTime $decidedAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class ApprovalStep extends Entity implements JsonSerializable
{

    /**
     * The uuid.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * The chain id.
     *
     * @var integer|null
     */
    protected ?int $chainId = null;

    /**
     * The object uuid.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The step order.
     *
     * @var integer
     */
    protected int $stepOrder = 0;

    /**
     * The role.
     *
     * @var string|null
     */
    protected ?string $role = null;

    /**
     * The status.
     *
     * @var string|null
     */
    protected ?string $status = 'pending';

    /**
     * The decided by.
     *
     * @var string|null
     */
    protected ?string $decidedBy = null;

    /**
     * The comment.
     *
     * @var string|null
     */
    protected ?string $comment = null;

    /**
     * The decided at.
     *
     * @var DateTime|null
     */
    protected ?DateTime $decidedAt = null;

    /**
     * The created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Constructor for ApprovalStep entity.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'chainId', type: 'integer');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'stepOrder', type: 'integer');
        $this->addType(fieldName: 'role', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'decidedBy', type: 'string');
        $this->addType(fieldName: 'comment', type: 'string');
        $this->addType(fieldName: 'decidedAt', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
    }//end __construct()

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
            'chainId',
            'objectUuid',
            'stepOrder',
            'role',
            'status',
            'decidedBy',
            'comment',
            'decidedAt',
            'created',
        ];

        foreach ($object as $key => $value) {
            if (in_array($key, $fields, true) === true) {
                $setter = 'set'.ucfirst($key);
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
            'id'         => $this->id,
            'uuid'       => $this->uuid,
            'chainId'    => $this->chainId,
            'objectUuid' => $this->objectUuid,
            'stepOrder'  => $this->stepOrder,
            'role'       => $this->role,
            'status'     => $this->status,
            'decidedBy'  => $this->decidedBy,
            'comment'    => $this->comment,
            'decidedAt'  => $this->decidedAt?->format('c'),
            'created'    => $this->created?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
