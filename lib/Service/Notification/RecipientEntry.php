<?php

/**
 * One entry of a notification rule's `recipients`, as the validator sees it.
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

namespace OCA\OpenRegister\Service\Notification;

/**
 * The rule a recipient entry belongs to and the entry's index.
 */
final class RecipientEntry {

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleContext $rule  The rule.
	 * @param int|string              $index The entry's index in `recipients`.
	 */
	public function __construct(
		public readonly NotificationRuleContext $rule,
		public readonly int|string $index,
	) {
	}//end __construct()

	/**
	 * A validation error about this entry.
	 *
	 * @param string $code   The error code.
	 * @param string $detail The message after `Notification "<rule>" recipient[<index>] `.
	 *
	 * @return array{code: string, message: string}
	 */
	public function error(string $code, string $detail): array {
		return [
			'code' => $code,
			'message' => sprintf('Notification "%s" recipient[%d] ', $this->rule->name, $this->index).$detail,
		];
	}//end error()
}//end class
