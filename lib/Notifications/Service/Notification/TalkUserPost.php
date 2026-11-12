<?php

/**
 * One attributed Talk post: the message, its conversation and the acting user.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * An attributed Talk post in flight, and the named failure for each way it can
 * fail to deliver. Built by TalkSender::postViaTalkApp() and never leaves it.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */
final class TalkUserPost {

	/**
	 * Constructor.
	 *
	 * @param TalkMessage $talkMessage The message and its conversation.
	 * @param string      $actorUid    The acting user's uid.
	 */
	public function __construct(
		public readonly TalkMessage $talkMessage,
		public readonly string $actorUid,
	) {
	}//end __construct()

	/**
	 * The named failure for an error Talk raised while posting.
	 *
	 * A room or participant that cannot be found means the acting user is not
	 * in the conversation; the message is not sent and nobody is auto-joined.
	 *
	 * @param \Throwable $error What Talk raised.
	 *
	 * @return TalkSendException
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function failure(\Throwable $error): TalkSendException {
		$class = get_class($error);
		if (str_contains($class, 'ParticipantNotFound') === true || str_contains($class, 'RoomNotFound') === true) {
			return new TalkSendException(
				sprintf(
					'User "%s" is not a participant of Talk conversation "%s"; the message was not sent and the user was not auto-joined.',
					$this->actorUid,
					$this->talkMessage->token
				)
			);
		}

		return new TalkSendException(
			sprintf('Talk post to "%s" as "%s" failed: %s', $this->talkMessage->token, $this->actorUid, $error->getMessage())
		);
	}//end failure()
}//end class
