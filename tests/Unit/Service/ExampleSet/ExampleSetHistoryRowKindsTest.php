<?php

/**
 * Unit test for ExampleSetHistoryRowKinds: the writers it bundles, by fragment part.
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

use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetHistoryRowKinds;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetHistoryRowKinds
 */
class ExampleSetHistoryRowKindsTest extends TestCase {
	/**
	 * Without writers every part is there, without a writer.
	 *
	 * @return void
	 */
	public function testEmptyBundleHasEveryPartWithoutWriter(): void {
		$this->assertSame(
			[
				'exportRuns' => null,
				'archival' => null,
				'auditEntries' => null,
				'timelineEntries' => null,
			],
			(new ExampleSetHistoryRowKinds())->writers()
		);
	}//end testEmptyBundleHasEveryPartWithoutWriter()

	/**
	 * Each writer is given under its own part.
	 *
	 * @return void
	 */
	public function testEachWriterUnderItsPart(): void {
		$exportRuns = $this->createMock(ExampleSetExportRuns::class);
		$archival = $this->createMock(ExampleSetArchival::class);
		$auditEntries = $this->createMock(ExampleSetAuditEntries::class);
		$timelineEntries = $this->createMock(ExampleSetTimelineEntries::class);
		$bundle = new ExampleSetHistoryRowKinds(
			exportRuns: $exportRuns,
			archival: $archival,
			auditEntries: $auditEntries,
			timelineEntries: $timelineEntries
		);
		$this->assertSame(
			[
				'exportRuns' => $exportRuns,
				'archival' => $archival,
				'auditEntries' => $auditEntries,
				'timelineEntries' => $timelineEntries,
			],
			$bundle->writers()
		);
	}//end testEachWriterUnderItsPart()
}//end class
