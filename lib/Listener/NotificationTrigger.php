<?php

/**
 * OpenRegister NotificationTrigger
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

/**
 * A notification trigger and its context, as dispatched inline or carried in a deferred entry.
 */
final class NotificationTrigger {
	/**
	 * Constructor.
	 *
	 * @param string               $name    The trigger (created, transition, ...).
	 * @param array<string, mixed> $context The context the rules read.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $context = [],
	) {
	}//end __construct()

	/**
	 * The fields this trigger adds to a deferred entry: the trigger first, then its context.
	 *
	 * @return array<string, mixed>
	 */
	public function deferredFields(): array {
		return array_merge(['trigger' => $this->name], $this->context);
	}//end deferredFields()
}//end class
