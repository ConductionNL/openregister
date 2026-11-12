<?php

/**
 * Behaviour tests for ExampleSetChanges and its read-back: which target a change
 * finds, what it writes, and how lost and revert read it back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake\FakeExampleSetStore;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * Behaviour of a change row: locating its one target, applying it, and reading
 * it back for lost and revert.
 */
class ExampleSetChangesBehaviourTest extends TestCase {

	private FakeExampleSetStore $store;

	protected function setUp(): void {
		$this->store = new FakeExampleSetStore();
		$this->store->offer(register: 'cases', schema: 'case');
	}//end setUp()

	private function changes(): ExampleSetChanges {
		return new ExampleSetChanges(store: $this->store, values: new ExampleSetValues());
	}//end changes()

	private static function change(array $override=[]): array {
		return array_merge(['key' => 'c1', 'register' => 'cases', 'schema' => 'case', 'where' => ['title' => 'Inspectie']], $override);
	}//end change()

	public function testSeveralMatchesRefuseTheChange(): void {
		$this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Inspectie']);
		$this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Inspectie']);

		$resolved = $this->changes()->resolve(self::change(['set' => ['status' => 'open']]), []);

		$this->assertSame('2 cases case rows match {"title":"Inspectie"}; a change needs exactly one to change', $resolved['refusal']);
	}

	public function testNoMatchRefusesTheChange(): void {
		$this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Ander']);

		$resolved = $this->changes()->resolve(self::change(['set' => ['status' => 'open']]), []);

		$this->assertSame('no cases case with {"title":"Inspectie"} to change', $resolved['refusal']);
	}

	public function testAnEmptyWhereMatchesNothing(): void {
		$this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Inspectie']);

		$resolved = $this->changes()->resolve(self::change(['where' => [], 'set' => ['status' => 'open']]), []);

		$this->assertSame('no cases case with [] to change', $resolved['refusal']);
	}

	public function testAnObjectNotWrittenEarlierRefusesTheChange(): void {
		$resolved = $this->changes()->resolve(self::change(['where' => [], 'object' => 'case-9', 'set' => ['status' => 'open']]), ['objects' => []]);

		$this->assertSame('object "case-9" is not written earlier in this fragment', $resolved['refusal']);
	}

	public function testAnUnresolvablePlaceholderRefusesTheChange(): void {
		$resolved = $this->changes()->resolve(self::change(['set' => ['owner' => '{{cast:nobody.uid}}']]), []);

		$this->assertStringStartsWith('cannot resolve ', $resolved['refusal']);
	}

	public function testAChangeThatAltersNothingWritesNothing(): void {
		$id       = $this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Inspectie', 'status' => 'open', 'tags' => ['a']]);
		$change   = self::change(['set' => ['status' => 'open'], 'append' => ['tags' => 'a']]);
		$resolved = $this->changes()->resolve($change, []);

		$this->assertSame('', $resolved['refusal']);
		$this->assertSame(['error' => '', 'entry' => null], $this->changes()->apply($change, $resolved));
		$this->assertSame('open', $this->store->row(ref: new ObjectRef(register: 'cases', schema: 'case', id: $id))['status']);
	}

	public function testAppliedSetAndAppendAreReadBackAndReverted(): void {
		$id       = $this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Inspectie', 'status' => 'new', 'tags' => ['a']]);
		$change   = self::change(['set' => ['status' => 'open'], 'append' => ['tags' => 'b']]);
		$changes  = $this->changes();
		$resolved = $changes->resolve($change, []);
		$applied  = $changes->apply($change, $resolved);

		$this->assertSame('', $applied['error']);
		$row = $this->store->row(ref: new ObjectRef(register: 'cases', schema: 'case', id: $id));
		$this->assertSame('open', $row['status']);
		$this->assertSame(['a', 'b'], $row['tags']);
		$this->assertSame([], $changes->lost($applied['entry']));

		$this->assertSame(['ok' => true, 'left' => []], $changes->revert($applied['entry']));
		$row = $this->store->row(ref: new ObjectRef(register: 'cases', schema: 'case', id: $id));
		$this->assertSame('new', $row['status']);
		$this->assertSame(['a'], $row['tags']);
	}

	public function testAnEditedFieldIsLostAndLeftOnRevert(): void {
		$id       = $this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Inspectie', 'status' => 'new', 'tags' => []]);
		$change   = self::change(['set' => ['status' => 'open'], 'append' => ['tags' => 'b']]);
		$changes  = $this->changes();
		$applied  = $changes->apply($change, $changes->resolve($change, []));
		$this->store->rows['cases case'][$id]['status'] = 'closed by hand';
		$this->store->rows['cases case'][$id]['tags']   = [];

		$this->assertSame(['status', 'tags'], $changes->lost($applied['entry']));

		$reverted = $changes->revert($applied['entry']);
		$this->assertSame(['status'], $reverted['left']);
		$this->assertSame('closed by hand', $this->store->row(ref: new ObjectRef(register: 'cases', schema: 'case', id: $id))['status']);
	}

	public function testAGoneRowIsLostAndItsRevertIsDone(): void {
		$id      = $this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Inspectie', 'status' => 'new']);
		$change  = self::change(['set' => ['status' => 'open']]);
		$changes = $this->changes();
		$applied = $changes->apply($change, $changes->resolve($change, []));
		unset($this->store->rows['cases case'][$id]);

		$this->assertSame(['(gone)'], $changes->lost($applied['entry']));
		$this->assertSame(['ok' => true, 'left' => []], $changes->revert($applied['entry']));
	}

	public function testAnUnreadableRowFailsTheRevert(): void {
		$id      = $this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'Inspectie', 'status' => 'new']);
		$change  = self::change(['set' => ['status' => 'open']]);
		$changes = $this->changes();
		$applied = $changes->apply($change, $changes->resolve($change, []));
		$this->store->unreadable[] = $id;

		$this->assertSame(['ok' => false, 'left' => []], $changes->revert($applied['entry']));
	}
}//end class
