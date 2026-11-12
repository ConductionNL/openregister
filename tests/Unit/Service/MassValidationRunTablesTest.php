<?php

/**
 * Unit tests for which objects a mass validation run re-saves: every object of every magic table.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\MassValidationRun;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The run counts and pages through the objects table by table.
 *
 * @covers \OCA\OpenRegister\Service\MassValidationRun
 */
class MassValidationRunTablesTest extends TestCase {

	/**
	 * Register 1 holds schema 2 (two objects) and schema 3 (three objects).
	 *
	 * @var array<string, list<string>>
	 */
	private const TABLES = ['1_2' => ['a1', 'a2'], '1_3' => ['b1', 'b2', 'b3']];

	/**
	 * Run over the two tables and return the uuids re-saved, in order, and the result.
	 *
	 * @param array{mode: string, max_objects: int, batch_size: int, collect_errors: bool} $config The run configuration.
	 *
	 * @return array{0: list<string>, 1: array<string, mixed>} The re-saved uuids and the result.
	 */
	private function validate(array $config): array {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('getAllRegisterSchemaPairs')->willReturn([['registerId' => 1, 'schemaId' => 2], ['registerId' => 1, 'schemaId' => 3]]);
		// Like the real mapper, a query that names no register and schema counts and finds nothing.
		$mapper->method('countSearchObjects')->willReturnCallback(
			static fn (array $query = []): int => count(self::TABLES[self::tableKey($query)] ?? [])
		);
		$mapper->method('searchObjects')->willReturnCallback(
			static function (array $query = []): array {
				$uuids = array_slice(self::TABLES[self::tableKey($query)] ?? [], (int)($query['_offset'] ?? 0), $query['_limit'] ?? null);
				return array_map(
					static function (string $uuid) use ($query): ObjectEntity {
						$object = new ObjectEntity();
						$object->setUuid($uuid);
						$object->setRegister((string)$query['@self']['register']);
						$object->setSchema((string)$query['@self']['schema']);
						$object->setObject(['uuid' => $uuid]);
						return $object;
					},
					$uuids
				);
			}
		);

		$saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved): ObjectEntity {
				$saved[] = ($args['options'] ?? $args[1])->uuid;
				return new ObjectEntity();
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$run    = new MassValidationRun($mapper, $this->createMock(AppSettingsInterface::class), $this->createMock(LoggerInterface::class), $container);
		$result = $run->run($config);

		return [$saved, $result];
	}//end validate()

	/**
	 * The `register_schema` key of a query, or '' when it names no table.
	 *
	 * @param array $query The search query.
	 *
	 * @return string The key.
	 */
	private static function tableKey(array $query): string {
		if (isset($query['@self']['register'], $query['@self']['schema']) === false) {
			return '';
		}

		return $query['@self']['register'].'_'.$query['@self']['schema'];
	}//end tableKey()

	/**
	 * Batches of two cross the table boundary and re-save all five objects once.
	 *
	 * @return void
	 */
	public function testEveryObjectOfEveryTableIsResavedOnce(): void {
		[$saved, $result] = $this->validate(['mode' => 'serial', 'max_objects' => 0, 'batch_size' => 2, 'collect_errors' => true]);

		$this->assertSame(['a1', 'a2', 'b1', 'b2', 'b3'], $saved);
		$this->assertSame(5, $result['stats']['total_objects']);
		$this->assertSame(5, $result['stats']['processed_objects']);
		$this->assertSame(3, $result['stats']['batches_processed']);
	}//end testEveryObjectOfEveryTableIsResavedOnce()

	/**
	 * max_objects stops the run part-way through the second table.
	 *
	 * @return void
	 */
	public function testMaxObjectsStopsInsideATable(): void {
		[$saved, $result] = $this->validate(['mode' => 'parallel', 'max_objects' => 3, 'batch_size' => 2, 'collect_errors' => true]);

		$this->assertSame(['a1', 'a2', 'b1'], $saved);
		$this->assertSame(3, $result['stats']['total_objects']);
	}//end testMaxObjectsStopsInsideATable()
}//end class
