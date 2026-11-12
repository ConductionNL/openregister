<?php

/**
 * ActionTargetContext: what a notification action's target is resolved against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The triggering object, its data and the app the notification belongs to.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ActionTargetContext {
	/**
	 * Constructor
	 *
	 * @param ObjectEntity         $object    The triggering object.
	 * @param array<string, mixed> $data      The triggering object's data.
	 * @param string               $originApp The resolved origin app id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly array $data,
		public readonly string $originApp,
	) {
	}//end __construct()
}//end class
