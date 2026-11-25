<?php

/**
 * A mixed-schema row's schema slug pinned to the batch register's own schema.
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
use OCA\OpenRegister\Service\Schemas\RowSchemaRefsInRegister;
use OCA\OpenRegister\Service\Schemas\SchemaRefInRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\RowSchemaRefsInRegister
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class RowSchemaRefsInRegisterTest extends TestCase {

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var RegisterMapperInterface&MockObject */
	private RegisterMapperInterface $registers;

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
	}

	private function pass(): RowSchemaRefsInRegister {
		return new RowSchemaRefsInRegister(
			lookup: new SchemaRefInRegister(schemas: $this->schemas, registers: $this->registers)
		);
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

	public function testASlugTheRegisterCarriesBecomesItsSchemaIdOnEveryRowNamingIt(): void {
		$this->registers->expects($this->once())->method('find')
			->with(7, false, false)->willReturn(self::register([36, 37]));
		$this->schemas->expects($this->once())->method('findBySlugInIds')
			->with('agent', [36, 37])->willReturn(self::schema(36));
		$this->schemas->expects($this->never())->method('find');

		$rows = $this->pass()->scope(
			objects: [
				'a' => ['@self' => ['schema' => 'agent'], 'name' => 'one'],
				'b' => ['@self' => ['schema' => 'agent'], 'name' => 'two'],
			],
			register: 7
		);

		$this->assertSame(
			[
				'a' => ['@self' => ['schema' => 36], 'name' => 'one'],
				'b' => ['@self' => ['schema' => 36], 'name' => 'two'],
			],
			$rows
		);
	}

	public function testASlugTheRegisterLacksIsLeftForTheGlobalLookup(): void {
		$this->registers->method('find')->willReturn(self::register([36]));
		$this->schemas->expects($this->once())->method('findBySlugInIds')
			->with('contact', [36])->willReturn(null);

		$rows = [['@self' => ['schema' => 'contact']]];

		$this->assertSame($rows, $this->pass()->scope(objects: $rows, register: 7));
	}

	public function testIdsUuidsAndRowsWithoutASchemaAreNotLookedUp(): void {
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('findBySlugInIds');

		$rows = [
			['@self' => ['schema' => 12]],
			['@self' => ['schema' => '12']],
			['@self' => []],
			['name' => 'no self'],
			'not a row',
			['@self' => 'not a map'],
		];

		$this->assertSame($rows, $this->pass()->scope(objects: $rows, register: 7));
	}

	public function testARowNamingAnotherRegisterIsLeftAsItIs(): void {
		$this->registers->expects($this->once())->method('find')
			->with('7', false, false)->willReturn(self::register([36]));
		$this->schemas->expects($this->once())->method('findBySlugInIds')->willReturn(self::schema(36));

		$rows = $this->pass()->scope(
			objects: [
				['@self' => ['schema' => 'agent', 'register' => 9]],
				['@self' => ['schema' => 'agent', 'register' => 7]],
			],
			register: '7'
		);

		$this->assertSame('agent', $rows[0]['@self']['schema']);
		$this->assertSame(36, $rows[1]['@self']['schema']);
	}

	public function testNoRegisterScopesNothing(): void {
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('findBySlugInIds');

		$rows = [['@self' => ['schema' => 'agent']]];

		foreach ([null, '', 0, '0'] as $register) {
			$this->assertSame($rows, $this->pass()->scope(objects: $rows, register: $register));
		}
	}
}
