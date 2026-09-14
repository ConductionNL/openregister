<?php

/**
 * Unit tests for the calendarProvider.dates block on Schema.
 *
 * The regression this file guards is the quiet one: a schema that declares no
 * date kinds must behave exactly as it did before this change, and publish no
 * feed. The refusals are asserted alongside it so a typo in a property name
 * surfaces in the schema editor rather than as an empty agenda weeks later.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use PHPUnit\Framework\TestCase;

class SchemaCalendarDatesTest extends TestCase {

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setTitle('Bezwaren');

		return $schema;
	}

	public function testDeclaredDateKindsRoundTripThroughTheConfiguration(): void {
		$schema = $this->schema();
		$schema->setConfiguration(
			[
				'calendarProvider' => [
					'enabled' => true,
					'dtstart' => 'beslistermijn',
					'titleTemplate' => '{{title}}',
					'dates' => [
						'beslistermijn' => ['kind' => 'deadline', 'alarmOffsetDays' => 7],
					],
				],
			]
		);

		$config = $schema->getCalendarProviderConfig();

		$this->assertNotNull($config);
		$declarations = ObjectDateDeclaration::allFromConfig(calendarConfig: $config);
		$this->assertCount(1, $declarations);
		$this->assertSame(7, $declarations[0]->alarmOffsetDays);
	}

	public function testAnUnknownKindIsRefusedAtSaveNamingTheProperty(): void {
		$schema = $this->schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/calendarProvider\.dates\.beslistermijn/');

		$schema->setConfiguration(
			[
				'calendarProvider' => [
					'enabled' => true,
					'dtstart' => 'beslistermijn',
					'titleTemplate' => '{{title}}',
					'dates' => ['beslistermijn' => ['kind' => 'termijn']],
				],
			]
		);
	}

	public function testDateKindsAreValidatedEvenWhenTheProviderIsOff(): void {
		$schema = $this->schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/calendarProvider\.dates\.hoorzitting/');

		$schema->setConfiguration(
			[
				'calendarProvider' => [
					'enabled' => false,
					'dates' => ['hoorzitting' => ['kind' => 'afspraak']],
				],
			]
		);
	}

	public function testASchemaThatDeclaresNoDatesIsUnchanged(): void {
		$schema = $this->schema();
		$schema->setConfiguration(
			[
				'calendarProvider' => [
					'enabled' => true,
					'dtstart' => 'startDate',
					'titleTemplate' => '{{title}}',
				],
			]
		);

		$config = $schema->getCalendarProviderConfig();

		$this->assertNotNull($config);
		$this->assertSame('startDate', $config['dtstart']);
		$this->assertArrayNotHasKey('dates', $config);
		$this->assertSame([], ObjectDateDeclaration::allFromConfig(calendarConfig: $config));
	}

	public function testAnIncompleteProviderBlockIsStillDroppedRatherThanThrown(): void {
		// Unchanged behaviour, asserted so the date-kind exemption above is
		// visibly narrow: a calendarProvider block that is merely incomplete
		// keeps the per-key isolation the rest of the configuration relies on,
		// and the dropped key is recorded for the mapper to warn about.
		$schema = $this->schema();

		$schema->setConfiguration(['calendarProvider' => ['enabled' => true]]);

		$this->assertNull($schema->getCalendarProviderConfig());
		$this->assertSame(['calendarProvider'], $schema->consumeDroppedAnnotationKeys());
	}
}
