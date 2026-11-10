<?php

/**
 * FlowTimerSweepInterface: what the timer worker asks of FlowTimerSweep.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
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

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeInterface;

/**
 * The business-timer sweep, as the background timer worker uses it. Only the method that caller calls.
 */
interface FlowTimerSweepInterface {

	/**
	 * Run one pass of the timer sweep.
	 *
	 * @param DateTimeInterface $now   The sweep instant.
	 * @param int               $batch The per-scan batch limit.
	 *
	 * @return array{expiriesFired: int, rungsFired: int, taskTimeouts: int, truncated: bool, errors: int} Work performed.
	 */
	public function run(DateTimeInterface $now, int $batch): array;
}//end interface
