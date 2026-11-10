<?php

/**
 * A notification kind's resolved preference together with the kind's own declaration.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * What the preference merge resolved for one kind, and what the schema declares for it.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
 */
final class KindPreference {
	/**
	 * Hold the preference.
	 *
	 * @param array<string,mixed> $resolved    What `NotificationPreferenceService::resolveEffective()` returned.
	 * @param array<string,mixed> $declaration The notification's own declaration from the schema.
	 */
	public function __construct(
		public readonly array $resolved,
		public readonly array $declaration,
	) {
	}//end __construct()
}//end class
