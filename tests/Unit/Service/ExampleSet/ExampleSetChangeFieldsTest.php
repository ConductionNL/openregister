<?php

/**
 * Unit tests for ExampleSetChangeFields — the field-level part of an example-set change.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFields;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExampleSetChangeFieldsTest extends TestCase {

	private ExampleSetStore&MockObject $store;
	private ExampleSetChangeFields $fields;

	protected function setUp(): void {
		$this->store  = $this->createMock(ExampleSetStore::class);
		$this->fields = new ExampleSetChangeFields($this->store, new ExampleSetPaths());
	}

	public function testJsonFieldsAreTheUniqueStringEntries(): void {
		$this->assertSame(['a', 'b'], $this->fields->jsonFields(['jsonFields' => ['a', 3, 'b', 'a', null]]));
		$this->assertSame([], $this->fields->jsonFields([]));
	}

	public function testTextFieldsJoinJsonFieldsWithStructuredTextPropertiesTheRowHolds(): void {
		$this->store->expects($this->once())->method('textProperties')
			->with('reg', 'sch')
			->willReturn(['body', 'summary', 'a']);

		$this->assertSame(
			['a', 'body'],
			$this->fields->textFields(
				['register' => 'reg', 'schema' => 'sch', 'jsonFields' => ['a']],
				['body' => ['blocks' => []], 'summary' => 'plain text', 'a' => ['x' => 1]]
			)
		);
	}

	public function testSetRowsRecordWhatEachPathHeldAndSkipsUnchangedOnes(): void {
		$resolved = [
			'row' => ['status' => 'open', 'meta' => ['owner' => 'ann']],
			'set' => ['status' => 'open', 'meta.owner' => 'bob', 'deep.new.key' => 1],
		];

		$this->assertSame(
			[
				['path' => 'meta.owner', 'had' => true, 'old' => 'ann', 'new' => 'bob', 'from' => 'meta.owner'],
				['path' => 'deep.new.key', 'had' => false, 'old' => null, 'new' => 1, 'from' => 'deep'],
			],
			$this->fields->setRows($resolved, ['status', 'meta.owner', 'deep.new.key'])
		);
	}

	public function testANullValueIsAChangeWhenThePathWasMissing(): void {
		$rows = $this->fields->setRows(['row' => [], 'set' => ['x' => null]], ['x']);

		$this->assertSame([['path' => 'x', 'had' => false, 'old' => null, 'new' => null, 'from' => 'x']], $rows);
	}

	public function testWriteUpdatesTheReferencedObject(): void {
		$this->store->expects($this->once())->method('update')
			->with(
				$this->callback(
					static fn (ObjectRef $ref): bool => $ref->register === '1' && $ref->schema === '2' && $ref->id === 'abc'
				),
				['title' => 'new']
			)
			->willReturn('updated');

		$this->assertSame(
			'updated',
			$this->fields->write(['register' => 1, 'schema' => 2, 'id' => 'abc'], ['title' => 'new'])
		);
	}

	public function testInListComparesStrictlyAgainstTheListAtThePath(): void {
		$row = ['tags' => ['a', '1'], 'single' => 'x'];

		$this->assertTrue($this->fields->inList($row, ['path' => 'tags', 'value' => 'a']));
		$this->assertFalse($this->fields->inList($row, ['path' => 'tags', 'value' => 1]));
		$this->assertTrue($this->fields->inList($row, ['path' => 'single', 'value' => 'x']));
		$this->assertFalse($this->fields->inList($row, ['path' => 'missing', 'value' => 'a']));
	}
}
