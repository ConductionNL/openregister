<?php

/**
 * Characterisation tests for ObjectDateDeclaration::fromArray(): the exact kind
 * refusals and every field it reads, pinned before the method was split into
 * named steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Calendar;

use OCA\OpenRegister\Exception\CalendarDateKindException;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * fromArray() refusals and reads, field by field.
 */
class ObjectDateDeclarationFromArrayTest extends TestCase {

	/**
	 * Declarations whose kind is refused, with the exact message.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function refusedKinds(): array {
		return [
			'unknown string' => [['kind' => 'deadlne'], "calendarProvider.dates.due declares kind 'deadlne', which is not a date kind: use deadline, appointment, period."],
			'missing' => [['alarmOffsetDays' => 2], "calendarProvider.dates.due declares kind 'null', which is not a date kind: use deadline, appointment, period."],
			'a number' => [['kind' => 5], "calendarProvider.dates.due declares kind 'int', which is not a date kind: use deadline, appointment, period."],
			'a list' => [['kind' => ['deadline']], "calendarProvider.dates.due declares kind 'array', which is not a date kind: use deadline, appointment, period."],
		];
	}//end refusedKinds()

	/**
	 * A kind that is not one of the date kinds is refused naming the property and the given kind.
	 *
	 * @param array  $config  The declaration.
	 * @param string $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusedKinds')]
	public function testARefusedKindNamesWhatWasGiven(array $config, string $message): void {
		$this->expectException(CalendarDateKindException::class);
		$this->expectExceptionMessage($message);

		ObjectDateDeclaration::fromArray('due', $config);
	}//end testARefusedKindNamesWhatWasGiven()

	/**
	 * An appointment reads its summary, end, length, attendees and calendar.
	 *
	 * @return void
	 */
	public function testAnAppointmentReadsEveryLayoutField(): void {
		$declaration = ObjectDateDeclaration::fromArray(
			'hoorzitting',
			[
				'kind' => 'appointment',
				'summaryTemplate' => 'Hoorzitting {{zaak}}',
				'durationMinutes' => 45,
				'attendeesProperty' => 'deelnemers',
				'calendar' => 'nl-national',
			]
		);

		$this->assertSame(
			['hoorzitting', 'appointment', 'Hoorzitting {{zaak}}', null, null, 45, null, 'nl-national', 'deelnemers'],
			[
				$declaration->property,
				$declaration->kind,
				$declaration->summaryTemplate,
				$declaration->alarmOffsetDays,
				$declaration->endProperty,
				$declaration->durationMinutes,
				$declaration->timerPurpose,
				$declaration->calendarSlug,
				$declaration->attendeesProperty,
			]
		);
	}//end testAnAppointmentReadsEveryLayoutField()

	/**
	 * Non-string optional strings read as null.
	 *
	 * @return void
	 */
	public function testNonStringOptionalStringsReadAsNull(): void {
		$declaration = ObjectDateDeclaration::fromArray(
			'due',
			['kind' => 'deadline', 'summaryTemplate' => 5, 'attendeesProperty' => ['x'], 'calendar' => true]
		);

		$this->assertSame(
			[null, null, null],
			[$declaration->summaryTemplate, $declaration->attendeesProperty, $declaration->calendarSlug]
		);
	}//end testNonStringOptionalStringsReadAsNull()
	/**
	 * Field refusals and dates-block refusals, with the exact message.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function refusedFields(): array {
		return [
			'alarm on an appointment' => [['dates' => ['due' => ['kind' => 'appointment', 'alarmOffsetDays' => 1]]], 'calendarProvider.dates.due declares alarmOffsetDays on a appointment; only a deadline carries an alarm.'],
			'timer on a period' => [['dates' => ['due' => ['kind' => 'period', 'endProperty' => 'end', 'timerPurpose' => 'due']]], 'calendarProvider.dates.due declares timerPurpose on a period; only a deadline is computed by the term engine.'],
			'unknown timer purpose' => [['dates' => ['due' => ['kind' => 'deadline', 'timerPurpose' => 'soon']]], "calendarProvider.dates.due declares timerPurpose 'soon': use due or expiry."],
			'blank property name' => [['dates' => [' ' => ['kind' => 'deadline']]], 'calendarProvider.dates carries an entry with no property name.'],
			'dates not an object' => [['dates' => 'due'], 'calendarProvider.dates must be an object keyed by property name.'],
		];
	}//end refusedFields()

	/**
	 * allFromConfig() refuses an unusable field or dates block with the exact message.
	 *
	 * @param array  $calendarConfig The calendarProvider config.
	 * @param string $message        The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusedFields')]
	public function testARefusedFieldIsNamed(array $calendarConfig, string $message): void {
		$this->expectException(CalendarDateKindException::class);
		$this->expectExceptionMessage($message);

		ObjectDateDeclaration::allFromConfig($calendarConfig);
	}//end testARefusedFieldIsNamed()

	/**
	 * Null dates declare nothing; a deadline with a known purpose and alarm reads both.
	 *
	 * @return void
	 */
	public function testAllFromConfigReadsInOrder(): void {
		$this->assertSame([], ObjectDateDeclaration::allFromConfig(['dates' => null]));

		$declarations = ObjectDateDeclaration::allFromConfig(
			['dates' => ['a' => ['kind' => 'deadline', 'timerPurpose' => ' expiry ', 'alarmOffsetDays' => 0], 'b' => ['kind' => 'appointment']]]
		);

		$this->assertSame(
			[['a', 'deadline', 'expiry', 0], ['b', 'appointment', null, null]],
			array_map(
				static fn (ObjectDateDeclaration $d): array => [$d->property, $d->kind, $d->timerPurpose, $d->alarmOffsetDays],
				$declarations
			)
		);
	}//end testAllFromConfigReadsInOrder()
}//end class
