<?php

/**
 * NotificationSubscription
 *
 * Entity for per-user (register, schema) notification subscription preferences.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Represents a user's subscription to notifications for a register or schema.
 *
 * Both registerId and schemaId are nullable so the same table models:
 * - "subscribe to a whole register" (schemaId = null)
 * - "subscribe to one (register, schema) tuple"
 * - "subscribe to a single schema across registers" (registerId = null)
 *
 * @method int         getId()
 * @method void        setId(int $id)
 * @method string      getUserId()
 * @method void        setUserId(string $userId)
 * @method string|null getRegisterId()
 * @method void        setRegisterId(?string $registerId)
 * @method string|null getSchemaId()
 * @method void        setSchemaId(?string $schemaId)
 * @method DateTime    getCreatedAt()
 * @method void        setCreatedAt(DateTime $createdAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class NotificationSubscription extends Entity implements JsonSerializable
{

    /**
     * Nextcloud user identifier.
     *
     * @var string
     */
    protected string $userId = '';

    /**
     * Register identifier (nullable — subscription covers all registers when null).
     *
     * @var string|null
     */
    protected ?string $registerId = null;

    /**
     * Schema identifier (nullable — subscription covers all schemas when null).
     *
     * @var string|null
     */
    protected ?string $schemaId = null;

    /**
     * Subscription creation timestamp.
     *
     * @var DateTime
     */
    protected DateTime $createdAt;

    /**
     * Constructor: configures type hints.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'userId', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');

        $this->createdAt = new DateTime();
    }//end __construct()

    /**
     * Serialize to JSON-safe array.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->getId(),
            'userId'     => $this->userId,
            'registerId' => $this->registerId,
            'schemaId'   => $this->schemaId,
            'createdAt'  => $this->createdAt->format(format: 'c'),
        ];
    }//end jsonSerialize()
}//end class
