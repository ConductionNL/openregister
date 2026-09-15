<?php

/**
 * Unit tests for SchemaMappingCheck — a saved mapping meets its schema.
 *
 * The mapping validator deliberately cannot see a schema, so a mapping
 * authored last month against a property the schema has since lost passes
 * every structural check and then writes onto a property that is not there.
 * These tests hold the line at the moment the mapping is bound to a schema.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Import\SchemaMappingCheck;
use PHPUnit\Framework\TestCase;

final class SchemaMappingCheckTest extends TestCase {

	/**
	 * A schema with the three properties these cases map onto.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'naam' => ['type' => 'string'],
				'geboortedatum' => ['type' => 'string'],
				'adres' => ['type' => 'object'],
			]
		);

		return $schema;
	}

	public function testAMappingThatFitsTheSchemaNamesNothing(): void {
		$definition = [
			'fieldMappings' => [
				['source' => 'Achternaam', 'target' => 'naam'],
				['source' => 'Geboren', 'target' => 'geboortedatum'],
			],
		];

		$this->assertSame([], SchemaMappingCheck::unknownTargets($definition, $this->schema()));
	}

	public function testAnUnknownPropertyIsNamed(): void {
		$definition = [
			'fieldMappings' => [
				['source' => 'Achternaam', 'target' => 'naam'],
				['source' => 'BSN', 'target' => 'burgerservicenummer'],
			],
		];

		$this->assertSame(
			['burgerservicenummer'],
			SchemaMappingCheck::unknownTargets($definition, $this->schema())
		);
	}

	public function testADefaultOnAnUnknownPropertyIsAlsoNamed(): void {
		$definition = [
			'fieldMappings' => [['source' => 'Achternaam', 'target' => 'naam']],
			'defaults' => ['herkomst' => 'migratie'],
		];

		$this->assertSame(['herkomst'], SchemaMappingCheck::unknownTargets($definition, $this->schema()));
	}

	/**
	 * A nested target lands inside a property the schema does have, so it is
	 * the root that is checked. Flagging `adres.straat` would refuse every
	 * mapping onto an object property.
	 *
	 * @return void
	 */
	public function testANestedTargetIsJudgedByItsRootProperty(): void {
		$definition = [
			'fieldMappings' => [
				['source' => 'Straat', 'target' => 'adres.straat'],
				['source' => 'Plaats', 'target' => 'woonplaats.naam'],
			],
		];

		$this->assertSame(['woonplaats.naam'], SchemaMappingCheck::unknownTargets($definition, $this->schema()));
	}

	/**
	 * The object's own identifier and the `@self` box are not schema
	 * properties, and never were.
	 *
	 * @return void
	 */
	public function testTheIdentifierAndTheSelfBoxAreNotUnknownProperties(): void {
		$definition = [
			'fieldMappings' => [
				['source' => 'Id', 'target' => 'id'],
				['source' => 'Eigenaar', 'target' => '@self.owner'],
			],
		];

		$this->assertSame([], SchemaMappingCheck::unknownTargets($definition, $this->schema()));
	}

	/**
	 * A schema that lists no properties makes no claim about what exists, so
	 * calling any target unknown against it would be an invented refusal.
	 *
	 * @return void
	 */
	public function testASchemaWithoutPropertiesRefusesNothing(): void {
		$schema = new Schema();
		$schema->setProperties([]);

		$definition = ['fieldMappings' => [['source' => 'BSN', 'target' => 'burgerservicenummer']]];

		$this->assertSame([], SchemaMappingCheck::unknownTargets($definition, $schema));
	}
}
