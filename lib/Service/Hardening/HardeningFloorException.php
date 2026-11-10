<?php

/**
 * Thrown when a change would take a control below the floor this instance
 * declared for it.
 *
 * The message names the control, the floor and what was asked for, because a
 * refusal an administrator cannot act on sends them to the logs. It never
 * names anything about the caller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use RuntimeException;

/**
 * A refused weakening.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class HardeningFloorException extends RuntimeException implements HardeningFloorExceptionInterface {

	/**
	 * The control that would have been weakened.
	 *
	 * @var string
	 */
	public readonly string $control;

	/**
	 * The floor in force.
	 *
	 * @var integer
	 */
	public readonly int $floor;

	/**
	 * The value that was asked for.
	 *
	 * @var integer
	 */
	public readonly int $proposed;

	/**
	 * `atLeast` or `atMost`.
	 *
	 * @var string
	 */
	public readonly string $comparator;

	/**
	 * Constructor.
	 *
	 * @param FloorBreach $breach  The floor the value would have crossed.
	 * @param string      $message The refusal, in one sentence.
	 *
	 * @return void
	 */
	public function __construct(FloorBreach $breach, string $message) {
		parent::__construct(message: $message);
		$this->control = $breach->control;
		$this->floor = $breach->floor;
		$this->proposed = $breach->proposed;
		$this->comparator = $breach->comparator;

	}//end __construct()

	/**
	 * The refusal as the API returns it.
	 *
	 * @return array<string, mixed> The refusal body.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function toArray(): array {
		return [
			'error' => $this->getMessage(),
			'control' => $this->control,
			'floor' => $this->floor,
			'proposed' => $this->proposed,
			'comparator' => $this->comparator,
		];

	}//end toArray()
}//end class
