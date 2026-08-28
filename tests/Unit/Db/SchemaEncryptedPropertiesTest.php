<?php

/**
 * Unit tests for Schema::hasEncryptedProperties() / getEncryptedProperties().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

class SchemaEncryptedPropertiesTest extends TestCase {
	public function testHasEncryptedPropertiesFalseByDefault(): void {
		$schema = new Schema();
		$schema->setProperties(['name' => ['type' => 'string']]);

		$this->assertFalse($schema->hasEncryptedProperties());
		$this->assertSame([], $schema->getEncryptedProperties());
	}

	public function testHasEncryptedPropertiesTrueWhenFlagged(): void {
		$schema = new Schema();
		$schema->setProperties([
			'name' => ['type' => 'string'],
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
		]);

		$this->assertTrue($schema->hasEncryptedProperties());
		$this->assertSame(['bsn'], $schema->getEncryptedProperties());
	}

	public function testGetEncryptedPropertiesReturnsAllFlaggedNames(): void {
		$schema = new Schema();
		$schema->setProperties([
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
			'medical' => ['type' => 'string', 'x-openregister-encrypted' => true],
			'name' => ['type' => 'string'],
		]);

		$this->assertSame(['bsn', 'medical'], $schema->getEncryptedProperties());
	}

	public function testFlagMustBeExactlyTrue(): void {
		$schema = new Schema();
		$schema->setProperties([
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => 'true'],
		]);

		$this->assertFalse(
			$schema->hasEncryptedProperties(),
			'A truthy-but-not-boolean-true value must not flag the property (matches writeOnly convention)'
		);
	}

	public function testEmptyPropertiesReturnsFalseAndEmptyArray(): void {
		$schema = new Schema();

		$this->assertFalse($schema->hasEncryptedProperties());
		$this->assertSame([], $schema->getEncryptedProperties());
	}

	public function testNonArrayPropertyConfigIsIgnored(): void {
		$schema = new Schema();
		$schema->setProperties(['weird' => 'not-an-array']);

		$this->assertFalse($schema->hasEncryptedProperties());
	}
}
