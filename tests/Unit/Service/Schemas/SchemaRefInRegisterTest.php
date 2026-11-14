<?php

/**
 * A schema reference resolved inside the register in context.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Schemas
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
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Schemas\SchemaRefInRegister;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class SchemaRefInRegisterTest extends TestCase {

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var RegisterMapperInterface&MockObject */
	private RegisterMapperInterface $registers;

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');

		return $schema;
	}

	private static function register(array $schemaIds): Register {
		$register = new Register();
		$register->setSchemas($schemaIds);

		return $register;
	}

	public function testASlugResolvesInsideTheRegisterEntity(): void {
		$this->schemas->expects($this->once())->method('findBySlugInIds')
			->with('agent', [36, 37])->willReturn(self::schema(36));
		$this->schemas->expects($this->never())->method('find');

		$found = (new SchemaRefInRegister(schemas: $this->schemas))->find(schemaRef: 'agent', register: self::register([36, 37]));

		$this->assertSame(36, $found->getId());
	}

	public function testARegisterReferenceIsReadWithTheCallersFlags(): void {
		$this->registers->expects($this->once())->method('find')
			->with('hermiq', false, false)->willReturn(self::register([36]));
		$this->schemas->expects($this->once())->method('findBySlugInIds')
			->with('agent', [36])->willReturn(self::schema(36));

		$found = (new SchemaRefInRegister(schemas: $this->schemas, registers: $this->registers))
			->find(schemaRef: 'agent', register: 'hermiq', _rbac: false, _multitenancy: false);

		$this->assertSame(36, $found->getId());
	}

	public function testASlugTheRegisterLacksFallsBackToTheGlobalLookupWithTheFlags(): void {
		$this->schemas->method('findBySlugInIds')->willReturn(null);
		$this->schemas->expects($this->once())->method('find')
			->with('agent', [], false, true)->willReturn(self::schema(22));

		$found = (new SchemaRefInRegister(schemas: $this->schemas))
			->find(schemaRef: 'agent', register: self::register([36]), _rbac: false);

		$this->assertSame(22, $found->getId());
	}

	public function testANumericIdNeverConsultsTheRegister(): void {
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->schemas->expects($this->once())->method('find')->with('36')->willReturn(self::schema(36));

		$found = (new SchemaRefInRegister(schemas: $this->schemas, registers: $this->registers))->find(schemaRef: '36', register: 'hermiq');

		$this->assertSame(36, $found->getId());
	}

	public function testAnUnreadableRegisterLeavesTheGlobalLookup(): void {
		$this->registers->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->schemas->expects($this->once())->method('find')->with('agent')->willReturn(self::schema(22));

		$found = (new SchemaRefInRegister(schemas: $this->schemas, registers: $this->registers))->find(schemaRef: 'agent', register: 'missing');

		$this->assertSame(22, $found->getId());
	}

	public function testNoRegisterReaderOrANonScalarReferenceScopesNothing(): void {
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->schemas->expects($this->exactly(2))->method('find')->with('agent')->willReturn(self::schema(22));

		$this->assertSame(22, (new SchemaRefInRegister(schemas: $this->schemas))->find(schemaRef: 'agent', register: 'hermiq')->getId());
		$this->assertSame(
			22,
			(new SchemaRefInRegister(schemas: $this->schemas, registers: $this->registers))->find(schemaRef: 'agent', register: ['hermiq'])->getId()
		);
	}

	public function testTheGlobalLookupsMissingSchemaStillThrows(): void {
		$this->schemas->method('findBySlugInIds')->willReturn(null);
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('no schema'));

		$this->expectException(DoesNotExistException::class);
		(new SchemaRefInRegister(schemas: $this->schemas))->find(schemaRef: 'agent', register: self::register([36]));
	}

	public function testARegisterLookupFailureOfAnyKindIsSwallowed(): void {
		$this->registers->method('find')->willThrowException(new RuntimeException('db down'));
		$this->schemas->expects($this->once())->method('find')->willReturn(self::schema(22));

		$found = (new SchemaRefInRegister(schemas: $this->schemas, registers: $this->registers))->find(schemaRef: 'agent', register: 5);

		$this->assertSame(22, $found->getId());
	}

	public function testARegisterListingNoSchemasSkipsTheScopedQuery(): void {
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->schemas->expects($this->once())->method('find')->with('agent')->willReturn(self::schema(22));

		$this->assertSame(22, (new SchemaRefInRegister(schemas: $this->schemas))->find(schemaRef: 'agent', register: self::register([]))->getId());
	}
}
