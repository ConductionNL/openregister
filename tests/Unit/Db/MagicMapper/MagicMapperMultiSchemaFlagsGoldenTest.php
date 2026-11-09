<?php

/**
 * Golden test: the RBAC and multitenancy flags a count over several schemas hands each table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Pins that countSearchObjects() over several schemas counts and searches each table under the caller's
 * RBAC and multitenancy flags, and that a single-table count leaves them to the table count.
 */
class MagicMapperMultiSchemaFlagsGoldenTest extends TestCase {

	/**
	 * The counts asked for: register id, schema id, _rbac, _multitenancy.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $counts = [];

	/**
	 * A mapper over register 1 holding schemas 7 and 8.
	 *
	 * @return MagicMapper
	 */
	private function mapper(): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['countObjectsInRegisterSchemaTable', 'searchAcrossMultipleTables'])
			->getMock();
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int)$id);
				$register->setSchemas([7, 8]);
				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);
		(new ReflectionProperty(MagicMapper::class, 'logger'))->setValue($mapper, new NullLogger());
		(new ReflectionProperty(MagicMapper::class, 'registerMapper'))->setValue($mapper, $registers);
		(new ReflectionProperty(MagicMapper::class, 'schemaMapper'))->setValue($mapper, $schemas);
		$mapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query, Register $register, Schema $schema): int {
				$this->counts[] = [$register->getId(), $schema->getId(), $query['_rbac'] ?? 'unset', $query['_multitenancy'] ?? 'unset'];
				return 3;
			}
		);

		$mapper->method('searchAcrossMultipleTables')->willReturnCallback(
			function (array $query, array $pairs): array {
				$this->counts[] = ['search', count($pairs), $query['_rbac'] ?? 'unset', $query['_multitenancy'] ?? 'unset'];
				return [];
			}
		);

		return $mapper;
	}//end mapper()

	public function testSeveralSchemasAreCountedUnderTheCallersFlags(): void {
		$query = ['@self' => ['register' => [1], 'schema' => [7, 8]]];

		$this->assertSame(6, $this->mapper()->countSearchObjects($query, null, true, false));
		$this->assertSame([[1, 7, true, false], [1, 8, true, false], ['search', 2, true, false]], $this->counts);
	}//end testSeveralSchemasAreCountedUnderTheCallersFlags()

	public function testSeveralSchemasWithoutRbacAreCountedWithoutIt(): void {
		$query = ['@self' => ['register' => [1], 'schema' => [7, 8]]];

		$this->assertSame(6, $this->mapper()->countSearchObjects($query, null, false, true));
		$this->assertSame([[1, 7, false, true], [1, 8, false, true], ['search', 2, false, true]], $this->counts);
	}//end testSeveralSchemasWithoutRbacAreCountedWithoutIt()

	public function testASearchOverSeveralSchemasRunsUnderTheCallersFlags(): void {
		$query = ['@self' => ['register' => [1], 'schema' => [7, 8]]];

		$this->assertSame([], $this->mapper()->searchObjects($query, null, false, false));
		$this->assertSame([[1, 7, false, false], [1, 8, false, false], ['search', 2, false, false]], $this->counts);
	}//end testASearchOverSeveralSchemasRunsUnderTheCallersFlags()

	public function testOneTableIsCountedWithoutFlagsInTheQuery(): void {
		$query = ['@self' => ['register' => 1, 'schema' => 7]];

		$this->assertSame(3, $this->mapper()->countSearchObjects($query, null, true, true));
		$this->assertSame([[1, 7, 'unset', 'unset']], $this->counts);
	}//end testOneTableIsCountedWithoutFlagsInTheQuery()

	public function testIdsThatSelectNothingCountNothing(): void {
		$query = ['@self' => ['register' => [1], 'schema' => [7, 8]]];

		$this->assertSame(0, $this->mapper()->countSearchObjects($query, null, true, true, []));
		$this->assertSame([], $this->counts);
	}//end testIdsThatSelectNothingCountNothing()
}//end class
