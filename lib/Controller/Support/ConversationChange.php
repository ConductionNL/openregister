<?php

/**
 * OpenRegister conversation change
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * A write on one conversation: which conversation, what the caller wants to do
 * with it, and the error a failure reports.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ConversationChange {
	/**
	 * Describe the change.
	 *
	 * @param string $uuid  The conversation.
	 * @param string $verb  What the caller does to it, as the refusal names it (modify, delete, restore).
	 * @param string $error The error a failure reports.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $verb,
		public readonly string $error,
	) {
	}//end __construct()

	/**
	 * The failure to report when the change throws.
	 *
	 * @return ConversationFailure The failure.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function failure(): ConversationFailure {
		return new ConversationFailure(uuid: $this->uuid, error: $this->error);
	}//end failure()

	/**
	 * The refusal message for a caller who may not make the change.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function deniedMessage(): string {
		return 'You do not have permission to ' . $this->verb . ' this conversation';
	}//end deniedMessage()
}//end class
