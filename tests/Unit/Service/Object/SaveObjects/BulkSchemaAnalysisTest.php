<?php

/**
 * What the bulk save reads from a schema once: the configured metadata
 * fields, which properties carry an inverse relation (own or on items) and
 * whether it writes back, and the validation flag.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSchemaAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see BulkSchemaAnalysis}.
 */
class BulkSchemaAnalysisTest extends TestCase {

	/**
	 * Only configured, non-empty metadata fields are kept, under their role.
	 *
	 * @return void
	 */
	public function testOnlyConfiguredMetadataFieldsAreKept(): void {
		$schema = new Schema();
		$schema->setConfiguration(['objectNameField' => 'title', 'objectSummaryField' => '', 'objectSlugField' => 'code', 'other' => 'x']);
		$schema->setProperties([]);
		$schema->setHardValidation(true);

		$analysis = (new BulkSchemaAnalysis())->analyse(schema: $schema);

		$this->assertSame(['name' => 'title', 'slug' => 'code'], $analysis['metadataFields']);
		$this->assertTrue($analysis['validationRequired']);
		$this->assertSame([], $analysis['inverseProperties']);
	}//end testOnlyConfiguredMetadataFieldsAreKept()

	/**
	 * Inverse relations are read from the property or its items, with
	 * write-back from either level, and plain or scalar properties skipped.
	 *
	 * @return void
	 */
	public function testInverseRelationsAreReadFromThePropertyOrItsItems(): void {
		$schema = new Schema();
		$schema->setConfiguration(null);
		$schema->setProperties(
			[
				'plain' => ['type' => 'string'],
				'scalar' => 'string',
				'owner' => ['type' => 'string', 'inversedBy' => 'cases', 'writeBack' => ' TRUE '],
				'members' => ['type' => 'array', 'writeBack' => 'false', 'items' => ['inversedBy' => 'teams', 'writeBack' => 1]],
				'tags' => ['type' => 'array', 'items' => ['inversedBy' => 'tagged']],
				'listed' => ['type' => 'array', 'items' => ['type' => 'string']],
			]
		);

		$inverse = (new BulkSchemaAnalysis())->analyse(schema: $schema)['inverseProperties'];

		$this->assertSame(
			[
				'owner' => ['inversedBy' => 'cases', 'writeBack' => true, 'isArray' => false],
				'members' => ['inversedBy' => 'teams', 'writeBack' => true, 'isArray' => true],
				'tags' => ['inversedBy' => 'tagged', 'writeBack' => false, 'isArray' => true],
			],
			$inverse
		);
	}//end testInverseRelationsAreReadFromThePropertyOrItsItems()
}//end class
