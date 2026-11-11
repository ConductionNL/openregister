<?php

/**
 * OpenRegister calculation recompute run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation\TemporalSweep
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation\TemporalSweep;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The state of one {@see CalculationRecomputer::changes()} pass over an object: the payload
 * as recomputed so far, the calculation being recomputed, and whether any value changed.
 * It never leaves the recomputer that built it.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class RecomputeRun {

	/**
	 * The name of the calculation being recomputed.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Whether any recomputed value differs from the stored one.
	 *
	 * @var boolean
	 */
	public bool $changed = false;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $object  The object being recomputed.
	 * @param array<string, mixed> $payload The evaluation payload; later calculations see earlier fresh values.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public array $payload,
	) {
	}//end __construct()
}//end class
