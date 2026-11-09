<?php

/**
 * MessageTarget: the firing and language a message is rendered for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * One rendering of a rule: for a recipient's language, or null for a broadcast.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class MessageTarget {

	/**
	 * Constructor.
	 *
	 * @param RuleFiring  $firing The rule and the event.
	 * @param string|null $locale Recipient locale, or null for broadcast channels.
	 */
	public function __construct(
		public readonly RuleFiring $firing,
		public readonly ?string $locale,
	) {
	}//end __construct()
}//end class
