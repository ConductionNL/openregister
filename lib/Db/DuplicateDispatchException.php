<?php

/**
 * OpenRegister Duplicate Dispatch Exception
 *
 * Signals that a notification dispatch lost the race against a concurrent
 * dispatcher for the same (notification_slug, idempotency_key) pair.
 * Authoritative serialisation is provided by the unique index defined in
 * Version1Date20260511120000; this exception is what
 * NotificationDispatchLogMapper::record() throws when the index trips,
 * so callers can abort the send before flagging the message as delivered.
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

use RuntimeException;

/**
 * Raised by NotificationDispatchLogMapper::record() when the unique
 * (notification_slug, idempotency_key) index trips.
 *
 * @package OCA\OpenRegister\Db
 */
class DuplicateDispatchException extends RuntimeException
{
}//end class
