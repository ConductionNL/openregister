<?php

/**
 * The running outcome of a size recalculation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

/**
 * Counts what a size recalculation processed and what failed.
 */
final class SizeTally {
	/**
	 * Items saved.
	 *
	 * @var int
	 */
	private int $processed = 0;

	/**
	 * Items that could not be saved.
	 *
	 * @var int
	 */
	private int $failed = 0;

	/**
	 * Count one saved item.
	 *
	 * @return void
	 */
	public function addProcessed(): void {
		$this->processed++;
	}//end addProcessed()

	/**
	 * Count one failure.
	 *
	 * @return void
	 */
	public function addFailed(): void {
		$this->failed++;
	}//end addFailed()

	/**
	 * The counts as the dashboard reports them.
	 *
	 * @return array{processed: int, failed: int}
	 */
	public function toArray(): array {
		return ['processed' => $this->processed, 'failed' => $this->failed];
	}//end toArray()
}//end class
