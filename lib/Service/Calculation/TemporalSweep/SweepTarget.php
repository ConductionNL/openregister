<?php

/**
 * OpenRegister SweepTarget
 *
 * One register+schema pair the temporal sweep re-evaluates, with the
 * materialised calculations it recomputes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation\TemporalSweep
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation\TemporalSweep;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A temporal schema, its owning register and its materialised calculations.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class SweepTarget {

	/**
	 * Hold the pair and its calculations.
	 *
	 * @param Register $register The owning register.
	 * @param Schema $schema The temporal schema.
	 * @param array<string, mixed> $calcs The schema's materialised calculations.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly array $calcs,
	) {
	}//end __construct()
}//end class
