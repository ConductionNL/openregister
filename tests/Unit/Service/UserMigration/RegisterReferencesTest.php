<?php

/**
 * Register and schema references travel as slugs and resolve back to the target's ids.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\UserMigration
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\UserMigration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\UserMigration\RegisterReferences;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\UserMigration\RegisterReferences
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class RegisterReferencesTest extends TestCase {

	private function references(): RegisterReferences {
		$register = new Register();
		$register->setId(3);
		$register->setSlug('zaken');
		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('zaak');

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id, bool $_rbac = true, bool $_multitenancy = true) use ($register): Register {
				if (in_array((string)$id, ['3', 'zaken'], true) === false || $_rbac !== false || $_multitenancy !== false) {
					throw new DoesNotExistException('no register');
				}

				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($schema): Schema {
				if (in_array((string)$id, ['9', 'zaak'], true) === false || $_rbac !== false || $_multitenancy !== false) {
					throw new DoesNotExistException('no schema');
				}

				return $schema;
			}
		);

		return new RegisterReferences(registers: $registers, schemas: $schemas);
	}//end references()

	/**
	 * An id becomes the slug, read as the system; an unknown reference stays as it was.
	 */
	public function testAnIdTravelsAsItsSlug(): void {
		$references = $this->references();

		$this->assertSame('zaken', $references->portable(kind: RegisterReferences::REGISTER, reference: 3));
		$this->assertSame('zaak', $references->portable(kind: RegisterReferences::SCHEMA, reference: '9'));
		$this->assertSame('42', $references->portable(kind: RegisterReferences::REGISTER, reference: 42));
	}//end testAnIdTravelsAsItsSlug()

	/**
	 * A slug resolves to this instance's id; one this instance lacks resolves to nothing.
	 */
	public function testASlugResolvesToTheLocalIdOrNothing(): void {
		$references = $this->references();

		$this->assertSame('3', $references->local(kind: RegisterReferences::REGISTER, reference: 'zaken'));
		$this->assertSame('9', $references->local(kind: RegisterReferences::SCHEMA, reference: 'zaak'));
		$this->assertNull($references->local(kind: RegisterReferences::REGISTER, reference: 'bezwaar'));
		$this->assertNull($references->local(kind: RegisterReferences::SCHEMA, reference: ''));
	}//end testASlugResolvesToTheLocalIdOrNothing()
}//end class
