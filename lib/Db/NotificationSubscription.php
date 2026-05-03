<?php

/**
 * NotificationSubscription entity.
 *
 * Wraps an `oc_openregister_notification_subscriptions` row.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Notification subscription row.
 *
 * @method void           setUserId(string $userId)
 * @method string|null    getUserId()
 * @method void           setRegisterId(?int $registerId)
 * @method int|null       getRegisterId()
 * @method void           setSchemaId(?int $schemaId)
 * @method int|null       getSchemaId()
 * @method void           setCreated(\DateTime $created)
 * @method \DateTime|null getCreated()
 */
class NotificationSubscription extends Entity
{

    /**
     * User UID owning this subscription.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * Register identifier scope; null means any register.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * Schema identifier scope; null means any schema in the register.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * Creation timestamp for the subscription row.
     *
     * @var \DateTime|null
     */
    protected ?\DateTime $created = null;

    /**
     * Configure typed columns for the entity.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'userId', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'created', type: 'datetime');

    }//end __construct()

    /**
     * Flat array shape for response embedding.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->getId(),
            'userId'     => $this->getUserId(),
            'registerId' => $this->getRegisterId(),
            'schemaId'   => $this->getSchemaId(),
            'created'    => ($this->getCreated()?->format(\DateTimeInterface::ATOM) ?? null),
        ];

    }//end jsonSerialize()
}//end class
