<?php

/**
 * FlowTimerLedgerEntry
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTime;
use DateTimeInterface;

/**
 * One history row of a timer, before it is written: what happened, who did
 * it, why and when, and how the fire moment moved.
 *
 * @spec openspec/specs/flow-business-timers/spec.md
 */
final class FlowTimerLedgerEntry {

	/**
	 * The fire moment before.
	 *
	 * @var DateTime|null
	 */
	private ?DateTime $priorFireAt = null;

	/**
	 * The fire moment after.
	 *
	 * @var DateTime|null
	 */
	private ?DateTime $newFireAt = null;

	/**
	 * The legal ground.
	 *
	 * @var string
	 */
	private string $basis = '';

	/**
	 * The impact in the budget unit.
	 *
	 * @var float|null
	 */
	private ?float $impact = null;

	/**
	 * Constructor.
	 *
	 * @param string            $type   The event type.
	 * @param string|null       $actor  The acting identity.
	 * @param string            $reason The reason.
	 * @param DateTimeInterface $moment The moment.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $actor,
		public readonly string $reason,
		public readonly DateTimeInterface $moment,
	) {

	}//end __construct()

	/**
	 * A copy recording how the fire moment moved.
	 *
	 * @param DateTime|null $prior The fire moment before.
	 * @param DateTime|null $next  The fire moment after.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function withFireMoments(?DateTime $prior, ?DateTime $next): self {
		$copy = clone $this;
		$copy->priorFireAt = $prior;
		$copy->newFireAt = $next;
		return $copy;
	}//end withFireMoments()

	/**
	 * A copy naming the legal ground and the impact in the budget unit.
	 *
	 * @param string     $basis  The legal ground.
	 * @param float|null $impact The impact.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function withBasis(string $basis, ?float $impact = null): self {
		$copy = clone $this;
		$copy->basis = $basis;
		$copy->impact = $impact;
		return $copy;
	}//end withBasis()

	/**
	 * The fire moment before.
	 *
	 * @return DateTime|null The moment.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function priorFireAt(): ?DateTime {
		return $this->priorFireAt;
	}//end priorFireAt()

	/**
	 * The fire moment after.
	 *
	 * @return DateTime|null The moment.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function newFireAt(): ?DateTime {
		return $this->newFireAt;
	}//end newFireAt()

	/**
	 * The legal ground.
	 *
	 * @return string The basis; empty when none.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function basis(): string {
		return $this->basis;
	}//end basis()

	/**
	 * The impact in the budget unit.
	 *
	 * @return float|null The impact.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function impact(): ?float {
		return $this->impact;
	}//end impact()
}//end class
