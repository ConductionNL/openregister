<?php

/**
 * QueuedNotification entity for the durable notification queue.
 *
 * One row per notification that the dispatcher decided to hold instead of
 * emitting immediately — either because the recipient is inside their
 * configured quiet-hours delivery window, or because the triggering rule
 * declares a fixed-time `digest` schedule that has not yet fired. The row
 * carries the fully pre-resolved subject/message/channels/context so
 * `NotificationQueueFlushJob` never needs to re-run recipient or template
 * resolution at flush time — it only needs to re-evaluate whether the
 * holding condition has cleared.
 *
 * Replaces the in-memory `NotificationDigest` primitive, which never
 * survived the process boundary between the web/cron request that would
 * enqueue an event and the next flush-job tick (see
 * openspec/changes/notification-delivery-windows/design.md, "Rejected
 * alternatives").
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/changes/notification-delivery-windows/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * QueuedNotification.
 *
 * @method int getSchemaId()
 * @method void setSchemaId(int $schemaId)
 * @method string getRuleKey()
 * @method void setRuleKey(string $ruleKey)
 * @method string getRecipient()
 * @method void setRecipient(string $recipient)
 * @method string getReason()
 * @method void setReason(string $reason)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string getPayload()
 * @method void setPayload(string $payload)
 * @method DateTime getDueAtHint()
 * @method void setDueAtHint(DateTime $dueAtHint)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class QueuedNotification extends Entity implements JsonSerializable
{

    /**
     * `reason` value when the row was queued because the recipient is
     * inside an active quiet-hours delivery window.
     */
    public const REASON_QUIET_HOURS = 'quiet-hours';

    /**
     * `reason` value when the row was queued because the rule's fixed-time
     * `digest` schedule has not yet reached its next fire time.
     */
    public const REASON_DIGEST_SCHEDULE = 'digest-schedule';

    /**
     * `reason` value when BOTH conditions applied at enqueue time (the
     * recipient was inside their window AND the rule's digest schedule
     * had not yet fired). Flush still waits for both to clear.
     */
    public const REASON_BOTH = 'quiet-hours+digest-schedule';

    /**
     * Owning schema id.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * Notification annotation key (rule key in `x-openregister-notifications`).
     *
     * @var string|null
     */
    protected ?string $ruleKey = null;

    /**
     * Recipient user UID.
     *
     * @var string|null
     */
    protected ?string $recipient = null;

    /**
     * Why the row was queued: `quiet-hours` | `digest-schedule` |
     * `quiet-hours+digest-schedule`. Advisory only — the flush job always
     * re-evaluates live rather than trusting this label (see design.md
     * "Live re-evaluation at flush time").
     *
     * @var string|null
     */
    protected ?string $reason = null;

    /**
     * The triggering object's UUID, when applicable.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * Pre-resolved subject/message/channels/context, JSON-encoded, so the
     * eventual flush does not need to re-run recipient/template resolution.
     *
     * @var string|null
     */
    protected ?string $payload = null;

    /**
     * Advisory "roughly when this should flush" timestamp, for operator
     * visibility only — never the sole flush trigger (see design.md).
     *
     * @var DateTime|null
     */
    protected ?DateTime $dueAtHint = null;

    /**
     * Row creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'ruleKey', type: 'string');
        $this->addType(fieldName: 'recipient', type: 'string');
        $this->addType(fieldName: 'reason', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'payload', type: 'string');
        $this->addType(fieldName: 'dueAtHint', type: 'datetime');
        $this->addType(fieldName: 'createdAt', type: 'datetime');

    }//end __construct()

    /**
     * Decode the JSON `payload` column into an array.
     *
     * @return array<string, mixed>
     */
    public function getDecodedPayload(): array
    {
        $decoded = json_decode((string) $this->payload, true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;

    }//end getDecodedPayload()

    /**
     * JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'schemaId'   => $this->schemaId,
            'ruleKey'    => $this->ruleKey,
            'recipient'  => $this->recipient,
            'reason'     => $this->reason,
            'objectUuid' => $this->objectUuid,
            'payload'    => $this->getDecodedPayload(),
            'dueAtHint'  => $this->dueAtHint?->format(DateTime::ATOM),
            'createdAt'  => $this->createdAt?->format(DateTime::ATOM),
        ];

    }//end jsonSerialize()
}//end class
