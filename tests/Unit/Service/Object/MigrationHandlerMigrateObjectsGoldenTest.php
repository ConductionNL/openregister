<?php

/**
 * MigrationHandler::migrateObjects() checks, maps, moves and reports exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Golden record of migrateObjects() over every branch: the four register and schema loads in
 * order, the batch load, the per-object ownership check against the requested source, the
 * property mapping and its counts, the object as it is handed to the update, the not-found
 * entries and the report. Stored object data that names a register, schema or owner is forged
 * and must not decide where an object is moved. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\MigrationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\MigrationRun
 * @uses   \OCA\OpenRegister\Service\Object\MigrationSide
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class MigrationHandlerMigrateObjectsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/migration-handler-migrate-objects-golden.json';

	private const FORGED = ['title' => 'T', 'keep' => 1, 'register' => '99', 'schema' => '99', '_owner' => 'mallory', '@self' => ['register' => 99]];

	/** @var array<int, mixed> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $world = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$ok = ['uuid' => 'u-ok', 'id' => 11, 'name' => 'Ok', 'register' => '1', 'schema' => '2', 'object' => self::FORGED];
		$nameless = ['uuid' => 'u-nameless', 'id' => 12, 'name' => null, 'register' => '1', 'schema' => '2', 'object' => ['a' => 1]];
		$wrongRegister = ['uuid' => 'u-reg', 'id' => 13, 'name' => 'R', 'register' => '7', 'schema' => '2', 'object' => ['a' => 1]];
		$wrongSchema = ['uuid' => 'u-sch', 'id' => 14, 'name' => 'S', 'register' => '1', 'schema' => '8', 'object' => ['a' => 1]];
		$updateFails = ['uuid' => 'u-fail', 'id' => 15, 'name' => 'F', 'register' => '1', 'schema' => '2', 'object' => ['a' => 1, 'b' => 2]];
		$renamed = ['uuid' => 'u-new', 'id' => 16, 'name' => 'N', 'register' => '1', 'schema' => '2', 'object' => [], 'savedAs' => 'u-other'];
		$entities = ['register:1' => 1, 'schema:2' => 2, 'register:target' => 30, 'schema:target' => 40, 'register:3' => 3, 'schema:4' => 4, 'register:src-slug' => 1, 'schema:sch-slug' => 2];
		$mapping = ['title' => 'title', 'kept' => 'keep', 'gone' => 'absent', 'register' => 'register'];
		$base = ['entities' => $entities, 'mapping' => $mapping, 'route' => ['1', '2', 'target', 'target']];

		$scenarios = [
			'mixed outcomes'            => $base + ['ids' => ['u-ok', 'u-reg', 'u-sch', 'u-fail', 'missing', 12, '12'], 'found' => [$ok, $wrongRegister, $wrongSchema, $updateFails, $nameless], 'updateFails' => ['u-fail']],
			'all migrated'              => $base + ['ids' => ['u-ok', 'u-nameless'], 'found' => [$ok, $nameless]],
			'saved under another uuid'  => $base + ['ids' => ['u-new'], 'found' => [$renamed]],
			'nothing found'             => $base + ['ids' => ['a', 'b', 'a'], 'found' => []],
			'no ids'                    => $base + ['ids' => [], 'found' => []],
			'all fail'                  => $base + ['ids' => ['u-reg'], 'found' => [$wrongRegister]],
			'int route'                 => ['route' => [1, 2, 3, 4]] + $base + ['ids' => [11], 'found' => [$ok]],
			'slug source matches by id' => ['route' => ['src-slug', '2', 'target', 'target']] + $base + ['ids' => ['u-ok'], 'found' => [$ok]],
			'slug source schema matches by id' => ['route' => ['1', 'sch-slug', 'target', 'target']] + $base + ['ids' => ['u-ok'], 'found' => [$ok]],
			'found twice'               => $base + ['ids' => ['u-ok'], 'found' => [$ok, $ok]],
			'mapping maps more than stored' => ['mapping' => ['x' => 'a', 'y' => 'a', 'z' => 'a']] + $base + ['ids' => ['u-nameless'], 'found' => [$nameless]],
			'empty mapping'             => ['mapping' => []] + $base + ['ids' => ['u-ok'], 'found' => [$ok]],
			'mapping throws'            => $base + ['ids' => ['u-ok', 'u-nameless'], 'found' => [$ok, $nameless], 'mapFails' => ['u-ok']],
			'source register missing'   => ['route' => ['none', '2', 'target', 'target']] + $base + ['ids' => ['u-ok'], 'found' => [$ok]],
			'source schema missing'     => ['route' => ['1', 'none', 'target', 'target']] + $base + ['ids' => ['u-ok'], 'found' => [$ok]],
			'target register missing'   => ['route' => ['1', '2', 'none', 'target']] + $base + ['ids' => ['u-ok'], 'found' => [$ok]],
			'target schema missing'     => ['route' => ['1', '2', 'target', 'none']] + $base + ['ids' => ['u-ok'], 'found' => [$ok]],
			'a load throws'             => ['route' => ['1', 'boom', 'target', 'target']] + $base + ['ids' => ['u-ok'], 'found' => [$ok]],
			'batch load throws'         => $base + ['ids' => ['u-ok'], 'found' => [$ok], 'findFails' => true],
		];

		$actual = [];
		foreach ($scenarios as $name => $scenario) {
			$actual[$name] = $this->scenario(scenario: $scenario);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run migrateObjects() once on a fresh handler and record what happened.
	 *
	 * @param array<string, mixed> $scenario The world and the request.
	 *
	 * @return array<string, mixed>
	 */
	private function scenario(array $scenario): array {
		$this->calls = [];
		$this->world = $scenario;
		[$sourceRegister, $sourceSchema, $targetRegister, $targetSchema] = $scenario['route'];

		try {
			$result = $this->handler()->migrateObjects(
				sourceRegister: $sourceRegister,
				sourceSchema: $sourceSchema,
				targetRegister: $targetRegister,
				targetSchema: $targetSchema,
				objectIds: $scenario['ids'],
				mapping: $scenario['mapping']
			);
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return ['result' => $result, 'calls' => $this->calls];
	}//end scenario()

	/**
	 * Build the handler over recording doubles.
	 *
	 * @return MigrationHandler
	 */
	private function handler(): MigrationHandler {
		$utility = $this->createMock(UtilityHandler::class);
		$utility->method('normalizeEntity')->willReturnCallback(
			function ($entity, string $type) {
				$this->calls[] = ['normalizeEntity', $entity, $type];
				if ($entity === 'boom') {
					throw new Exception('load failed');
				}

				$id = $this->world['entities'][$type . ':' . $entity] ?? null;
				if ($id === null) {
					return null;
				}

				$loaded = $type === 'register' ? new Register() : new Schema();
				$loaded->setId($id);
				return $loaded;
			}
		);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findMultiple')->willReturnCallback(
			function (array $ids): array {
				$this->calls[] = ['findMultiple', $ids];
				if (($this->world['findFails'] ?? false) === true) {
					throw new Exception('batch failed');
				}

				return array_map(fn (array $data): ObjectEntity => $this->entity(data: $data), $this->world['found']);
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $object, ...$rest): ObjectEntity {
				$this->calls[] = ['update', $object->getUuid(), $object->getRegister(), $object->getSchema(), $object->getObject(), count($rest)];
				if (in_array($object->getUuid(), ($this->world['updateFails'] ?? []), true) === true) {
					throw new Exception('update failed');
				}

				foreach ($this->world['found'] as $data) {
					if ($data['uuid'] === $object->getUuid() && isset($data['savedAs']) === true) {
						$saved = new ObjectEntity();
						$saved->setUuid($data['savedAs']);
						return $saved;
					}
				}

				return $object;
			}
		);

		$manipulation = $this->createMock(DataManipulationHandler::class);
		$manipulation->method('mapObjectProperties')->willReturnCallback(
			function (array $sourceData, array $mapping): array {
				$this->calls[] = ['mapObjectProperties', $sourceData, $mapping];
				if (in_array($sourceData, [self::FORGED], true) === true && in_array('u-ok', ($this->world['mapFails'] ?? []), true) === true) {
					throw new Exception('mapping failed');
				}

				$mapped = [];
				foreach ($mapping as $target => $source) {
					if (array_key_exists($source, $sourceData) === true) {
						$mapped[$target] = $sourceData[$source];
					}
				}

				return $mapped;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (...$args) use ($level): void {
					$context = $args[1] ?? [];
					unset($context['file'], $context['line']);
					$this->calls[] = ['log.' . $level, $args[0], $context];
				}
			);
		}

		return new MigrationHandler(
			objectMapper: $mapper,
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			saveHandler: $this->createMock(SaveObject::class),
			utilityHandler: $utility,
			dataManipHandler: $manipulation,
			logger: $logger
		);
	}//end handler()

	/**
	 * Build a stored object.
	 *
	 * @param array<string, mixed> $data The stored fields.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setId($data['id']);
		$entity->setUuid($data['uuid']);
		$entity->setName($data['name']);
		$entity->setRegister($data['register']);
		$entity->setSchema($data['schema']);
		$entity->setObject($data['object']);
		return $entity;
	}//end entity()
}//end class
