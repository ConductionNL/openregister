<?php

/**
 * One disposal sweep: the day it runs and the records already on a list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * One disposal sweep: the day it runs and the records already on a list (ADR-011 Rule 2).
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class DisposalSweep {
	/**
	 * Build the sweep.
	 *
	 * @param string   $today        Today, as Y-m-d.
	 * @param string[] $excludeUuids UUIDs already on a pending or active list.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly string $today,
		public readonly array $excludeUuids,
	) {
	}//end __construct()
}//end class
