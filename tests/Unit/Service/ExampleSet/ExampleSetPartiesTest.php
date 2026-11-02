<?php

/**
 * A fragment may put parties on its objects: checked before anything is
 * written, linked once through the party model's trusted path, proved, and
 * unlinked on remove.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetPartiesTest extends ExampleSetTestCase {
	/**
	 * A case register and the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * One case, and the party links given.
	 *
	 * @param array<int, array<string, mixed>> $parties The fragment's party rows.
	 *
	 * @return void
	 */
	private function caseWithParties(array $parties): void {
		$this->app(app: 'alpha', fragment: [
			'objects' => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'parties' => $parties,
		]);
	}//end caseWithParties()

	/**
	 * A representative with a mandate is linked once by the app, kept on a second install and unlinked on remove.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
	 */
	public function testARepresentativeIsLinkedOnceAndUnlinkedOnRemove(): void {
		$this->caseWithParties(parties: [
			['key' => 'gemachtigde', 'data' => [
				'objectUuid' => '{{object:case-1.id}}', 'partyUuid' => 'party-vanwijk', 'role' => 'gemachtigde',
				'validFrom' => '{{date:-10}}', 'validUntil' => '2026-12-31', 'note' => 'Awb 2:1',
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->partyLinks, message: 'the check links no party');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['alpha/gemachtigde' => 'created'], actual: $report['parties']);

		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$link   = ($this->partyLinks[$caseId.' party-vanwijk gemachtigde'] ?? null);
		$this->assertNotNull(actual: $link);
		$this->assertSame(expected: 'alpha', actual: $link['linkedBy']);
		$this->assertSame(expected: '2026-12-31', actual: $link['payload']['validUntil']);
		$this->assertSame(expected: 7, actual: $link['registerId']);
		$this->assertArrayNotHasKey(key: 'objectUuid', array: $link['payload']);

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(expected: ['alpha/gemachtigde' => 'kept'], actual: $again['parties']);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: 1, actual: $removed['removed']['parties']);
		$this->assertSame(expected: [], actual: $this->partyLinks);
	}//end testARepresentativeIsLinkedOnceAndUnlinkedOnRemove()

	/**
	 * A refresh writes a held link again where it is, so what the link caches of the party (its name and e-mail
	 * address) follows the party, and a changed note arrives.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-a-party-link-again-where-it-is
	 */
	public function testARefreshWritesTheLinkAgainWhereItIs(): void {
		$row = ['objectUuid' => '{{object:case-1.id}}', 'partyUuid' => 'party-vanwijk', 'role' => 'gemachtigde', 'note' => 'Awb 2:1'];
		$this->caseWithParties(parties: [['key' => 'gemachtigde', 'data' => $row]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->caseWithParties(parties: [['key' => 'gemachtigde', 'data' => ['note' => 'Machtiging tot 31 december'] + $row]]);
		$report = $this->installer->install(plan: ($this->catalogue->gather(set: 'demo') + ['refresh' => true]), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('refreshed', $report['parties']['alpha/gemachtigde']);
		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$this->assertCount(1, $this->partyLinks);
		$this->assertSame('Machtiging tot 31 december', $this->partyLinks[$caseId.' party-vanwijk gemachtigde']['payload']['note']);
	}//end testARefreshWritesTheLinkAgainWhereItIs()

	/**
	 * A link the party model refuses is not written and the install says why.
	 *
	 * @return void
	 */
	public function testALinkThePartyModelRefusesIsDropped(): void {
		$this->caseWithParties(
			parties: [['key' => 'p', 'data' => ['objectUuid' => '{{object:case-1.id}}', 'partyUuid' => 'not-a-party', 'role' => 'melder']]]
		);
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: [], actual: $this->partyLinks);
		$this->assertStringContainsString(needle: 'is not a party', haystack: implode("\n", $report['dropped']));
	}//end testALinkThePartyModelRefusesIsDropped()

	/**
	 * A faulty party link is refused at check time and nothing is linked.
	 *
	 * @param array<string, mixed> $data   The link's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedParties
	 */
	public function testACheckRefusesAFaultyLinkAndLinksNothing(array $data, string $reason): void {
		$this->caseWithParties(parties: [['key' => 'bad', 'data' => $data]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: 'party "bad"', haystack: $refusals[0]);
		$this->assertStringContainsString(needle: $reason, haystack: $refusals[0]);
		$this->assertSame(expected: [], actual: $this->partyLinks);
	}//end testACheckRefusesAFaultyLinkAndLinksNothing()

	/**
	 * The faulty links and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedParties(): array {
		$on = '{{object:case-1.id}}';
		return [
			'no party'          => [['objectUuid' => $on, 'role' => 'melder'], '(partyUuid)'],
			'no role'           => [['objectUuid' => $on, 'partyUuid' => 'p'], '(role)'],
			'no object'         => [['partyUuid' => 'p', 'role' => 'melder'], '(objectUuid)'],
			'not a date'        => [
				['objectUuid' => $on, 'partyUuid' => 'p', 'role' => 'melder', 'validUntil' => 'soon'],
				'validUntil "soon" is not a date',
			],
			'another field'     => [['objectUuid' => $on, 'partyUuid' => 'p', 'role' => 'melder', 'kind' => 'x'], 'field "kind"'],
			'an unknown object' => [['objectUuid' => '{{object:nope.id}}', 'partyUuid' => 'p', 'role' => 'melder'], 'cannot resolve'],
		];
	}//end refusedParties()
}//end class
