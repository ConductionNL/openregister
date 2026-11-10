<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A schema or register referenced by its UUID resolves to its id through a direct
 * lookup, with or without a query string, and in the system scope the
 * referenced-address notifier uses.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class RelationCascadeUuidReferenceTest extends TestCase {
	private const UUID = '21aab6e0-2177-4920-beb0-391492fed04b';

	private function handler(): RelationCascadeHandler {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('person');
		$register = new Register();
		$register->setId(9);
		$register->setSlug('demo');

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			fn (...$args) => (($args['id'] ?? $args[0]) === self::UUID) ? $schema : throw new \OCP\AppFramework\Db\DoesNotExistException('no')
		);
		$schemas->method('findAll')->willReturn([$schema]);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			fn (...$args) => (($args['id'] ?? $args[0]) === self::UUID) ? $register : throw new \OCP\AppFramework\Db\DoesNotExistException('no')
		);
		$registers->method('findAll')->willReturn([$register]);

		return new RelationCascadeHandler(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapperInterface::class)), logger: new NullLogger());
	}//end handler()

	public function testUuidReferencesResolveDirectly(): void {
		$handler = $this->handler();
		$this->assertSame('5', $handler->resolveSchemaReference(self::UUID));
		$this->assertSame('5', $handler->resolveSchemaReference(self::UUID . '?v=1'));
		$this->assertSame('5', $handler->resolveSchemaReference(self::UUID, RelationCascadeHandler::SYSTEM_SCOPE));
		$this->assertSame('9', $handler->resolveRegisterReference(self::UUID));
	}//end testUuidReferencesResolveDirectly()

	public function testUnknownUuidStillFallsBackToSlugs(): void {
		$this->assertNull($this->handler()->resolveSchemaReference('00000000-0000-0000-0000-000000000000'));
	}//end testUnknownUuidStillFallsBackToSlugs()
}//end class
