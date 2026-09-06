<?php

/**
 * A Talk post that could not be performed, with the reason a person can act on.
 *
 * Raised by TalkSender's attributed path: Talk not installed, an unknown
 * conversation, an acting user who is not a participant, or a post the Talk
 * API refused. Never raised for the kill switch — a silenced channel is a
 * skip, not a failure.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use RuntimeException;

/**
 * A failed Talk post, carrying its reason.
 */
class TalkSendException extends RuntimeException {
}//end class
