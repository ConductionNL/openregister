<?php

/**
 * Characterisation of which registers' magic tables index maintenance covers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Search;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCA\OpenRegister\Search\Service\Search\SearchIndexStores;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * schemaSlugsByTable: every register, one register, and non-register rows.
 *
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance
 */
class SearchIndexMaintenanceScopeTest extends TestCase {

	/**
	 * The tables in scope for a register id.
	 *
	 * @param integer|null $registerId The register, or null for all.
	 *
	 * @return array<string, string> Slug by table.
	 */
	private function tables(?int $registerId): array {
		$registers = [];
		foreach ([1, 2] as $id) {
			$register = new Register();
			$register->setId($id);
			$registers[] = $register;
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$registers[0], new \stdClass(), $registers[1]]);
		$registerMapper->method('getSchemasByRegisterId')->willReturnCallback(
			static function (int $registerId): array {
				$schema = new Schema();
				$schema->setId(10 + $registerId);
				$schema->setSlug('s' . $registerId);
				return [$schema];
			}
		);
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 'openregister_table_' . $register->getId() . '_' . $schema->getId()
		);

		$service = new SearchIndexMaintenance(
			stores: new SearchIndexStores($this->createMock(IDBConnection::class), new SchemaCatalog(registers: $registerMapper, schemas: $this->createMock(SchemaMapperInterface::class), tables: $magicMapper)),
			appConfig: $this->createMock(IAppConfig::class),
			config: $this->createMock(IConfig::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		return (new ReflectionMethod(SearchIndexMaintenance::class, 'schemaSlugsByTable'))->invoke($service, $registerId);
	}//end tables()

	/**
	 * Null covers every register; anything that is not a register is skipped.
	 *
	 * @return void
	 */
	public function testEveryRegister(): void {
		$this->assertSame(['openregister_table_1_11' => 's1', 'openregister_table_2_12' => 's2'], $this->tables(null));
	}//end testEveryRegister()

	/**
	 * A register id covers that register only; an unknown one covers nothing.
	 *
	 * @return void
	 */
	public function testOneRegister(): void {
		$this->assertSame(['openregister_table_2_12' => 's2'], $this->tables(2));
		$this->assertSame([], $this->tables(9));
	}//end testOneRegister()
}//end class
