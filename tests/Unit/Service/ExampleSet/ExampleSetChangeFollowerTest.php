<?php

/**
 * Unit tests for ExampleSetChangeFollower — keeping a held change in step with its declaration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFields;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFollower;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetHeldChange;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExampleSetChangeFollowerTest extends TestCase {

	private const CHANGE = ['register' => 'cases', 'schema' => 'case', 'set' => ['status' => 'open', 'priority' => 'high']];

	private ExampleSetChangeFields&MockObject $fields;
	private ExampleSetChangeFollower $follower;

	protected function setUp(): void {
		$this->fields   = $this->createMock(ExampleSetChangeFields::class);
		$this->fields->method('textFields')->willReturn([]);
		$this->follower = new ExampleSetChangeFollower(new ExampleSetPaths(), $this->fields, new ExampleSetWrittenValue());
	}

	private function entry(): array {
		return ['id' => 'c-1', 'set' => [['path' => 'status', 'had' => true, 'old' => 'new', 'new' => 'open']], 'append' => []];
	}

	private function held(array $row, bool $refresh): ExampleSetHeldChange {
		return new ExampleSetHeldChange(
			self::CHANGE,
			$this->entry(),
			['refusal' => '', 'row' => $row, 'set' => self::CHANGE['set']],
			$refresh
		);
	}

	public function testHeldOnlyKeepsTheRowsOnPathsTheChangeStillDeclares(): void {
		$entry = [
			'id' => 'c-1',
			'set' => [['path' => 'status'], ['path' => 'dropped']],
			'append' => [['path' => 'tags'], ['path' => 'oldList']],
		];

		$this->assertSame(
			['id' => 'c-1', 'set' => [['path' => 'status']], 'append' => [['path' => 'tags']]],
			$this->follower->heldOnly(['set' => ['status' => 'x'], 'append' => ['tags' => 'y']], $entry)
		);
	}

	public function testGainedNamesTheDeclaredPathsTheEntryDoesNotHoldYet(): void {
		$this->assertSame(['priority'], $this->follower->gained(self::CHANGE, $this->entry()));
		$this->assertSame([], $this->follower->gained(['set' => ['status' => 'open']], $this->entry()));
	}

	public function testNothingGainedAndNoRefreshWritesNothing(): void {
		$this->fields->method('setRows')->willReturn([]);
		$this->fields->expects($this->never())->method('write');

		$this->assertSame(
			['error' => '', 'entry' => $this->entry(), 'wrote' => false],
			$this->follower->write($this->held(['id' => 'c-1', 'status' => 'new'], false), [])
		);
	}

	public function testAGainedFieldIsWrittenAndAddedToTheEntry(): void {
		$fresh = [['path' => 'priority', 'had' => false, 'old' => null, 'new' => 'high', 'from' => 'priority']];
		$this->fields->expects($this->once())->method('setRows')->with($this->anything(), ['priority'])->willReturn($fresh);
		$this->fields->expects($this->once())->method('write')
			->with(['register' => 'cases', 'schema' => 'case', 'id' => 'c-1'], ['id' => 'c-1', 'status' => 'open', 'priority' => 'high'])
			->willReturn('');

		$result = $this->follower->write($this->held(['id' => 'c-1', 'status' => 'open'], false), ['priority']);

		$this->assertTrue($result['wrote']);
		$this->assertSame('', $result['error']);
		$this->assertSame(array_merge($this->entry()['set'], $fresh), $result['entry']['set']);
	}

	public function testARefreshRewritesAValueSomeoneUndid(): void {
		$this->fields->method('setRows')->willReturn([]);
		$this->fields->expects($this->once())->method('write')
			->with($this->anything(), ['id' => 'c-1', 'status' => 'open'])
			->willReturn('');

		$result = $this->follower->write($this->held(['id' => 'c-1', 'status' => 'new'], true), []);

		$this->assertTrue($result['wrote']);
		$this->assertSame('open', $result['entry']['set'][0]['new']);
	}

	public function testARefreshLeavesAValueThatStillHoldsAlone(): void {
		$this->fields->method('setRows')->willReturn([]);
		$this->fields->expects($this->never())->method('write');

		$this->assertFalse($this->follower->write($this->held(['id' => 'c-1', 'status' => 'open'], true), [])['wrote']);
	}

	public function testARefreshLeavesAValueSomeoneElseEditedAlone(): void {
		$this->fields->method('setRows')->willReturn([]);
		$this->fields->expects($this->never())->method('write');

		$this->assertFalse($this->follower->write($this->held(['id' => 'c-1', 'status' => 'closed'], true), [])['wrote']);
	}
}
