<?php

/**
 * A schema save refuses a team-page declaration that names no property, or one the schema does not have.
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
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals;
use OCA\OpenRegister\Reference\TeamResourceDeclaration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the save-time refusal of a team-page declaration.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Reference\TeamResourceDeclaration
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
final class SchemaTeamResourcesRefusalTest extends TestCase {

	/**
	 * A schema with one property and the given declaration.
	 *
	 * @param mixed $block The declared block, or null to declare none.
	 *
	 * @return Schema The schema.
	 */
	private static function schemaWith(mixed $block): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties(['afdeling' => ['type' => 'string']]);
		if ($block !== null) {
			$schema->setConfiguration([TeamResourceDeclaration::ANNOTATION => $block]);
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
	 * A declaration naming a property the schema does not have fails the save, naming it.
	 *
	 * @return void
	 */
	public function testADeclarationNamingAnAbsentPropertyFailsTheSave(): void {
		try {
			$this->checks()->checkTeamResources(schema: self::schemaWith(['teamProperty' => 'team']));
			$this->fail('The save went through.');
		} catch (Exception $refusal) {
			$this->assertStringContainsString('x-openregister-team-resources.teamProperty', $refusal->getMessage());
			$this->assertStringContainsString('team', $refusal->getMessage());
		}
	}//end testADeclarationNamingAnAbsentPropertyFailsTheSave()

	/**
	 * A declaration that is not an object, or names no property, fails the save.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationFailsTheSave(): void {
		foreach (['afdeling', ['teamProperty' => '']] as $block) {
			try {
				$this->checks()->checkTeamResources(schema: self::schemaWith($block));
				$this->fail('The save went through.');
			} catch (Exception $refusal) {
				$this->assertStringContainsString('x-openregister-team-resources', $refusal->getMessage());
			}
		}
	}//end testAMalformedDeclarationFailsTheSave()

	/**
	 * A valid declaration, and a schema declaring none, save.
	 *
	 * @return void
	 */
	public function testAValidOrAbsentDeclarationSaves(): void {
		$this->checks()->checkTeamResources(schema: self::schemaWith(['teamProperty' => 'afdeling']));
		$this->checks()->checkTeamResources(schema: self::schemaWith(null));

		$this->assertTrue(true, 'Neither save was refused.');
	}//end testAValidOrAbsentDeclarationSaves()
}//end class
