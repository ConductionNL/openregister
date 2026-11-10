<?php

/**
 * What one timer operation carries: who and when.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The acting identity and the clock of one timer operation.
 */
final class FlowTimerAct {

	/**
	 * Constructor.
	 *
	 * @param string|null            $actor The acting identity.
	 * @param DateTimeImmutable      $now   The clock.
	 * @param DateTimeInterface|null $when  The moment the act names: the new anchor of a supersession.
	 */
	public function __construct(
		public readonly ?string $actor,
		public readonly DateTimeImmutable $now,
		public readonly ?DateTimeInterface $when = null,
	) {
	}//end __construct()
}//end class
