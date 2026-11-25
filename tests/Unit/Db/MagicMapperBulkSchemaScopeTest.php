<?php

/**
 * A bulk save's schema slug resolves inside the bulk save's register.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class MagicMapperBulkSchemaScopeTest extends TestCase {

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
	}

	private function findBulkSchema(mixed $id, ?Register $register): ?Schema {
		$class = new ReflectionClass(MagicMapper::class);
		$mapper = $class->newInstanceWithoutConstructor();
		$class->getProperty('schemaMapper')->setValue($mapper, $this->schemas);

		return $class->getMethod('findBulkSchema')->invoke($mapper, $id, $register);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}

	public function testABulkSlugResolvesAmongTheRegistersSchemas(): void {
		$register = new Register();
		$register->setSchemas([36]);
		$this->schemas->expects($this->once())->method('findBySlugInIds')->with('agent', [36])->willReturn(self::schema(36));
		$this->schemas->expects($this->never())->method('find');

		$this->assertSame(36, $this->findBulkSchema('agent', $register)?->getId());
	}

	public function testABulkIdStillResolvesGloballyOutsideTheTenantFilter(): void {
		$register = new Register();
		$register->setSchemas([36]);
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->schemas->expects($this->once())->method('find')->with(22, [], true, false)->willReturn(self::schema(22));

		$this->assertSame(22, $this->findBulkSchema(22, $register)?->getId());
	}

	public function testNoSchemaInTheBulkSaveIsNull(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertNull($this->findBulkSchema(null, null));
	}
}
