<?php

/**
 * A suspension (opschorting) of one timer: why, until when, on what ground.
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

use DateTimeInterface;

/**
 * The timer to suspend, the reason, the expected end and the legal ground.
 */
final class TimerSuspension {

	/**
	 * Constructor.
	 *
	 * @param string                 $uuid   The timer uuid.
	 * @param string                 $reason Why the term is suspended (required).
	 * @param DateTimeInterface|null $until  The expected end, display-only.
	 * @param string|null            $basis  The legal ground, e.g. `Awb 4:15`.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $reason,
		public readonly ?DateTimeInterface $until = null,
		public readonly ?string $basis = null,
	) {
	}//end __construct()
}//end class
