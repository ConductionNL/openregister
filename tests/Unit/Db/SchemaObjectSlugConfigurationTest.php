<?php

/**
 * The object-slug configuration keys survive schema save and import (#4037).
 *
 * Before #4037 `objectSlugField` was missing from the allowlist, so every
 * save and import dropped it and slugs could only be switched on in the
 * database.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-schema-configuration-must-accept-and-persist-the-object-slug-keys
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for objectSlugField / objectSlugSuffix in Schema::setConfiguration().
 */
class SchemaObjectSlugConfigurationTest extends TestCase {

	public function testBothKeysArePersisted(): void {
		$schema = new Schema();
		$schema->setConfiguration(['objectSlugField' => 'kenmerk', 'objectSlugSuffix' => true]);

		$this->assertSame('kenmerk', $schema->getConfiguration()['objectSlugField']);
		$this->assertTrue($schema->getConfiguration()['objectSlugSuffix']);
	}

	public function testAnEmptySlugFieldIsStoredAsNull(): void {
		$schema = new Schema();
		$schema->setConfiguration(['objectSlugField' => '']);

		$this->assertArrayHasKey('objectSlugField', $schema->getConfiguration());
		$this->assertNull($schema->getConfiguration()['objectSlugField']);
	}

	public function testAnInvalidSuffixIsDroppedAloneAndTheRestIsKept(): void {
		$schema = new Schema();
		$schema->setConfiguration(
			['objectSlugField' => 'kenmerk', 'objectSlugSuffix' => 'yes', 'objectNameField' => 'titel']
		);

		$configuration = $schema->getConfiguration();
		$this->assertArrayNotHasKey('objectSlugSuffix', $configuration);
		$this->assertSame('kenmerk', $configuration['objectSlugField']);
		$this->assertSame('titel', $configuration['objectNameField']);
	}

	public function testAConfigurationWithoutTheKeysIsUnchanged(): void {
		$schema = new Schema();
		$schema->setConfiguration(['objectNameField' => 'titel']);

		$this->assertSame(['objectNameField' => 'titel'], $schema->getConfiguration());
	}

	public function testTheKeysSurviveAnImportThroughHydrate(): void {
		$schema = new Schema();
		$schema->hydrate(['title' => 'Convenanten', 'configuration' => ['objectSlugField' => 'kenmerk']]);

		$this->assertSame('kenmerk', $schema->getConfiguration()['objectSlugField']);
	}
}//end class
