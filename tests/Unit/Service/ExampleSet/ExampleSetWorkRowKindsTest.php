<?php

/**
 * Unit test for ExampleSetWorkRowKinds: the writers it bundles, by fragment part.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetWorkRowKinds;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetWorkRowKinds
 */
class ExampleSetWorkRowKindsTest extends TestCase {
	/**
	 * Without writers every part is there, without a writer.
	 *
	 * @return void
	 */
	public function testEmptyBundleHasEveryPartWithoutWriter(): void {
		$this->assertSame(
			[
				'tasks' => null,
				'notes' => null,
				'readStates' => null,
				'watchers' => null,
			],
			(new ExampleSetWorkRowKinds())->writers()
		);
	}//end testEmptyBundleHasEveryPartWithoutWriter()

	/**
	 * Each writer is given under its own part.
	 *
	 * @return void
	 */
	public function testEachWriterUnderItsPart(): void {
		$tasks = $this->createMock(ExampleSetTasks::class);
		$notes = $this->createMock(ExampleSetNotes::class);
		$readStates = $this->createMock(ExampleSetReadStates::class);
		$watchers = $this->createMock(ExampleSetWatchers::class);
		$bundle = new ExampleSetWorkRowKinds(
			tasks: $tasks,
			notes: $notes,
			readStates: $readStates,
			watchers: $watchers
		);
		$this->assertSame(
			[
				'tasks' => $tasks,
				'notes' => $notes,
				'readStates' => $readStates,
				'watchers' => $watchers,
			],
			$bundle->writers()
		);
	}//end testEachWriterUnderItsPart()
}//end class
