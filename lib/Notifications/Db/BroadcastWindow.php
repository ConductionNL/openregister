<?php

/**
 * When a broadcast shows: the moment it starts and the moment it stops.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;

/**
 * When a broadcast shows: the moment it starts and the moment it stops.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */
final readonly class BroadcastWindow {
	/**
	 * Hold the values.
	 *
	 * @param DateTime $startsAt When it starts showing.
	 * @param DateTime $endsAt   When it stops showing.
	 */
	public function __construct(
		public DateTime $startsAt,
		public DateTime $endsAt,
	) {
	}//end __construct()
}//end class
