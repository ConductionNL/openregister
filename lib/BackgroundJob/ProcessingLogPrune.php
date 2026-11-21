<?php

/**
 * OpenRegister processing log prune
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;

/**
 * One prune of the processing log: the retention applied, the cutoff it gave and how many entries went.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class ProcessingLogPrune {
	/**
	 * Describe the prune.
	 *
	 * @param string   $retention The ISO 8601 retention applied.
	 * @param DateTime $cutoff    Entries created before this moment went.
	 * @param int      $deleted   How many entries went.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		public readonly string $retention,
		public readonly DateTime $cutoff,
		public readonly int $deleted,
	) {
	}//end __construct()

	/**
	 * The prune as the audit trail records it: period and count.
	 *
	 * @return array{retention: string, before: string, deleted: int} The recorded change.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function recorded(): array {
		return ['retention' => $this->retention, 'before' => $this->cutoff->format('c'), 'deleted' => $this->deleted];
	}//end recorded()
}//end class
