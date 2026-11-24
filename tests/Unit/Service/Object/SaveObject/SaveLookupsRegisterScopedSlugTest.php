<?php

/**
 * A schema reference a save resolves names the register's own schema for a shared slug.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class SaveLookupsRegisterScopedSlugTest extends TestCase {

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var RegisterMapperInterface&MockObject */
	private RegisterMapperInterface $registers;

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
	}

	private function lookups(): SaveLookups {
		return new SaveLookups(schemaMapper: $this->schemas, registerMapper: $this->registers);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');

		return $schema;
	}

	private static function register(int $id, array $schemaIds): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSchemas($schemaIds);

		return $register;
	}

	public function testAPathReferenceResolvesToTheRegistersOwnSchemaNotTheFirstGlobalMatch(): void {
		// The global scan would answer another app's schema 22 first.
		$this->schemas->method('findAll')->willReturn([self::schema(22), self::schema(36)]);
		$this->schemas->expects($this->once())->method('findBySlugInIds')
			->with('agent', [36])->willReturn(self::schema(36));

		$lookups = $this->lookups();

		$this->assertSame('36', $lookups->schemaId(reference: '#/components/schemas/agent', register: self::register(7, [36])));
		$this->assertSame('22', $lookups->schemaId(reference: '#/components/schemas/agent'));
	}

	public function testARegisterIdIsReadOnceAndTheScopedAnswerKept(): void {
		$this->registers->expects($this->once())->method('find')
			->with(7)->willReturn(self::register(7, [36]));
		$this->schemas->expects($this->once())->method('findBySlugInIds')->willReturn(self::schema(36));
		$this->schemas->expects($this->never())->method('findAll');

		$lookups = $this->lookups();

		$this->assertSame('36', $lookups->schemaId(reference: 'agent', register: 7));
		$this->assertSame('36', $lookups->schemaId(reference: 'Agent', register: 7));
		$this->assertSame(36, $lookups->schema(schemaId: 36)->getId());
	}

	public function testASlugTheRegisterLacksFallsBackToTheGlobalLookup(): void {
		$this->schemas->expects($this->once())->method('findBySlugInIds')->willReturn(null);
		$this->schemas->method('findAll')->willReturn([self::schema(22)]);

		$this->assertSame('22', $this->lookups()->schemaId(reference: 'agent', register: self::register(7, [36])));
	}

	public function testAnIdIsNeverLookedUpInsideTheRegister(): void {
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->once())->method('find')->willReturn(self::schema(36));

		$this->assertSame('36', $this->lookups()->schemaId(reference: '36', register: 7));
	}

	public function testClearForgetsTheScopedAnswers(): void {
		$this->schemas->expects($this->exactly(2))->method('findBySlugInIds')->willReturn(self::schema(36));

		$lookups = $this->lookups();
		$register = self::register(7, [36]);
		$lookups->schemaId(reference: 'agent', register: $register);
		$lookups->clear();
		$lookups->schemaId(reference: 'agent', register: $register);
	}
}
