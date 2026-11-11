<?php

/**
 * TablesSchemaSeedGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Golden: the schema a table seeds, created, refreshed or left alone.
 *
 * Records every mapper and log call of a reconcile over three tables (one new,
 * one whose managed schema exists, one whose schema is hand-authored) and pins
 * them in full, payload included.
 */
class TablesSchemaSeedGoldenTest extends TestCase {

	public function testSeedCreatesRefreshesAndLeavesHandAuthoredSchemasAlone(): void {
		$log = [];
		$register = new Register();
		$register->setId(30);
		$register->setSlug('tables');
		$register->setSchemas([82]);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$registers->method('update')->willReturnCallback(
			function (Register $updated) use (&$log): Register {
				$log[] = ['registers.update', $updated->getSchemas()];
				return $updated;
			}
		);
		$registers->method('findAll')->willReturn([]);

		$managed = new Schema();
		$managed->setId(82);
		$managed->setSlug('nc-orders-t4');
		$managed->setConfiguration(['x-openregister-object-source' => ['provider' => 'tables', 'config' => ['tableId' => 4, 'managed' => true]]]);
		$handmade = new Schema();
		$handmade->setId(83);
		$handmade->setSlug('nc-mine-t5');
		$handmade->setConfiguration([]);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function ($slug) use (&$log, $managed, $handmade): Schema {
				$log[] = ['schemas.find', $slug];
				return match ($slug) {
					'nc-orders-t4' => $managed,
					'nc-mine-t5' => $handmade,
					default => throw new DoesNotExistException('none'),
				};
			}
		);
		$schemas->method('createFromArray')->willReturnCallback(
			function (array $object) use (&$log): Schema {
				$log[] = ['schemas.createFromArray', $object];
				$created = new Schema();
				$created->setId(81);
				return $created;
			}
		);
		$schemas->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object) use (&$log): Schema {
				$log[] = ['schemas.updateFromArray', $id, $object];
				return new Schema();
			}
		);
		$schemas->method('findAll')->willReturn([]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$log): void {
				$log[] = ['warning', $message];
			}
		);

		$service = new TablesSchemaSyncService(
			registerMapper: $registers,
			schemaMapper: $schemas,
			columnMapper: new TablesColumnMapper(uuidDeriver: new TablesUuidDeriver(), logger: new NullLogger()),
			logger: $logger
		);

		$stats = $service->reconcile(
			tables: [
				['id' => 3, 'title' => 'New', 'columns' => [['id' => 1, 'title' => 'Name', 'type' => 'text', 'subtype' => 'line', 'mandatory' => true]]],
				['id' => 4, 'title' => 'Orders', 'columns' => []],
				['id' => 5, 'title' => 'Mine'],
			]
		);

		$actual = json_decode((string)json_encode(['stats' => $stats, 'log' => $log], JSON_UNESCAPED_SLASHES), true);
		$file = __DIR__ . '/fixtures/tables-schema-seed-golden.json';
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($file, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents($file), true), $actual);
	}//end testSeedCreatesRefreshesAndLeavesHandAuthoredSchemasAlone()
}//end class
