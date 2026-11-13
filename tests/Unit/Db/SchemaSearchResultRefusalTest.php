<?php

/**
 * A schema save refuses a search result declaration that names a property the schema does not have.
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
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals;
use OCA\OpenRegister\Service\Search\SearchResultDeclaration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the save-time refusal of a search result declaration.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Service\Search\SearchResultDeclaration
 */
final class SchemaSearchResultRefusalTest extends TestCase {

	/**
	 * A schema with two properties and the given declaration.
	 *
	 * @param mixed $declared The declaration, or null to declare none.
	 *
	 * @return Schema The schema.
	 */
	private static function schemaWith(mixed $declared): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties(['identificatie' => ['type' => 'string'], 'registratiedatum' => ['type' => 'string', 'format' => 'date']]);
		if ($declared !== null) {
			$schema->setConfiguration([SearchResultDeclaration::ANNOTATION => $declared]);
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

	public function testADeclarationNamingAnAbsentPropertyFailsTheSave(): void {
		try {
			$this->checks()->checkSearchResult(schema: self::schemaWith(['titleProperty' => 'identificatie', 'sublineProperty' => 'omschrijving']));
			$this->fail('The save went through.');
		} catch (Exception $refusal) {
			$this->assertStringContainsString('x-openregister-search-result.sublineProperty', $refusal->getMessage());
			$this->assertStringContainsString('omschrijving', $refusal->getMessage());
		}
	}//end testADeclarationNamingAnAbsentPropertyFailsTheSave()

	public function testADeclarationThatIsNotAnObjectFailsTheSave(): void {
		$this->expectException(Exception::class);
		$this->checks()->checkSearchResult(schema: self::schemaWith('identificatie'));
	}//end testADeclarationThatIsNotAnObjectFailsTheSave()

	public function testAValidOrAbsentDeclarationSavesAndIsKept(): void {
		$schema = self::schemaWith(['titleProperty' => 'identificatie', 'orderDateProperty' => 'registratiedatum']);
		$this->checks()->checkSearchResult(schema: $schema);
		$this->checks()->checkSearchResult(schema: self::schemaWith(null));

		$this->assertSame(
			['titleProperty' => 'identificatie', 'orderDateProperty' => 'registratiedatum'],
			$schema->getConfiguration()[SearchResultDeclaration::ANNOTATION]
		);
	}//end testAValidOrAbsentDeclarationSavesAndIsKept()
}//end class
