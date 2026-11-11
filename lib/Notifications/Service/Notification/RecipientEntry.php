<?php

/**
 * One entry of a notification rule's `recipients`, with the rule it belongs to.
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
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * A recipient entry, its index in the list, and the rule that declares it.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class RecipientEntry {

	/**
	 * The rule the entry belongs to.
	 *
	 * @var NotificationRuleContext
	 */
	public readonly NotificationRuleContext $rule;

	/**
	 * The entry's index in `recipients`, for messages.
	 *
	 * @var int|string
	 */
	public readonly int|string $index;

	/**
	 * The entry as declared; not necessarily an array.
	 *
	 * @var mixed
	 */
	public readonly mixed $recipient;

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleContext $rule      The rule.
	 * @param int|string              $index     The entry's index.
	 * @param mixed                   $recipient The entry.
	 */
	public function __construct(NotificationRuleContext $rule, int|string $index, mixed $recipient) {
		$this->rule      = $rule;
		$this->index     = $index;
		$this->recipient = $recipient;
	}//end __construct()

	/**
	 * One key of the entry, null when absent or when the entry is no array.
	 *
	 * @param string $key The key.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function value(string $key): mixed {
		if (is_array($this->recipient) === false) {
			return null;
		}

		return ($this->recipient[$key] ?? null);
	}//end value()

	/**
	 * One error, its message prefixed with the rule name and entry index.
	 *
	 * @param string $code   The error code.
	 * @param string $detail The message after `recipient[n] `.
	 *
	 * @return array{code: string, message: string}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function error(string $code, string $detail): array {
		return [
			'code' => $code,
			'message' => sprintf('Notification "%s" recipient[%d] ', $this->rule->name, $this->index).$detail,
		];
	}//end error()
}//end class
