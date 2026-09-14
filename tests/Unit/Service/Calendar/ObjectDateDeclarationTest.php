<?php

/**
 * Unit tests for ObjectDateDeclaration.
 *
 * Covers the schema-save contract: the three kinds are accepted, an unknown
 * kind is refused NAMING THE PROPERTY, and every per-kind constraint is
 * enforced in one place so the validator and the feed cannot disagree.
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use PHPUnit\Framework\TestCase;

class ObjectDateDeclarationTest extends TestCase {

	public function testDeadlineCarriesItsAlarmAndTimerBinding(): void {
		$declaration = ObjectDateDeclaration::fromArray(
			property: 'beslistermijn',
			config: [
				'kind' => 'deadline',
				'alarmOffsetDays' => 7,
				'timerPurpose' => 'due',
				'calendar' => 'nl-national',
				'summaryTemplate' => 'Beslistermijn {{zaaknummer}}',
			]
		);

		$this->assertSame('beslistermijn', $declaration->property);
		$this->assertSame(ObjectDateDeclaration::KIND_DEADLINE, $declaration->kind);
		$this->assertSame(7, $declaration->alarmOffsetDays);
		$this->assertSame('due', $declaration->timerPurpose);
		$this->assertSame('nl-national', $declaration->calendarSlug);
		$this->assertSame('Beslistermijn {{zaaknummer}}', $declaration->summaryTemplate);
	}

	public function testAnUnknownKindIsRefusedNamingTheProperty(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/calendarProvider\.dates\.beslistermijn/');

		ObjectDateDeclaration::fromArray(property: 'beslistermijn', config: ['kind' => 'deadlne']);
	}

	public function testAMissingKindIsRefusedNamingTheProperty(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/calendarProvider\.dates\.hoorzitting/');

		ObjectDateDeclaration::fromArray(property: 'hoorzitting', config: ['alarmOffsetDays' => 2]);
	}

	public function testAPeriodMustDeclareItsEnd(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/bezwaartermijn.*endProperty/s');

		ObjectDateDeclaration::fromArray(property: 'bezwaartermijn', config: ['kind' => 'period']);
	}

	public function testAnAlarmOnAnAppointmentIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/only a deadline carries an alarm/');

		ObjectDateDeclaration::fromArray(
			property: 'hoorzitting',
			config: ['kind' => 'appointment', 'alarmOffsetDays' => 1]
		);
	}

	public function testANegativeAlarmOffsetIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		ObjectDateDeclaration::fromArray(
			property: 'beslistermijn',
			config: ['kind' => 'deadline', 'alarmOffsetDays' => -1]
		);
	}

	public function testAnUnknownTimerPurposeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/timerPurpose/');

		ObjectDateDeclaration::fromArray(
			property: 'beslistermijn',
			config: ['kind' => 'deadline', 'timerPurpose' => 'whenever']
		);
	}

	public function testANonPositiveDurationIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		ObjectDateDeclaration::fromArray(
			property: 'hoorzitting',
			config: ['kind' => 'appointment', 'durationMinutes' => 0]
		);
	}

	public function testASchemaWithNoDatesDeclaresNothing(): void {
		$this->assertSame([], ObjectDateDeclaration::allFromConfig(calendarConfig: ['enabled' => true]));
	}

	public function testEveryDeclarationIsReadInOrder(): void {
		$declarations = ObjectDateDeclaration::allFromConfig(
			calendarConfig: [
				'enabled' => true,
				'dates' => [
					'beslistermijn' => ['kind' => 'deadline'],
					'hoorzitting' => ['kind' => 'appointment', 'durationMinutes' => 90],
					'bezwaartermijn' => ['kind' => 'period', 'endProperty' => 'bezwaarEinde'],
				],
			]
		);

		$this->assertCount(3, $declarations);
		$this->assertSame(
			['beslistermijn', 'hoorzitting', 'bezwaartermijn'],
			array_map(static fn (ObjectDateDeclaration $d): string => $d->property, $declarations)
		);
		$this->assertSame(90, $declarations[1]->durationMinutes);
		$this->assertSame('bezwaarEinde', $declarations[2]->endProperty);
	}

	public function testADatesBlockThatIsNotAnObjectIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		ObjectDateDeclaration::allFromConfig(calendarConfig: ['dates' => 'beslistermijn']);
	}
}
