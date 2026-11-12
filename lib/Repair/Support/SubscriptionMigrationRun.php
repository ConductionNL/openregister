<?php

/**
 * The services one notification-subscription migration run writes through
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair\Support
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

namespace OCA\OpenRegister\Repair\Support;

use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;

/**
 * The preference service and schema mapper a subscription migration run resolved.
 *
 * ADR-011 Rule 3: the run object of MigrateNotificationSubscriptionsToUserConfig's
 * row helpers. It never leaves that class.
 *
 * @spec exclude ADR-011 run object of a repair step, no behaviour of its own
 */
final class SubscriptionMigrationRun {
	/**
	 * Constructor.
	 *
	 * @param NotificationPreferencesInterface $prefs        The preference service the overrides go to.
	 * @param mixed                            $schemaMapper The schema mapper, resolved lazily from the container.
	 *
	 * @spec exclude ADR-011 run object of a repair step, no behaviour of its own
	 */
	public function __construct(
		public readonly NotificationPreferencesInterface $prefs,
		public readonly mixed $schemaMapper,
	) {
	}//end __construct()
}//end class
