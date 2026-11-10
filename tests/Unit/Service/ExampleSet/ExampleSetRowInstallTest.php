<?php

/**
 * Unit test for ExampleSetRowInstall: the install loop of one kind of engine row.
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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRowInstall;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use OCA\OpenRegister\Service\ExampleSet\FilledRow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowInstall
 */
class ExampleSetRowInstallTest extends TestCase {
	/**
	 * The refusal is asked with the filled fields, the set's accounts and the context, in the context the kind fills
	 * in; a refused row is not written.
	 *
	 * @return void
	 */
	public function testRefusalAndFillContextAreAskedAndARefusedRowIsNotWritten(): void {
		$rows = $this->createMock(ExampleSetEngineRows::class);
		$rows->method('part')->willReturn('notes');
		$rows->method('noun')->willReturn('note');
		$rows->expects($this->never())->method('write');
		$asked   = [];
		$install = new ExampleSetRowInstall(
			rows: $rows,
			refusal: function (FilledRow $row, array $context) use (&$asked): string {
				$asked[] = [$row->data, $row->users, $context];
				return 'no';
			},
			fill: function (array $context) use (&$asked): array {
				$asked[] = ['fill', $context];
				return $context;
			},
			values: new ExampleSetValues()
		);
		$report  = [];
		$record  = ['users' => ['alice' => 'created']];
		$fragment = ['app' => 'demo', 'file' => 'f.json', 'notes' => [['key' => 'n', 'data' => ['by' => 'alice']]]];
		$this->assertFalse($install->fragment(fragment: $fragment, context: ['x' => 1], report: $report, record: $record));
		$this->assertSame([['fill', ['x' => 1]], [['by' => 'alice'], ['alice'], ['x' => 1]]], $asked);
		$this->assertSame(['notes' => ['demo/n' => 'failed'], 'dropped' => ['f.json: note "n": no']], $report);
	}//end testRefusalAndFillContextAreAskedAndARefusedRowIsNotWritten()

	/**
	 * An accepted row is written and held.
	 *
	 * @return void
	 */
	public function testAnAcceptedRowIsWrittenAndHeld(): void {
		$rows = $this->createMock(ExampleSetEngineRows::class);
		$rows->method('part')->willReturn('notes');
		$rows->method('noun')->willReturn('note');
		$rows->expects($this->once())->method('write')->with(new ExampleSetRow(app: 'demo', key: 'n', data: ['text' => 'hi']))->willReturn(['error' => '', 'entry' => ['id' => 9]]);
		$install = new ExampleSetRowInstall(
			rows: $rows,
			refusal: static fn (FilledRow $row, array $context): string => '',
			fill: static fn (array $context): array => $context,
			values: new ExampleSetValues()
		);
		$report  = [];
		$record  = ['users' => []];
		$fragment = ['app' => 'demo', 'file' => 'f.json', 'notes' => [['key' => 'n', 'data' => ['text' => 'hi']]]];
		$this->assertTrue($install->fragment(fragment: $fragment, context: [], report: $report, record: $record));
		$this->assertSame(['notes' => ['demo/n' => 'created']], $report);
		$this->assertSame(['users' => [], 'notes' => ['demo/n' => ['id' => 9]]], $record);
	}//end testAnAcceptedRowIsWrittenAndHeld()
}//end class
