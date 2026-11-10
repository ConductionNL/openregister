<?php

/**
 * Unit test for ExampleSetRowInstall's unresolved placeholders: a row whose
 * data names something the context cannot fill is dropped as unresolvable,
 * even when its kind would accept it, and nothing is written.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows;
use OCA\OpenRegister\Service\ExampleSet\RecordLedger;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentWrite;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRowInstall;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowInstall
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetFillRun
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentRows
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetMoment
 */
class ExampleSetRowInstallGapTest extends TestCase {

	/**
	 * A row with a placeholder the context cannot fill is dropped naming the placeholder once, and not written.
	 *
	 * @return void
	 */
	public function testAnUnresolvablePlaceholderDropsTheRow(): void {
		$rows = $this->createMock(ExampleSetEngineRows::class);
		$rows->method('part')->willReturn('notes');
		$rows->method('noun')->willReturn('note');
		$rows->expects($this->never())->method('write');
		$install = new ExampleSetRowInstall(
			rows: $rows,
			refusal: static fn (array $data, array $users, array $context): string => '',
			fill: static fn (array $context): array => $context,
			values: new ExampleSetValues()
		);
		$report   = [];
		$record   = ['users' => []];
		$fragment = ['app' => 'demo', 'file' => 'f.json', 'notes' => [['key' => 'n', 'data' => ['objectUuid' => '{{object:gone.id}}', 'again' => '{{object:gone.id}}']]]];

		$this->assertFalse($install->fragment(write: new ExampleSetFragmentWrite(fragment: $fragment, context: ['objects' => []], values: new ExampleSetValues()), ledger: new RecordLedger(report: $report, record: $record)));
		$this->assertSame(['notes' => ['demo/n' => 'failed'], 'dropped' => ['f.json: note "n": cannot resolve {{object:gone.id}}']], $report);
		$this->assertSame(['users' => []], $record);
	}//end testAnUnresolvablePlaceholderDropsTheRow()
}//end class
