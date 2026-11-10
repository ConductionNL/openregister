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
}//end class
