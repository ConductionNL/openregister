<?php

/**
 * One register to split off a shared schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

/**
 * A plan entry of the dedupe service and the register it splits off.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class PlannedSchemaSplit {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $entry      The plan entry.
	 * @param int                  $registerId The register that is split off.
	 */
	public function __construct(
		public readonly array $entry,
		public readonly int $registerId,
	) {
	}//end __construct()
}//end class
