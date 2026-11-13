<?php

/**
 * A schema save refuses a reference card that names a property the schema does not have.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals;
use OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the save-time refusal of a reference card declaration.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration
 */
final class SchemaReferenceCardRefusalTest extends TestCase {

	/**
	 * A schema with one property and the given card block.
	 *
	 * @param mixed $card The declared card, or null to declare none.
	 *
	 * @return Schema The schema.
	 */
	private static function schemaWith(mixed $card): Schema {
		$schema = new Schema();
		$schema->setSlug('pand');
		$schema->setProperties(['adres' => ['type' => 'string']]);
		if ($card !== null) {
			$schema->setConfiguration([ReferenceCardDeclaration::ANNOTATION => $card]);
		}

		return $schema;
	}//end schemaWith()

	/**
	 * The refusals.
	 *
	 * @return RecordAnnotationRefusals The checks.
	 */
	private function checks(): RecordAnnotationRefusals {
		return new RecordAnnotationRefusals(logger: $this->createMock(LoggerInterface::class));
	}//end checks()

	/**
	 * A card naming a property the schema does not have fails the save, naming it.
	 *
	 * @return void
	 */
	public function testACardNamingAnAbsentPropertyFailsTheSave(): void {
		try {
			$this->checks()->checkReferenceCard(schema: self::schemaWith(['titleProperty' => 'straat']));
			$this->fail('The save went through.');
		} catch (Exception $refusal) {
			$this->assertStringContainsString('x-openregister-card.titleProperty', $refusal->getMessage());
			$this->assertStringContainsString('straat', $refusal->getMessage());
		}
	}//end testACardNamingAnAbsentPropertyFailsTheSave()

	/**
	 * A card that is not an object, and one naming four summary properties, fail the save.
	 *
	 * @return void
	 */
	public function testAMalformedCardFailsTheSave(): void {
		$this->expectException(Exception::class);
		$this->checks()->checkReferenceCard(schema: self::schemaWith('nope'));
	}//end testAMalformedCardFailsTheSave()

	/**
	 * A valid card, and a schema declaring none, save.
	 *
	 * @return void
	 */
	public function testAValidOrAbsentCardSaves(): void {
		$this->checks()->checkReferenceCard(schema: self::schemaWith(['titleProperty' => 'adres', 'summary' => ['adres'], 'icon' => 'home']));
		$this->checks()->checkReferenceCard(schema: self::schemaWith(null));

		$this->assertTrue(true, 'Neither save was refused.');
	}//end testAValidOrAbsentCardSaves()
}//end class
