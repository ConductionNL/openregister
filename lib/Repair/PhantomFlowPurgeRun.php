<?php

/**
 * The progress of one phantom-flow purge: what was removed, what was spared.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCP\Migration\IOutput;

/**
 * Counts removals and collects the rows a human has to review, for PurgePhantomMigratedFlows.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
final class PhantomFlowPurgeRun {

	/**
	 * How many rows were removed.
	 *
	 * @var integer
	 */
	private int $removed = 0;

	/**
	 * One line per spared row that needs review.
	 *
	 * @var array<int, string>
	 */
	private array $reported = [];

	/**
	 * Count one removed row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function countRemoval(): void {
		$this->removed++;
	}//end countRemoval()

	/**
	 * Record a spared row for review.
	 *
	 * @param string $line The row's uuid and why it was spared.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function report(string $line): void {
		$this->reported[] = $line;
	}//end report()

	/**
	 * Print the counts and every spared row.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function announce(IOutput $output): void {
		$output->info(
			sprintf('Phantom flow rows: %d removed, %d reported for review.', $this->removed, count($this->reported))
		);

		foreach ($this->reported as $line) {
			$output->info('  needs review: ' . $line);
		}
	}//end announce()
}//end class
