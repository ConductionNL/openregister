<?php

/**
 * Tests that the record lets go of a held entry whose declaration the set no longer holds, and that a held change
 * writes a field again that reads back null where it was not there when the change first wrote it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * A change or read state the set no longer declares is neither proved nor removed; a field that reads back null
 * counts as not set.
 *
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 */
class ExampleSetGoneDeclarationsTest extends ExampleSetTestCase {

	/**
	 * Set up the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
	}//end setUp()

	/**
	 * A fragment that changes a ticket another app shipped, or no change at all.
	 *
	 * @param array<string, mixed>|null $set The fields the change sets; null declares no change.
	 *
	 * @return void
	 */
	private function ticketChange(?array $set): void {
		$changes = [];
		if ($set !== null) {
			$changes[] = ['key' => 'ticket-eerst', 'register' => 'crm', 'schema' => 'ticket', 'where' => ['key' => 'afvalpas'], 'set' => $set];
		}

		$this->app(app: 'alpha', fragment: ['changes' => $changes]);
	}//end ticketChange()

	/**
	 * A change the set no longer declares is let go: the field it wrote now holds another value, and that is not
	 * reported lost; the report names the change as left, and remove does not put the old value back.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public function testAChangeTheSetNoLongerDeclaresIsLetGo(): void {
		$this->store->offer(register: 'crm', schema: 'ticket');
		$id = $this->store->seed(register: 'crm', schema: 'ticket', data: ['key' => 'afvalpas', 'slaDeadline' => '2026-10-20T17:00:00+00:00']);
		$this->ticketChange(set: ['slaDeadline' => '2026-10-15T17:00:00+00:00']);
		$this->assertTrue(condition: $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['ok']);

		$this->ticketChange(set: null);
		$this->store->rows['crm ticket'][$id]['slaDeadline'] = '2026-10-16T17:00:00+00:00';
		$report = $this->installer->install(plan: ($this->catalogue->gather(set: 'demo') + ['refresh' => true]), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertContains(needle: 'change alpha/ticket-eerst: the set no longer declares it', haystack: $report['left']);

		$this->remover->remove(set: 'demo');
		$this->assertSame(expected: '2026-10-16T17:00:00+00:00', actual: $this->store->rows['crm ticket'][$id]['slaDeadline']);
	}//end testAChangeTheSetNoLongerDeclaresIsLetGo()

	/**
	 * A read state the set no longer declares is let go: that it is gone from the instance is not reported missing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public function testAReadStateTheSetNoLongerDeclaresIsLetGo(): void {
		$this->store->offer(register: 'cases', schema: 'case');
		$case = ['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']];
		$read = ['key' => 'esra-opende', 'data' => ['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}']];
		$this->app(app: 'alpha', fragment: ['objects' => [$case], 'readStates' => [$read]]);
		$this->assertTrue(condition: $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['ok']);

		$this->app(app: 'alpha', fragment: ['objects' => [$case], 'readStates' => []]);
		$this->readStates = [];
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertNotContains(needle: 'read state alpha/esra-opende', haystack: $report['missing']);
		$this->assertContains(needle: 'read state alpha/esra-opende: the set no longer declares it', haystack: $report['left']);
	}//end testAReadStateTheSetNoLongerDeclaresIsLetGo()

	/**
	 * Only the entries of the fragments in the plan are let go: an app whose fragment is not gathered keeps its own.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public function testAnEntryOfAnAppOutsideThePlanIsKept(): void {
		$this->store->offer(register: 'crm', schema: 'ticket');
		$this->store->seed(register: 'crm', schema: 'ticket', data: ['key' => 'afvalpas', 'slaDeadline' => null]);
		$this->ticketChange(set: ['slaDeadline' => '2026-10-15T17:00:00+00:00']);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue(condition: $this->installer->install(plan: $plan, today: $this->today())['ok']);

		$plan['fragments'] = array_values(array_filter($plan['fragments'], static fn (array $fragment): bool => $fragment['app'] !== 'alpha'));
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame(expected: [], actual: $report['left']);
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
	}//end testAnEntryOfAnAppOutsideThePlanIsKept()

	/**
	 * A field the change wrote where the object had none, and that now reads back null, is back at what the change
	 * replaced: a refresh writes it again instead of reporting it lost.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-that-reads-back-null-is-not-set
	 */
	public function testARefreshWritesAgainAFieldThatReadsBackNull(): void {
		$this->store->offer(register: 'crm', schema: 'ticket');
		$id = $this->store->seed(register: 'crm', schema: 'ticket', data: ['key' => 'afvalpas']);
		$this->ticketChange(set: ['contactRef' => 'c32e7c7a-470d-42e4-9c07-e673bfce516e']);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue(condition: $this->installer->install(plan: $plan, today: $this->today())['ok']);

		$this->store->rows['crm ticket'][$id]['contactRef'] = null;
		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: 'c32e7c7a-470d-42e4-9c07-e673bfce516e', actual: $this->store->rows['crm ticket'][$id]['contactRef']);
		$this->assertSame(expected: 'refreshed', actual: $report['changes']['alpha/ticket-eerst']);
	}//end testARefreshWritesAgainAFieldThatReadsBackNull()
}//end class
