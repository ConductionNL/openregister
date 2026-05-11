<?php

/**
 * NotificationReadStateEntity.
 *
 * Wraps an `oc_openregister_notification_readstate` row. Each row
 * records that a specific user has read a specific notification, with
 * the timestamp of the read.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/notificatie-engine/tasks.md "Read/unread tracking MUST be maintained per user per notification"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Notification-read-state row.
 *
 * @method void           setUserId(string $userId)
 * @method string|null    getUserId()
 * @method void           setNotificationId(string $notificationId)
 * @method string|null    getNotificationId()
 * @method void           setReadAt(\DateTime $readAt)
 * @method \DateTime|null getReadAt()
 */
class NotificationReadStateEntity extends Entity
{

    /**
     * User UID.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * Notification UUID this row tracks.
     *
     * @var string|null
     */
    protected ?string $notificationId = null;

    /**
     * Timestamp at which the user read the notification.
     *
     * @var \DateTime|null
     */
    protected ?\DateTime $readAt = null;

    /**
     * Configure typed columns for the entity.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'userId', type: 'string');
        $this->addType(fieldName: 'notificationId', type: 'string');
        $this->addType(fieldName: 'readAt', type: 'datetime');

    }//end __construct()

    /**
     * Flat array shape used by the read-state mapper for response embedding.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->getId(),
            'userId'         => $this->getUserId(),
            'notificationId' => $this->getNotificationId(),
            'readAt'         => ($this->getReadAt()?->format(\DateTimeInterface::ATOM) ?? null),
        ];

    }//end jsonSerialize()
}//end class
