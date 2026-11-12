<?php

/**
 * TalkSenderInterface: the public surface of TalkSender, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Every public method of TalkSender. Callers depend on this interface, not on the class.
 */
interface TalkSenderInterface {

	public const OUTCOME_KILL_SWITCH = 'kill-switch';

	/**
	 * Post a chat message to a Talk conversation AS a user.
	 *
	 * The flow messaging path: the message appears from the acting user, so
	 * replies have an addressee and the audit trail an actor. The user MUST
	 * already be a participant of the conversation; "not a participant" is a
	 * failure with that reason, never an auto-join.
	 *
	 * @param TalkMessage $talkMessage The message and its conversation.
	 * @param string      $actorUid    The acting user's uid.
	 *
	 * @return string OUTCOME_DISPATCHED or OUTCOME_KILL_SWITCH.
	 *
	 * @throws TalkSendException When Talk is unavailable, the conversation is
	 *                           unknown, the acting user is not a participant,
	 *                           or the post is refused.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function postAsUser(TalkMessage $talkMessage, string $actorUid): string;
}//end interface
