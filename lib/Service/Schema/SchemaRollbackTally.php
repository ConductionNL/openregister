<?php

/**
 * The restored and conflict counts of one migration rollback.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * Counts what a rollback restored and what it skipped as a conflict.
 */
final class SchemaRollbackTally {

	/**
	 * Objects restored to their pre-migration snapshot.
	 *
	 * @var int
	 */
	private int $restored = 0;

	/**
	 * Objects skipped: gone, edited since, or refused by the save.
	 *
	 * @var int
	 */
	private int $conflicts = 0;

	/**
	 * Count one restored object.
	 *
	 * @return void
	 */
	public function restore(): void {
		$this->restored++;

	}//end restore()

	/**
	 * Count one conflict.
	 *
	 * @return void
	 */
	public function conflict(): void {
		$this->conflicts++;

	}//end conflict()

	/**
	 * The counts as the run report's `rollback` entry.
	 *
	 * @return array{restored: int, conflicts: int} The counts.
	 */
	public function toArray(): array {
		return ['restored' => $this->restored, 'conflicts' => $this->conflicts];
	}//end toArray()
}//end class
