<?php

/**
 * Unit tests for ObjectDateEventBuilder.
 *
 * One test per shape the kind decides, plus the two properties a subscribed
 * client depends on: the UID is the same on every read, and a timed event
 * never carries a naive local time.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use OCA\OpenRegister\Service\Calendar\DeadlineDateResolver;
use OCA\OpenRegister\Service\Calendar\IcalendarWriter;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use OCA\OpenRegister\Service\Calendar\ObjectDateEventBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ObjectDateEventBuilderTest extends TestCase {

	private DeadlineDateResolver&MockObject $deadlines;
	private AppointmentAttendeeService&MockObject $attendees;
	private ObjectDateEventBuilder $builder;
	private DateTimeZone $amsterdam;

	protected function setUp(): void {
		parent::setUp();

		$this->deadlines = $this->createMock(DeadlineDateResolver::class);
		$this->attendees = $this->createMock(AppointmentAttendeeService::class);
		$this->amsterdam = new DateTimeZone('Europe/Amsterdam');

		$this->builder = new ObjectDateEventBuilder(
			writer: new IcalendarWriter(),
			deadlines: $this->deadlines,
			attendees: $this->attendees
		);

		$this->attendees->method('responsesByAttendee')->willReturn([]);
	}

	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setOrganisation('org-uuid');
		$object->setUpdated(new DateTime('2026-09-14T10:00:00+02:00'));
		$object->setObject($data);

		return $object;
	}

	private function declaration(string $property, array $config): ObjectDateDeclaration {
		return ObjectDateDeclaration::fromArray(property: $property, config: $config);
	}

	public function testADeadlinePublishesAsAnAllDayEventWithItsAlarm(): void {
		$this->deadlines->method('resolve')
			->willReturn(new DateTimeImmutable('2026-10-20T00:00:00+02:00'));

		$event = $this->builder->build(
			object: $this->object(['title' => 'Bezwaar 2026-114', 'beslistermijn' => '2026-10-20']),
			schemaId: 9,
			declaration: $this->declaration('beslistermijn', ['kind' => 'deadline', 'alarmOffsetDays' => 7]),
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($event);
		$body = implode("\n", $event['lines']);

		$this->assertStringContainsString('DTSTART;VALUE=DATE:20261020', $body);
		// DTEND is exclusive, so a one-day event ends on the 21st.
		$this->assertStringContainsString('DTEND;VALUE=DATE:20261021', $body);
		$this->assertStringContainsString('BEGIN:VALARM', $body);
		$this->assertStringContainsString('TRIGGER;RELATED=START:-P7D', $body);
		$this->assertStringContainsString('SUMMARY:Bezwaar 2026-114', $body);
		$this->assertStringContainsString('CATEGORIES:OpenRegister,DEADLINE', $body);
	}

	public function testADeadlineWithNoDeclaredAlarmCarriesNoAlarm(): void {
		$this->deadlines->method('resolve')
			->willReturn(new DateTimeImmutable('2026-10-20T00:00:00+02:00'));

		$event = $this->builder->build(
			object: $this->object(['beslistermijn' => '2026-10-20']),
			schemaId: 9,
			declaration: $this->declaration('beslistermijn', ['kind' => 'deadline']),
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($event);
		$this->assertStringNotContainsString('BEGIN:VALARM', implode("\n", $event['lines']));
	}

	public function testADeadlineThatResolvesToNothingPublishesNothing(): void {
		$this->deadlines->method('resolve')->willReturn(null);

		$this->assertNull(
			$this->builder->build(
				object: $this->object(['beslistermijn' => '2026-10-20']),
				schemaId: 9,
				declaration: $this->declaration('beslistermijn', ['kind' => 'deadline']),
				timeZone: $this->amsterdam
			)
		);
	}

	public function testTheDeadlineDateComesFromTheResolverNotTheRawValue(): void {
		// The raw value says the 17th; the engine says the 19th.
		$this->deadlines->method('resolve')
			->willReturn(new DateTimeImmutable('2026-10-19T00:00:00+02:00'));

		$event = $this->builder->build(
			object: $this->object(['beslistermijn' => '2026-10-17']),
			schemaId: 9,
			declaration: $this->declaration('beslistermijn', ['kind' => 'deadline']),
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($event);
		$body = implode("\n", $event['lines']);
		$this->assertStringContainsString('DTSTART;VALUE=DATE:20261019', $body);
		$this->assertStringNotContainsString('20261017', $body);
	}

	public function testAnAppointmentCarriesATimeZoneAndItsDuration(): void {
		$event = $this->builder->build(
			object: $this->object(['title' => 'Hoorzitting', 'hoorzitting' => '2026-10-20T14:00:00+02:00']),
			schemaId: 9,
			declaration: $this->declaration('hoorzitting', ['kind' => 'appointment', 'durationMinutes' => 90]),
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($event);
		$body = implode("\n", $event['lines']);

		$this->assertStringContainsString('DTSTART;TZID=Europe/Amsterdam:20261020T140000', $body);
		$this->assertStringContainsString('DTEND;TZID=Europe/Amsterdam:20261020T153000', $body);
		// A naive local time is the defect this asserts against.
		$this->assertSame(0, preg_match('/^DTSTART:\d{8}T\d{6}$/m', $body));
	}

	public function testAnAppointmentWithoutADurationGetsTheDefaultHour(): void {
		$event = $this->builder->build(
			object: $this->object(['hoorzitting' => '2026-10-20T14:00:00+02:00']),
			schemaId: 9,
			declaration: $this->declaration('hoorzitting', ['kind' => 'appointment']),
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($event);
		$this->assertStringContainsString(
			'DTEND;TZID=Europe/Amsterdam:20261020T150000',
			implode("\n", $event['lines'])
		);
	}

	public function testAPeriodSpansItsStartAndEnd(): void {
		$event = $this->builder->build(
			object: $this->object(['bezwaartermijn' => '2026-10-01', 'bezwaarEinde' => '2026-11-12']),
			schemaId: 9,
			declaration: $this->declaration(
				'bezwaartermijn',
				['kind' => 'period', 'endProperty' => 'bezwaarEinde']
			),
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($event);
		$body = implode("\n", $event['lines']);

		$this->assertStringContainsString('DTSTART;VALUE=DATE:20261001', $body);
		$this->assertStringContainsString('DTEND;VALUE=DATE:20261113', $body);
	}

	public function testAnUndeclaredOrAbsentDatePublishesNothing(): void {
		$this->assertNull(
			$this->builder->build(
				object: $this->object(['title' => 'Bezwaar']),
				schemaId: 9,
				declaration: $this->declaration('hoorzitting', ['kind' => 'appointment']),
				timeZone: $this->amsterdam
			)
		);
	}

	public function testTheUidIsTheSameOnEveryRead(): void {
		$declaration = $this->declaration('hoorzitting', ['kind' => 'appointment']);
		$object = $this->object(['hoorzitting' => '2026-10-20T14:00:00+02:00']);

		$first = $this->builder->build(object: $object, schemaId: 9, declaration: $declaration, timeZone: $this->amsterdam);
		$second = $this->builder->build(object: $object, schemaId: 9, declaration: $declaration, timeZone: $this->amsterdam);

		$this->assertNotNull($first);
		$this->assertNotNull($second);

		$uid = 'UID:openregister-9-object-uuid-hoorzitting@openregister.app';
		$this->assertContains($uid, $first['lines']);
		$this->assertSame(
			array_values(array_filter($first['lines'], static fn (string $l): bool => str_starts_with($l, 'UID:'))),
			array_values(array_filter($second['lines'], static fn (string $l): bool => str_starts_with($l, 'UID:')))
		);
	}

	public function testTheUidSurvivesAMovedDate(): void {
		$declaration = $this->declaration('hoorzitting', ['kind' => 'appointment']);

		$before = $this->builder->build(
			object: $this->object(['hoorzitting' => '2026-10-01T14:00:00+02:00']),
			schemaId: 9,
			declaration: $declaration,
			timeZone: $this->amsterdam
		);
		$after = $this->builder->build(
			object: $this->object(['hoorzitting' => '2026-10-15T14:00:00+02:00']),
			schemaId: 9,
			declaration: $declaration,
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($before);
		$this->assertNotNull($after);
		$this->assertContains('UID:openregister-9-object-uuid-hoorzitting@openregister.app', $before['lines']);
		$this->assertContains('UID:openregister-9-object-uuid-hoorzitting@openregister.app', $after['lines']);
		$this->assertStringContainsString('20261015T140000', implode("\n", $after['lines']));
		$this->assertStringNotContainsString('20261001T140000', implode("\n", $after['lines']));
	}

	public function testAnAppointmentPublishesTheAnswersRecordedOnTheObject(): void {
		$attendees = $this->createMock(AppointmentAttendeeService::class);
		$attendees->method('responsesByAttendee')->willReturn(
			[
				'ana@example.org' => ['attendee' => 'ana@example.org', 'status' => 'ACCEPTED'],
				'bram@example.org' => ['attendee' => 'bram@example.org', 'status' => 'DECLINED'],
			]
		);

		$builder = new ObjectDateEventBuilder(
			writer: new IcalendarWriter(),
			deadlines: $this->deadlines,
			attendees: $attendees
		);

		$event = $builder->build(
			object: $this->object(
				[
					'hoorzitting' => '2026-10-20T14:00:00+02:00',
					'genodigden' => ['ana@example.org', 'bram@example.org', 'chris@example.org'],
				]
			),
			schemaId: 9,
			declaration: $this->declaration(
				'hoorzitting',
				['kind' => 'appointment', 'attendeesProperty' => 'genodigden']
			),
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($event);
		$body = implode("\n", $event['lines']);

		$this->assertStringContainsString('ATTENDEE;PARTSTAT=ACCEPTED:mailto:ana@example.org', $body);
		$this->assertStringContainsString('ATTENDEE;PARTSTAT=DECLINED:mailto:bram@example.org', $body);
		$this->assertStringContainsString('ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:chris@example.org', $body);
	}

	public function testASummaryTemplateIsInterpolatedFromTheObject(): void {
		$this->deadlines->method('resolve')
			->willReturn(new DateTimeImmutable('2026-10-20T00:00:00+02:00'));

		$event = $this->builder->build(
			object: $this->object(['zaaknummer' => 'Z-2026-114', 'beslistermijn' => '2026-10-20']),
			schemaId: 9,
			declaration: $this->declaration(
				'beslistermijn',
				['kind' => 'deadline', 'summaryTemplate' => 'Beslistermijn {{zaaknummer}}']
			),
			timeZone: $this->amsterdam
		);

		$this->assertNotNull($event);
		$this->assertStringContainsString('SUMMARY:Beslistermijn Z-2026-114', implode("\n", $event['lines']));
	}
}
