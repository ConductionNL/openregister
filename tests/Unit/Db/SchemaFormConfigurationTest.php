<?php

/**
 * The two configuration keys the form validator reads survive a save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * An unknown configuration key is dropped in silence; these two must not be.
 *
 * @covers \OCA\OpenRegister\Db\Schema
 */
class SchemaFormConfigurationTest extends TestCase {

	/**
	 * `staging` and `additionalProperties` round-trip as booleans.
	 */
	public function testStagingAndAdditionalPropertiesAreKept(): void {
		$schema = new Schema();
		$schema->setConfiguration(['staging' => true, 'additionalProperties' => true]);

		$configuration = $schema->getConfiguration();
		$this->assertTrue($configuration['staging'] ?? null);
		$this->assertTrue($configuration['additionalProperties'] ?? null);
	}//end testStagingAndAdditionalPropertiesAreKept()

	/**
	 * `draftLifetimeDays` (decision 216) is kept as a whole number of days, beside the other keys.
	 */
	public function testTheDraftLifetimeIsKept(): void {
		$schema = new Schema();
		$schema->setConfiguration(['draftLifetimeDays' => 7, 'draftsVisible' => true]);
		$this->assertSame(['draftLifetimeDays' => 7, 'draftsVisible' => true], $schema->getConfiguration());

		// A register file written by hand may carry the number as text.
		$schema->setConfiguration('{"draftLifetimeDays": "30"}');
		$this->assertSame(30, $schema->getConfiguration()['draftLifetimeDays'] ?? null);

		// It survives the serialisation an export and an API read are built from.
		$this->assertSame(30, $schema->jsonSerialize()['configuration']['draftLifetimeDays'] ?? null);

		// Control: a key nobody declared is still dropped, which is why this one had to be declared.
		$schema->setConfiguration(['draftLifetimeDays' => 7, 'draftLifetimeDay' => 7]);
		$this->assertSame(['draftLifetimeDays' => 7], $schema->getConfiguration());
	}//end testTheDraftLifetimeIsKept()

	/**
	 * A lifetime that is not a whole number of days, one or more, is refused and reported; the rest is kept.
	 *
	 * @dataProvider refusedLifetimes
	 */
	public function testAnUnusableDraftLifetimeIsDroppedAndReported(mixed $value): void {
		$schema = new Schema();
		$schema->setConfiguration(['draftLifetimeDays' => $value, 'staging' => true]);

		// Never stored: zero or a negative number must not read as "remove every draft now".
		$this->assertSame(['staging' => true], $schema->getConfiguration());
		// Reported, so SchemaMapper logs it instead of dropping it without a word.
		$this->assertSame(['draftLifetimeDays'], $schema->consumeDroppedAnnotationKeys());
	}//end testAnUnusableDraftLifetimeIsDroppedAndReported()

	/**
	 * Values that are no lifetime.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedLifetimes(): array {
		return [
			'zero' => [0],
			'negative' => [-7],
			'a fraction' => [1.5],
			'text' => ['a week'],
			'zero as text' => ['0'],
			'a leading zero' => ['07'],
			'true' => [true],
			'a list' => [[7]],
		];
	}//end refusedLifetimes()

	/**
	 * Null means "no lifetime": nothing is stored and nothing is reported.
	 */
	public function testANullDraftLifetimeSetsNone(): void {
		$schema = new Schema();
		$schema->setConfiguration(['draftLifetimeDays' => null, 'staging' => true]);

		$this->assertSame(['staging' => true], $schema->getConfiguration());
		$this->assertSame([], $schema->consumeDroppedAnnotationKeys());
	}//end testANullDraftLifetimeSetsNone()
}//end class
