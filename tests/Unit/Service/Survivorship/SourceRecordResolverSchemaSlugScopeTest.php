<?php

/**
 * A source schema slug resolves inside the source register, and is memoised per register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Survivorship;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Two registers carry a source schema with one slug; the source register decides the schema id the reverse-FK query uses.
 *
 * @covers \OCA\OpenRegister\Service\Survivorship\SourceRecordResolver
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class SourceRecordResolverSchemaSlugScopeTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;

	private RegisterMapperInterface&MockObject $registers;

	protected function setUp(): void {
		parent::setUp();
		// The memo is static and lives across requests; start every case from empty.
		(new ReflectionProperty(SourceRecordResolver::class, 'schemaIdCache'))->setValue(null, []);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
	}

	protected function tearDown(): void {
		(new ReflectionProperty(SourceRecordResolver::class, 'schemaIdCache'))->setValue(null, []);
		parent::tearDown();
	}

	private function resolver(?RegisterMapperInterface $registers): SourceRecordResolver {
		return new SourceRecordResolver($this->createMock(ObjectOperationsInterface::class), $this->schemas, new NullLogger(), $registers);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}

	private static function register(array $schemaIds): Register {
		$register = new Register();
		$register->setSchemas($schemaIds);

		return $register;
	}

	public function testASourceSlugResolvesPerRegisterAndIsMemoisedPerRegister(): void {
		$this->registers->expects($this->exactly(2))->method('find')->willReturnCallback(
			static fn (string|int $ref): Register => ($ref === 'crm') ? self::register([36]) : self::register([37])
		);
		$this->schemas->expects($this->exactly(2))->method('findBySlugInIds')->willReturnCallback(
			static fn (string $slug, array $ids): ?Schema => self::schema((int)$ids[0])
		);
		$this->schemas->expects($this->never())->method('find');

		$resolver = $this->resolver(registers: $this->registers);

		$this->assertSame('36', $resolver->schemaQueryFilter(ref: 'source', register: 'crm'));
		$this->assertSame('37', $resolver->schemaQueryFilter(ref: 'source', register: 'erp'));
		// Both answered from the memo now: no further register or schema reads.
		$this->assertSame('36', $resolver->schemaQueryFilter(ref: 'source', register: 'crm'));
		$this->assertSame('37', $resolver->schemaQueryFilter(ref: 'source', register: 'erp'));
	}

	public function testAGloballyMemoisedSlugDoesNotAnswerAScopedLookup(): void {
		$this->schemas->expects($this->once())->method('find')->with('source', [], false, false)->willReturn(self::schema(22));
		$this->registers->method('find')->willReturn(self::register([36]));
		$this->schemas->expects($this->once())->method('findBySlugInIds')->with('source', [36])->willReturn(self::schema(36));

		$resolver = $this->resolver(registers: $this->registers);

		$this->assertSame('22', $resolver->schemaQueryFilter(ref: 'source'));
		$this->assertSame('36', $resolver->schemaQueryFilter(ref: 'source', register: 'crm'));
	}

	public function testASlugTheRegisterLacksResolvesGloballyAndAFailureIsNotMemoised(): void {
		$this->registers->method('find')->willReturn(self::register([40]));
		$this->schemas->method('findBySlugInIds')->willReturn(null);
		$this->schemas->expects($this->exactly(2))->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new \RuntimeException('inside a save transaction')),
			self::schema(22)
		);

		$resolver = $this->resolver(registers: $this->registers);

		$this->assertSame('source', $resolver->schemaQueryFilter(ref: 'source', register: 'crm'));
		$this->assertSame('22', $resolver->schemaQueryFilter(ref: 'source', register: 'crm'));
	}

	public function testANumericReferenceIsPassedThroughUnread(): void {
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('find');

		$this->assertSame('36', $this->resolver(registers: $this->registers)->schemaQueryFilter(ref: '36', register: 'crm'));
	}
}
