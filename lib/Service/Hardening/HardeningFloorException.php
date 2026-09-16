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
class HardeningFloorException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $control The control that would have been weakened.
	 * @param int $floor The floor in force.
	 * @param int $proposed The value that was asked for.
	 * @param string $comparator `atLeast` or `atMost`.
	 * @param string $message The refusal, in one sentence.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $control,
		public readonly int $floor,
		public readonly int $proposed,
		public readonly string $comparator,
		string $message,
	) {
		parent::__construct($message);

	}//end __construct()

	/**
	 * The refusal as the API returns it.
	 *
	 * @return array<string, mixed> The refusal body.
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
