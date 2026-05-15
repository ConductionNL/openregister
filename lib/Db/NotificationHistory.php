<?php

/**
 * NotificationHistory entity for the audit trail of notification dispatches.
 *
 * One row per (rule, channel, recipient) emission. Closes the
 * `notificatie-engine` spec's
 * "Notification history MUST be stored and queryable for audit
 * purposes" requirement.
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
 * NotificationHistory.
 *
 * @method string getRuleId()
 * @method void setRuleId(string $ruleId)
 * @method string|null getSchemaId()
 * @method void setSchemaId(?string $schemaId)
 * @method string|null getRegisterId()
 * @method void setRegisterId(?string $registerId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string getRecipient()
 * @method void setRecipient(string $recipient)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method string|null getLocale()
 * @method void setLocale(?string $locale)
 * @method DateTime getDispatchedAt()
 * @method void setDispatchedAt(DateTime $dispatchedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class NotificationHistory extends Entity implements JsonSerializable
{

    /**
     * Annotation key (per-schema rule identifier).
     *
     * @var string|null
     */
    protected ?string $ruleId = null;

    /**
     * Schema the rule lives on.
     *
     * @var string|null
     */
    protected ?string $schemaId = null;

    /**
     * Register the object lives in.
     *
     * @var string|null
     */
    protected ?string $registerId = null;

    /**
     * Object the event happened on.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * Channel the notification was emitted on.
     *
     * One of `nc-notification`, `email`, `activity`, `webhook`, `talk`.
     *
     * @var string|null
     */
    protected ?string $channel = null;

    /**
     * Recipient identifier.
     *
     * Per-recipient channels: the user's uid. Broadcast channels:
     * `__webhook__` / `__talk__`.
     *
     * @var string|null
     */
    protected ?string $recipient = null;

    /**
     * Interpolated subject string actually emitted.
     *
     * @var string|null
     */
    protected ?string $subject = null;

    /**
     * Status of the dispatch.
     *
     * One of `dispatched`, `rate-limited`, `failed`.
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * Error message — populated when status is `failed`.
     *
     * @var string|null
     */
    protected ?string $errorMessage = null;

    /**
     * Locale of the recipient (null for broadcast channels).
     *
     * @var string|null
     */
    protected ?string $locale = null;

    /**
     * Wall-clock timestamp of the dispatch.
     *
     * @var DateTime|null
     */
    protected ?DateTime $dispatchedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'ruleId', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'channel', type: 'string');
        $this->addType(fieldName: 'recipient', type: 'string');
        $this->addType(fieldName: 'subject', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'errorMessage', type: 'string');
        $this->addType(fieldName: 'locale', type: 'string');
        $this->addType(fieldName: 'dispatchedAt', type: 'datetime');

    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->id,
            'ruleId'       => $this->ruleId,
            'schemaId'     => $this->schemaId,
            'registerId'   => $this->registerId,
            'objectUuid'   => $this->objectUuid,
            'channel'      => $this->channel,
            'recipient'    => $this->recipient,
            'subject'      => $this->subject,
            'status'       => $this->status,
            'errorMessage' => $this->errorMessage,
            'locale'       => $this->locale,
            'dispatchedAt' => $this->dispatchedAt?->format(DateTime::ATOM),
        ];

    }//end jsonSerialize()
}//end class
