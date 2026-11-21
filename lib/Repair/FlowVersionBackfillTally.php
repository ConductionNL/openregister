<?php

/**
 * The counts of one flow-version back-fill: published, already versioned, pinned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * Adds up what BackfillFlowVersions did per flow, and says it in one line.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
final class FlowVersionBackfillTally {

	/**
	 * Flows that got their version 1 in this run.
	 *
	 * @var integer
	 */
	private int $published = 0;

	/**
	 * Flows that already had a version.
	 *
	 * @var integer
	 */
	private int $skipped = 0;

	/**
	 * In-flight runs pinned to a version.
	 *
	 * @var integer
	 */
	private int $pinned = 0;

	/**
	 * Add the outcome of one flow.
	 *
	 * @param array{published: boolean, pinned: integer} $result What was done for that flow.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function record(array $result): void {
		if ($result['published'] === true) {
			$this->published++;
		} else {
			$this->skipped++;
		}

		$this->pinned += $result['pinned'];
	}//end record()

	/**
	 * The summary line the repair step prints.
	 *
	 * @return string The counts in words.
	 */
	public function summary(): string {
		return sprintf(
			'Flow versions: published version 1 for %d flow(s), %d already versioned, pinned %d in-flight run(s).',
			$this->published,
			$this->skipped,
			$this->pinned
		);
	}//end summary()
}//end class
