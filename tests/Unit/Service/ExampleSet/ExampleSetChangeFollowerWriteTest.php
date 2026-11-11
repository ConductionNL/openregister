<?php

/**
 * Unit test for ExampleSetChangeFollower's write refusals: a change whose
 * target was refused writes nothing, and a write the store refuses keeps the
 * held entry as it was.
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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFields;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFollower;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetHeldChange;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFollower
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetHeldChange
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue
 */
class ExampleSetChangeFollowerWriteTest extends TestCase {

	/**
	 * The held entry.
	 *
	 * @var array<string, mixed>
	 */
	private const ENTRY = ['id' => 'c-1', 'set' => [['path' => 'status', 'had' => true, 'old' => 'new', 'new' => 'open']], 'append' => []];

	/**
	 * A held change of the case, refused or resolved to the given row.
	 *
	 * @param string $refusal Why the target was refused, or ''.
	 *
	 * @return ExampleSetHeldChange
	 */
	private static function held(string $refusal): ExampleSetHeldChange {
		return new ExampleSetHeldChange(
			change: ['register' => 'cases', 'schema' => 'case', 'set' => ['status' => 'open', 'priority' => 'high']],
			entry: self::ENTRY,
			resolved: ['refusal' => $refusal, 'row' => ['id' => 'c-1', 'status' => 'open']],
			refresh: false
		);
	}//end held()

	/**
	 * A refused target writes nothing and reports the refusal with the entry unchanged.
	 *
	 * @return void
	 */
	public function testARefusedTargetWritesNothing(): void {
		$fields = $this->createMock(ExampleSetChangeFields::class);
		$fields->expects($this->never())->method('setRows');
		$fields->expects($this->never())->method('write');
		$follower = new ExampleSetChangeFollower(new ExampleSetPaths(), $fields, new ExampleSetWrittenValue());

		$this->assertSame(
			['error' => 'object "case-1" is gone', 'entry' => self::ENTRY, 'wrote' => false],
			$follower->write(held: self::held(refusal: 'object "case-1" is gone'), gained: ['priority'])
		);
	}//end testARefusedTargetWritesNothing()

	/**
	 * A gained field whose write the store refuses reports why and keeps the entry as it was.
	 *
	 * @return void
	 */
	public function testARefusedWriteKeepsTheEntry(): void {
		$fields = $this->createMock(ExampleSetChangeFields::class);
		$fields->method('setRows')->with(['refusal' => '', 'row' => ['id' => 'c-1', 'status' => 'open']], ['priority'])
			->willReturn([['path' => 'priority', 'had' => false, 'old' => null, 'new' => 'high']]);
		$fields->method('textFields')->willReturn([]);
		$fields->expects($this->once())->method('write')
			->with(['register' => 'cases', 'schema' => 'case', 'id' => 'c-1'], ['id' => 'c-1', 'status' => 'open', 'priority' => 'high'])
			->willReturn('the object is locked');
		$follower = new ExampleSetChangeFollower(new ExampleSetPaths(), $fields, new ExampleSetWrittenValue());

		$this->assertSame(
			['error' => 'the object is locked', 'entry' => self::ENTRY, 'wrote' => false],
			$follower->write(held: self::held(refusal: ''), gained: ['priority'])
		);
	}//end testARefusedWriteKeepsTheEntry()
}//end class
