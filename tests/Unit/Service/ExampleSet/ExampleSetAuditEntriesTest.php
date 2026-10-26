<?php

/**
 * Tests for the example set's audit entries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-audit-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * A fragment gives a case history by a named person, once, marked as seeded, and remove leaves it.
 */
class ExampleSetAuditEntriesTest extends ExampleSetTestCase {

	/**
	 * Set up a case schema and the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}//end setUp()

	/**
	 * A case with the given audit entries.
	 *
	 * @param array<int, array<string, mixed>> $rows The entry rows.
	 *
	 * @return void
	 */
	private function caseWithEntries(array $rows): void {
		$this->app(app: 'alpha', fragment: [
			'objects'      => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'auditEntries' => $rows,
		]);
	}//end caseWithEntries()

	/**
	 * A download of the medical report by the employee.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function download(array $extra = []): array {
		return [...[
			'object'  => '{{object:case-1.id}}',
			'action'  => 'file.downloaded',
			'user'    => '{{cast:esra.uid}}',
			'at'      => '2026-10-06T10:15:00+02:00',
			'changed' => ['fileId' => 7, 'data' => ['fileId' => 7, 'fileName' => 'Medisch verslag']],
		], ...$extra];
	}//end download()

	/**
	 * The entry is by the person, at the moment, with what changed and the seeded cause; a second install writes
	 * nothing, and remove leaves it.
	 *
	 * @return void
	 */
	public function testAnEntryIsWrittenOnceByThePersonAndKept(): void {
		$this->caseWithEntries(rows: [['key' => 'inzage', 'data' => self::download()]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame([], $this->auditTrail, 'the check writes no entry');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$entry = array_values($this->auditTrail)[0];
		$this->assertSame('esra.demir', $entry->getUser());
		$this->assertSame('file.downloaded', $entry->getAction());
		$this->assertSame('Medisch verslag', $entry->getChanged()['data']['fileName']);
		$this->assertSame('2026-10-06T08:15:00+00:00', $entry->getCreated()->setTimezone(new \DateTimeZone('UTC'))->format('c'));
		$this->assertSame(['import', 'example-set:alpha/inzage'], [$entry->getCause(), $entry->getCauseRun()]);
		$this->assertSame($this->store->ids(register: 'cases', schema: 'case')[0], $entry->getObjectUuid());

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(1, $this->auditTrail, 'a kept entry is written once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertCount(1, $this->auditTrail, 'remove leaves the history');
	}//end testAnEntryIsWrittenOnceByThePersonAndKept()

	/**
	 * A faulty entry is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedEntries
	 */
	public function testACheckRefusesAFaultyEntry(array $data, string $reason): void {
		$this->caseWithEntries(rows: [['key' => 'bad', 'data' => $data]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString($reason, $refusals[0]);
		$this->assertSame([], $this->auditTrail);
	}//end testACheckRefusesAFaultyEntry()

	/**
	 * The faulty entries and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedEntries(): array {
		return [
			'no action'      => [self::download(['action' => '']), 'needs an action'],
			'foreign person' => [self::download(['user' => 'nobody']), 'not an account the set creates or adopts'],
			'no moment'      => [self::download(['at' => 'gisteren rond tienen']), 'is not a date and time'],
			'changed a text' => [self::download(['changed' => 'x']), 'changed is an object'],
			'unknown field'  => [self::download(['ip' => '1.2.3.4']), 'not an audit entry field'],
		];
	}//end refusedEntries()
}//end class
