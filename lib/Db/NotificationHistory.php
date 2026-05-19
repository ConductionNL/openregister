<?php

/**
 * NotificationHistory
 *
 * Entity for the oc_openregister_notification_history audit-trail table.
 * Records every dispatch attempt per (rule, channel, recipient).
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Notification history record.
 *
 * @method int         getId()
 * @method void        setId(int $id)
 * @method string      getRuleId()
 * @method void        setRuleId(string $ruleId)
 * @method string      getChannel()
 * @method void        setChannel(string $channel)
 * @method string      getRecipient()
 * @method void        setRecipient(string $recipient)
 * @method string|null getObjectUuid()
 * @method void        setObjectUuid(?string $objectUuid)
 * @method string|null getSchemaId()
 * @method void        setSchemaId(?string $schemaId)
 * @method string|null getRegisterId()
 * @method void        setRegisterId(?string $registerId)
 * @method string      getStatus()
 * @method void        setStatus(string $status)
 * @method DateTime    getDispatchedAt()
 * @method void        setDispatchedAt(DateTime $dispatchedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class NotificationHistory extends Entity implements JsonSerializable
{

    /**
     * Rule identifier (annotation name or uuid).
     *
     * @var string
     */
    protected string $ruleId = '';

    /**
     * Delivery channel (nc-notification / email / activity / webhook / talk).
     *
     * @var string
     */
    protected string $channel = '';

    /**
     * Recipient identifier (uid, email, url, etc.).
     *
     * @var string
     */
    protected string $recipient = '';

    /**
     * UUID of the object that triggered the notification.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * Schema identifier.
     *
     * @var string|null
     */
    protected ?string $schemaId = null;

    /**
     * Register identifier.
     *
     * @var string|null
     */
    protected ?string $registerId = null;

    /**
     * Dispatch status: dispatched / rate-limited / coalesced / failed.
     *
     * @var string
     */
    protected string $status = 'dispatched';

    /**
     * Timestamp of the dispatch attempt.
     *
     * @var DateTime
     */
    protected DateTime $dispatchedAt;

    /**
     * Constructor: sets up type hints for DB column casting.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'ruleId', type: 'string');
        $this->addType(fieldName: 'channel', type: 'string');
        $this->addType(fieldName: 'recipient', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'dispatchedAt', type: 'datetime');

        $this->dispatchedAt = new DateTime();
    }//end __construct()

    /**
     * Serialize to JSON-safe array.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->getId(),
            'ruleId'       => $this->ruleId,
            'channel'      => $this->channel,
            'recipient'    => $this->recipient,
            'objectUuid'   => $this->objectUuid,
            'schemaId'     => $this->schemaId,
            'registerId'   => $this->registerId,
            'status'       => $this->status,
            'dispatchedAt' => $this->dispatchedAt->format(format: 'c'),
        ];
    }//end jsonSerialize()
}//end class
