<?php

/**
 * What one timer operation carries: who, when, and why.
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
 * The acting identity, the clock, and the reason of one timer operation.
 */
final class FlowTimerAct {

	/**
	 * Constructor.
	 *
	 * @param string|null            $actor  The acting identity.
	 * @param DateTimeImmutable      $now    The clock.
	 * @param string                 $reason Why, as recorded.
	 * @param string|null            $basis  The legal ground.
	 * @param DateTimeInterface|null $when   The moment the act names: the expected
	 *                                       end of a suspension, the new anchor of a supersession.
	 */
	public function __construct(
		public readonly ?string $actor,
		public readonly DateTimeImmutable $now,
		public readonly string $reason = '',
		public readonly ?string $basis = null,
		public readonly ?DateTimeInterface $when = null,
	) {
	}//end __construct()
}//end class
