<?php

/**
 * A fragment may put appointments on its objects: checked before anything is
 * written, the event written to the owner's calendar with its invitees and
 * linked, proved, and taken away on remove.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-appointments/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 */
class ExampleSetAppointmentsTest extends ExampleSetTestCase {
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
	 * One case and the appointments given.
	 *
	 * @param array<int, array<string, mixed>> $rows The fragment's appointment rows.
	 *
	 * @return void
	 */
	private function caseWithAppointments(array $rows): void {
		$this->app(app: 'alpha', fragment: [
			'objects'      => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'appointments' => $rows,
		]);
	}//end caseWithAppointments()

	/**
	 * An explanation at the desk.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function appointment(array $extra = []): array {
		return [...[
			'object'    => '{{object:case-1.id}}',
			'owner'     => 'esra.demir',
			'summary'   => 'Toelichting stukken aan de verzoeker',
			'start'     => '2026-10-14T10:00:00+02:00',
			'end'       => '2026-10-14T10:30:00+02:00',
			'location'  => 'Stadskantoor, balie 3',
			'attendees' => [['name' => 'Sanne de Vries', 'email' => 'sanne@demo.example', 'status' => 'invited']],
		], ...$extra];
	}//end appointment()

	/**
	 * The event goes into the owner's calendar with its invitee, is linked to
	 * the case, kept on a second install, and goes on remove.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-appointments/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function testAnAppointmentIsWrittenLinkedOnceAndTakenAway(): void {
		$this->caseWithAppointments(rows: [['key' => 'toelichting', 'data' => self::appointment()]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->calendarObjects, message: 'the check writes no event');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));

		$this->assertSame(expected: [1 => ['principal' => 'principals/users/esra.demir', 'uri' => 'personal']], actual: $this->calendars);
		$ics = array_values($this->calendarObjects)[0];
		$this->assertStringContainsString(needle: 'SUMMARY:Toelichting stukken aan de verzoeker', haystack: $ics);
		$this->assertStringContainsString(needle: 'DTSTART:20261014T080000Z', haystack: $ics);
		$this->assertStringContainsString(needle: 'LOCATION:Stadskantoor\, balie 3', haystack: $ics);
		$invitee = 'ATTENDEE;CN="Sanne de Vries";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:sanne@demo.example';
		$this->assertStringContainsString(needle: $invitee, haystack: $ics);

		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$link   = array_values($this->calendarLinks)[0];
		$this->assertSame(expected: $caseId, actual: $link->getObjectUuid());
		$this->assertSame(expected: 'personal', actual: $link->getCalendarUri());
		$this->assertSame(expected: 'Stadskantoor, balie 3', actual: $link->getLocation());
		$this->assertSame(expected: 'alpha', actual: $link->getLinkedBy());
		$this->assertSame(expected: '2026-10-14 08:00 UTC', actual: $link->getDtstart()->format('Y-m-d H:i T'), message: 'the link holds the moment in UTC');
		$this->assertSame(expected: '2026-10-14 08:30 UTC', actual: $link->getDtend()->format('Y-m-d H:i T'));

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(expectedCount: 1, haystack: $this->calendarObjects, message: 'a kept appointment is written once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: [], actual: $this->calendarObjects);
		$this->assertSame(expected: [], actual: $this->calendarLinks);
	}//end testAnAppointmentIsWrittenLinkedOnceAndTakenAway()

	/**
	 * An existing calendar of the owner is used.
	 *
	 * @return void
	 */
	public function testTheOwnersCalendarIsUsed(): void {
		$this->calendars[7] = ['principal' => 'principals/users/esra.demir', 'uri' => 'personal'];
		$this->caseWithAppointments(rows: [['key' => 'toelichting', 'data' => self::appointment()]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertCount(expectedCount: 1, haystack: $this->calendars);
		$this->assertStringStartsWith(prefix: '7 ', string: array_keys($this->calendarObjects)[0]);
	}//end testTheOwnersCalendarIsUsed()

	/**
	 * An appointment's moment is relative to the install day, so a refresh writes it again on the new day: the old
	 * event and its link go, the new one is linked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-appointments/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function testARefreshMovesTheAppointmentToItsNewDay(): void {
		$this->caseWithAppointments(rows: [['key' => 'toelichting', 'data' => ['start' => '{{datetime:3 10:00}}', 'end' => '{{datetime:3 10:30}}'] + self::appointment()]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today()->modify('+5 days'));

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('refreshed', $report['appointments']['alpha/toelichting']);
		$this->assertCount(1, $this->calendarObjects);
		$this->assertCount(1, $this->calendarLinks);
		$this->assertSame($this->today()->modify('+8 days')->format('Y-m-d'), array_values($this->calendarLinks)[0]->getDtstart()->format('Y-m-d'));
	}//end testARefreshMovesTheAppointmentToItsNewDay()

	/**
	 * An appointment may sit on a task the fragment declares: the link names the task, with the register and schema
	 * of the record the task is on, so the task page's Afspraken card lists it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-appointments/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function testAnAppointmentMaySitOnATask(): void {
		$this->app(app: 'alpha', fragment: [
			'objects'      => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'tasks'        => [['key' => 'hoorzitting', 'data' => [
				'title' => 'Hoorzitting inplannen', 'objectUuid' => '{{object:case-1.id}}', 'state' => 'active', 'assignee' => '{{cast:esra.uid}}',
			]]],
			'appointments' => [['key' => 'hoorzitting', 'data' => ['object' => '{{task:hoorzitting.uuid}}', 'summary' => 'Hoorzitting bezwaar parkeerboete'] + self::appointment()]],
		]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$taskUuid = (string) array_key_first($this->engineTasks);
		$link     = array_values($this->calendarLinks)[0];
		$this->assertSame($taskUuid, $link->getObjectUuid());
		$this->assertSame('Hoorzitting bezwaar parkeerboete', $link->getSummary());
	}//end testAnAppointmentMaySitOnATask()

	/**
	 * A faulty appointment is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedAppointments
	 */
	public function testACheckRefusesAFaultyAppointment(array $data, string $reason): void {
		$this->caseWithAppointments(rows: [['key' => 'bad', 'data' => $data]]);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: $reason, haystack: $refusals[0]);
		$this->assertSame(expected: [], actual: $this->calendarObjects);
	}//end testACheckRefusesAFaultyAppointment()

	/**
	 * The faulty appointments and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedAppointments(): array {
		return [
			'no start'          => [self::appointment(extra: ['start' => '']), 'needs a start'],
			'a stranger owns it' => [self::appointment(extra: ['owner' => 'nobody']), 'owner "nobody" is not an account'],
			'a bad end'         => [self::appointment(extra: ['end' => 'later']), 'is not a date'],
			'no invitee address' => [self::appointment(extra: ['attendees' => [['name' => 'Sanne']]]), 'needs an e-mail address'],
			'an unknown status' => [self::appointment(extra: ['attendees' => [['email' => 'a@b.nl', 'status' => 'maybe']]]), 'status is one of'],
			'an unknown field'  => [self::appointment(extra: ['rrule' => 'FREQ=DAILY']), 'field "rrule"'],
		];
	}//end refusedAppointments()
}//end class
