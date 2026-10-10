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
}//end class
