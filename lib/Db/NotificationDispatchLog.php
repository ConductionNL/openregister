<?php

/**
 * NotificationDispatchLog entity for idempotency-key deduplication.
 *
 * One row per (notification_slug, idempotency_key) pair. The dispatcher
 * inserts a row on first dispatch and skips subsequent dispatches within
 * the configured retention window (default 24 h). Rows outside the window
 * are cleaned up lazily on read or by a scheduled prune job.
 *
 * Closes the scholiq deps requirement:
 * "The notification engine MUST deduplicate dispatches by
 * (notification_slug, resolved_idempotency_key) over a configurable
 * window (default 24 h)."
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
 * NotificationDispatchLog.
 *
 * @method string getNotificationSlug()
 * @method void setNotificationSlug(string $notificationSlug)
 * @method string getIdempotencyKey()
 * @method void setIdempotencyKey(string $idempotencyKey)
 * @method DateTime getDispatchedAt()
 * @method void setDispatchedAt(DateTime $dispatchedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class NotificationDispatchLog extends Entity implements JsonSerializable
{

    /**
     * The notification annotation key (slug) that was dispatched.
     *
     * @var string|null
     */
    protected ?string $notificationSlug = null;

    /**
     * The resolved idempotency key for this dispatch.
     *
     * @var string|null
     */
    protected ?string $idempotencyKey = null;

    /**
     * Wall-clock timestamp of the first dispatch for this key.
     *
     * @var DateTime|null
     */
    protected ?DateTime $dispatchedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'notificationSlug', type: 'string');
        $this->addType(fieldName: 'idempotencyKey', type: 'string');
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
            'id'               => $this->id,
            'notificationSlug' => $this->notificationSlug,
            'idempotencyKey'   => $this->idempotencyKey,
            'dispatchedAt'     => $this->dispatchedAt?->format(DateTime::ATOM),
        ];

    }//end jsonSerialize()
}//end class
