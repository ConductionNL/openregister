<?php

/**
 * A fragment may ship flows of its app: checked before anything is written,
 * stored switched off and published once, proved, and taken away on remove.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetFlowsTest extends ExampleSetTestCase {
	/**
	 * The cast, no objects.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * A case type flow of three steps with their places on the canvas.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function flow(array $extra = []): array {
		return [...[
			'name'    => 'Omgevingsvergunning regulier',
			'trigger' => 'manual',
			'nodes'   => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'name' => 'Zaak aangemaakt', 'position' => ['x' => 30, 'y' => 30]],
				['id' => 'status', 'type' => 'dossiq.setStatus', 'name' => 'Status: ontvangen', 'position' => ['x' => 30, 'y' => 130]],
				['id' => 'end', 'type' => 'openregister.end', 'position' => ['x' => 30, 'y' => 230]],
			],
			'edges'   => [['from' => 'start', 'to' => 'status'], ['from' => 'status', 'to' => 'end']],
		], ...$extra];
	}//end flow()

	/**
	 * The flow is the shipping app's, switched off, published, written once and
	 * taken away on remove.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 */
	public function testAFlowIsWrittenOnceSwitchedOffAndPublishedAndTakenAwayOnRemove(): void {
		$this->app(app: 'alpha', fragment: ['flows' => [['key' => 'omgevingsvergunning', 'data' => self::flow()]]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->flows, message: 'the check writes no flow');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['alpha/omgevingsvergunning' => 'created'], actual: $report['flows']);

		$flow = array_values($this->flows)[0];
		$this->assertSame(expected: 'alpha', actual: $flow->getApp());
		$this->assertSame(expected: 'Omgevingsvergunning regulier', actual: $flow->getName());
		$this->assertFalse(condition: (bool) $flow->getEnabled(), message: 'an example flow does not run until adopted');
		$this->assertSame(expected: 'org-1', actual: $flow->getOrganisation());
		$this->assertSame(expected: ['x' => 30, 'y' => 130], actual: $flow->getNodes()[1]['position']);
		$this->assertSame(expected: [$flow->getUuid()], actual: $this->publishedFlows);

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(expected: ['alpha/omgevingsvergunning' => 'kept'], actual: $again['flows']);
		$this->assertCount(expectedCount: 1, haystack: $this->flows);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: 1, actual: $removed['removed']['flows']);
		$this->assertSame(expected: [], actual: $this->flows);
	}//end testAFlowIsWrittenOnceSwitchedOffAndPublishedAndTakenAwayOnRemove()

	/**
	 * A change quotes a flow of its fragment by `{{flow:key.uuid}}`: the flow is
	 * written before the changes, so a type can list the flows a handler may
	 * start; a stand-in passes the check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-flow-placeholder/specs/example-sets/spec.md#requirement-a-change-may-quote-a-flow-of-its-fragment
	 */
	public function testAChangeQuotesAFlowOfItsFragment(): void {
		$this->store->offer(register: 'cases', schema: 'caseType');
		$this->app(app: 'alpha', fragment: [
			'objects' => [['key' => 'woo', 'register' => 'cases', 'schema' => 'caseType', 'data' => ['title' => 'Woo-verzoek']]],
			'flows'   => [['key' => 'zienswijze', 'data' => self::flow(extra: ['name' => 'Zienswijze derde vragen'])]],
			'changes' => [[
				'key'    => 'woo-startbaar',
				'register' => 'cases',
				'schema' => 'caseType',
				'object' => 'woo',
				'set'    => ['startableFlows' => ['{{flow:zienswijze.uuid}}']],
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$flow = array_values($this->flows)[0];
		$type = $this->store->find(register: 'cases', schema: 'caseType', filters: [])[0];
		$this->assertSame(expected: [$flow->getUuid()], actual: $type['startableFlows']);
	}//end testAChangeQuotesAFlowOfItsFragment()

	/**
	 * A change quoting a flow its fragment does not declare is refused at check time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-flow-placeholder/specs/example-sets/spec.md#requirement-a-change-may-quote-a-flow-of-its-fragment
	 */
	public function testAChangeQuotingAnUnknownFlowIsRefused(): void {
		$this->store->offer(register: 'cases', schema: 'caseType');
		$this->app(app: 'alpha', fragment: [
			'objects' => [['key' => 'woo', 'register' => 'cases', 'schema' => 'caseType', 'data' => ['title' => 'Woo-verzoek']]],
			'changes' => [[
				'key'      => 'c',
				'register' => 'cases',
				'schema'   => 'caseType',
				'object'   => 'woo',
				'set'      => ['startableFlows' => ['{{flow:nope.uuid}}']],
			]],
		]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];
		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: 'cannot resolve', haystack: $refusals[0]);
	}//end testAChangeQuotingAnUnknownFlowIsRefused()

	/**
	 * A flow that says not to be published stays a draft; one that
	 * disappeared is reported missing.
	 *
	 * @return void
	 */
	public function testADraftFlowIsNotPublishedAndALostOneIsReportedMissing(): void {
		$this->app(app: 'alpha', fragment: ['flows' => [['key' => 'concept', 'data' => self::flow(extra: ['publish' => false])]]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(expected: [], actual: $this->publishedFlows);

		$this->flows = [];
		$report      = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertContains(needle: 'flow alpha/concept', haystack: $report['missing']);
		$this->assertFalse(condition: $report['ok']);
	}//end testADraftFlowIsNotPublishedAndALostOneIsReportedMissing()

	/**
	 * A faulty flow is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedFlows
	 */
	public function testACheckRefusesAFaultyFlowAndWritesNothing(array $data, string $reason): void {
		$this->app(app: 'alpha', fragment: ['flows' => [['key' => 'bad', 'data' => $data]]]);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: '"bad"', haystack: $refusals[0]);
		$this->assertStringContainsString(needle: $reason, haystack: $refusals[0]);
		$this->assertSame(expected: [], actual: $this->flows);
	}//end testACheckRefusesAFaultyFlowAndWritesNothing()

	/**
	 * The faulty flows and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedFlows(): array {
		return [
			'no name'             => [self::flow(extra: ['name' => '']), 'needs a name'],
			'no steps'            => [self::flow(extra: ['nodes' => []]), 'needs its steps'],
			'a step with no type' => [self::flow(extra: ['nodes' => [['id' => 'a']]]), 'an id and a type'],
			'a line to nowhere'   => [self::flow(extra: ['edges' => [['from' => 'start', 'to' => 'ghost']]]), 'from one step'],
			'an owner'            => [self::flow(extra: ['owner' => 'admin']), 'field "owner"'],
			'publish as a word'   => [self::flow(extra: ['publish' => 'ja']), 'publish is true or false'],
		];
	}//end refusedFlows()
}//end class
