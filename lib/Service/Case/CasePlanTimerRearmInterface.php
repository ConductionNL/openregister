<?php

/**
 * CasePlanTimerRearmInterface: what callers outside the Service component ask of CasePlanTimerRearm.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use DateTimeInterface;

/**
 * The plan-timer re-arm pass, as its background job runs it. Only the method
 * that caller calls.
 */
interface CasePlanTimerRearmInterface {

	/**
	 * Re-arm, enforce or drop the timers of live plan items.
	 *
	 * @param DateTimeInterface|null $now The clock; null is the real clock.
	 *
	 * @return array{armed: int, enforced: int, dropped: int, failed: int} Work performed.
	 */
	public function run(?DateTimeInterface $now = null): array;
}//end interface
