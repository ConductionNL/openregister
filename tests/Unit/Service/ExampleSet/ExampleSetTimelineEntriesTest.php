<?php

/**
 * Tests for the example set's timeline entries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * A fragment gives a case timeline entries by named people, once, and remove deletes them.
 */
class ExampleSetTimelineEntriesTest extends ExampleSetTestCase {

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
	 * A case with the given entries.
	 *
	 * @param array<int, array<string, mixed>> $rows The entry rows.
	 *
	 * @return void
	 */
	private function caseWithEntries(array $rows): void {
		$this->app(app: 'alpha', fragment: [
			'objects'         => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'timelineEntries' => $rows,
		]);
	}//end caseWithEntries()

	/**
	 * A status change by the employee.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function statusChange(array $extra = []): array {
		return [...[
			'object'  => '{{object:case-1.id}}',
			'kind'    => 'statuswijziging',
			'message' => 'Status gewijzigd naar In behandeling',
			'author'  => '{{cast:esra.uid}}',
			'at'      => '2026-10-04T09:12:00+02:00',
			'fields'  => ['to' => 'In behandeling'],
		], ...$extra];
	}//end statusChange()

	/**
	 * The entry is by the person, at the moment, of its kind, written once, and remove deletes it.
	 *
	 * @return void
	 */
	public function testAnEntryIsWrittenOnceByThePersonAndRemoved(): void {
		$this->caseWithEntries(rows: [['key' => 'status-4-okt', 'data' => self::statusChange()]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame([], $this->timelineEntries, 'the check writes no entry');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$entry = array_values($this->timelineEntries)[0];
		$this->assertSame(['esra.demir', 'statuswijziging', 'Status gewijzigd naar In behandeling'], [$entry->getAuthor(), $entry->getKind(), $entry->getMessage()]);
		$this->assertSame('2026-10-04T07:12:00+00:00', $entry->getCreated()->setTimezone(new \DateTimeZone('UTC'))->format('c'));
		$this->assertSame(['to' => 'In behandeling'], $entry->getFields());
		$this->assertSame('example-set:alpha/status-4-okt', $entry->getRawSource());

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(1, $this->timelineEntries, 'a kept entry is written once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame([], $this->timelineEntries);
	}//end testAnEntryIsWrittenOnceByThePersonAndRemoved()

	/**
	 * An entry without an author is the system's, as one written without a session is.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutAnAuthorIsTheSystems(): void {
		$row = self::statusChange(['message' => 'Zaak aangemaakt']);
		unset($row['author']);
		$this->caseWithEntries(rows: [['key' => 'aangemaakt', 'data' => $row]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertNull(array_values($this->timelineEntries)[0]->getAuthor());
	}//end testAnEntryWithoutAnAuthorIsTheSystems()

	/**
	 * An entry of a kind nobody declared is not written, and the install says why.
	 *
	 * @return void
	 */
	public function testAnUndeclaredKindIsNotWritten(): void {
		$this->caseWithEntries(rows: [['key' => 'x', 'data' => self::statusChange(['kind' => 'nergens'])]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertFalse($report['ok']);
		$this->assertStringContainsString('No entry kind named nergens', (string) json_encode($report['dropped']));
		$this->assertSame([], $this->timelineEntries);
	}//end testAnUndeclaredKindIsNotWritten()

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
	}//end testACheckRefusesAFaultyEntry()

	/**
	 * The faulty entries and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedEntries(): array {
		return [
			'no message'     => [self::statusChange(['message' => '']), 'needs a message'],
			'foreign author' => [self::statusChange(['author' => 'nobody']), 'not an account the set creates or adopts'],
			'no moment'      => [self::statusChange(['at' => 'morgen']), 'is not a date and time'],
			'fields a text'  => [self::statusChange(['fields' => 'x']), 'fields is an object'],
			'unknown field'  => [self::statusChange(['pinned' => true]), 'not a timeline entry field'],
		];
	}//end refusedEntries()
}//end class
