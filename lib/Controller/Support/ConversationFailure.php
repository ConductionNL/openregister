<?php

/**
 * OpenRegister conversation failure
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
 * How a failed conversation action is logged and answered.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ConversationFailure {

	/**
	 * The log message, without the controller prefix.
	 *
	 * @var string
	 */
	private string $logMessage;

	/**
	 * A failure of the action on one conversation, logged with its answer text.
	 *
	 * @param string $uuid  The conversation the action was on.
	 * @param string $error The error text of the 500 answer.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $error,
	) {
		$this->logMessage = $error;
	}//end __construct()

	/**
	 * The same failure, logged with another message than its answer text.
	 *
	 * @param string $message The log message, without the controller prefix.
	 *
	 * @return self The failure.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function loggedAs(string $message): self {
		$failure             = clone $this;
		$failure->logMessage = $message;
		return $failure;
	}//end loggedAs()

	/**
	 * The log message, without the controller prefix.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function logMessage(): string {
		return $this->logMessage;
	}//end logMessage()
}//end class
