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

	/**
	 * The act of one identity at a moment; null is the real clock.
	 *
	 * @param string|null            $actor The acting identity.
	 * @param DateTimeInterface|null $now   The clock; null means the real clock.
	 *
	 * @return self The act.
	 */
	public static function of(?string $actor, ?DateTimeInterface $now = null): self {
		return new self(actor: $actor, now: (new FlowTimerValues())->instant(now: $now));
	}//end of()
}//end class
