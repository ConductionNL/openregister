<?php

/**
 * ValidationHandler::validateAndSaveObjectsBySchema() loads, slices, chunks, saves and counts exactly as recorded.
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
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of the schema-wide validate-and-save run over every branch: the schema and
 * register loads, the table read, limit and offset, the chunk size for each size band, each bulk
 * save with its arguments, the per-chunk counts, a failing chunk, a callback without a bulk save,
 * and the log lines (without file, line and memory figures; numbers carry their type). Long call
 * logs are recorded as their first and last entries plus a hash of the whole. Set
 * OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\ValidationChunk
 * @uses   \OCA\OpenRegister\Service\Object\ValidationRun
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class ValidationHandlerSchemaRunGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/validation-handler-schema-run-golden.json';

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
		$scenarios = [
			'schema cannot load'     => ['schemaFails' => true, 'count' => 3],
			'register cannot load'   => ['registerFails' => true, 'count' => 3],
			'objects cannot load'    => ['tableFails' => true],
			'no objects'             => ['count' => 0],
			'three objects'          => ['count' => 3],
			'limit'                  => ['count' => 5, 'limit' => 2],
			'offset'                 => ['count' => 5, 'offset' => 3],
			'limit and offset'       => ['count' => 5, 'limit' => 2, 'offset' => 1],
			'limit zero'             => ['count' => 5, 'limit' => 0],
			'offset past the end'    => ['count' => 5, 'offset' => 9],
			'saved and updated'      => ['count' => 3, 'stats' => ['saved' => 1, 'updated' => 2, 'failed' => 4]],
			'no statistics'          => ['count' => 3, 'stats' => null],
			'empty statistics'       => ['count' => 3, 'stats' => []],
			'bulk save throws'       => ['count' => 3, 'saveFails' => [1]],
			'callback without bulk'  => ['count' => 3, 'callback' => 'plain'],
			'empty callback'         => ['count' => 3, 'callback' => 'empty'],
			'four chunks, one fails' => ['count' => 4500, 'saveFails' => [2]],
			'band 1000'              => ['count' => 1000],
			'band 1001'              => ['count' => 1001],
			'band 10000'             => ['count' => 10000],
			'band 10001'             => ['count' => 10001],
			'band 50000'             => ['count' => 50000],
			'band 50001'             => ['count' => 50001],
			'band 200000'            => ['count' => 200000],
			'band 200001'            => ['count' => 200001],
			'band by limit'          => ['count' => 4000, 'limit' => 2500, 'offset' => 1000],
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
	 * Run the schema-wide validation once on a fresh handler and record what happened.
	 *
	 * @param array<string, mixed> $scenario The world and the request.
	 *
	 * @return array<string, mixed>
	 */
	private function scenario(array $scenario): array {
		$this->calls = [];
		$this->world = $scenario;

		$result = $this->handler()->validateAndSaveObjectsBySchema(
			registerId: 7,
			schemaId: 9,
			saveCallback: $this->saveCallback(kind: ($scenario['callback'] ?? 'bulk')),
			limit: ($scenario['limit'] ?? null),
			offset: ($scenario['offset'] ?? 0)
		);

		$calls = $this->typed(value: $this->calls);
		if (count($calls) > 12) {
			$calls = [
				'count' => count($calls),
				'first' => array_slice($calls, 0, 6),
				'last' => array_slice($calls, -6),
				'sha1' => sha1((string) json_encode($calls)),
			];
		}

		return ['result' => $this->typed(value: $result), 'calls' => $calls];
	}//end scenario()

	/**
	 * The save callback the run is given.
	 *
	 * @param string $kind bulk, plain or empty.
	 *
	 * @return array<int, mixed>
	 */
	private function saveCallback(string $kind): array {
		if ($kind === 'empty') {
			return [];
		}

		if ($kind === 'plain') {
			return [new class {
				/**
				 * A single save only.
				 *
				 * @return void
				 */
				public function saveObject(): void {
				}//end saveObject()
			}, 'saveObject'];
		}

		$test = $this;
		return [new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param ValidationHandlerSchemaRunGoldenTest $test The recording test.
			 */
			public function __construct(private readonly ValidationHandlerSchemaRunGoldenTest $test) {
			}//end __construct()

			/**
			 * The bulk save double.
			 *
			 * @param array<int, mixed> $objects The chunk.
			 * @param mixed             ...$rest  The named options.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObjects(array $objects, mixed ...$rest): array {
				return $this->test->bulkSave(objects: $objects, options: $rest);
			}//end saveObjects()
		}, 'saveObject'];
	}//end saveCallback()

	/**
	 * Record one bulk save and answer it.
	 *
	 * @param array<int, mixed>    $objects The chunk.
	 * @param array<string, mixed> $options The named options.
	 *
	 * @return array<string, mixed>
	 */
	public function bulkSave(array $objects, array $options): array {
		$this->calls[] = ['saveObjects', count($objects), $objects[0] ?? null, $objects[count($objects) - 1] ?? null, $options];
		$saves = count(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'saveObjects'));
		if (in_array($saves, ($this->world['saveFails'] ?? []), true) === true) {
			throw new Exception('bulk save ' . $saves . ' failed');
		}

		if (array_key_exists('stats', $this->world) === true) {
			return $this->world['stats'] === null ? [] : ['statistics' => $this->world['stats']];
		}

		return ['statistics' => ['saved' => count($objects) - 1, 'updated' => 1, 'failed' => 0]];
	}//end bulkSave()

	/**
	 * Build the handler over recording doubles.
	 *
	 * @return ValidationHandler
	 */
	private function handler(): ValidationHandler {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (...$args): Schema {
				$this->calls[] = ['schema.find', $args];
				if (($this->world['schemaFails'] ?? false) === true) {
					throw new Exception('no schema');
				}

				$schema = new Schema();
				$schema->setId((int) $args[0]);
				return $schema;
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->calls[] = ['register.find', $args];
				if (($this->world['registerFails'] ?? false) === true) {
					throw new Exception('no register');
				}

				$register = new Register();
				$register->setId((int) $args[0]);
				return $register;
			}
		);

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (Register $register, Schema $schema, ...$rest): array {
				$this->calls[] = ['findAllInRegisterSchemaTable', $register->getId(), $schema->getId(), $rest];
				if (($this->world['tableFails'] ?? false) === true) {
					throw new Exception('no table');
				}

				$count = (int) ($this->world['count'] ?? 0);
				if ($count > 20) {
					$entity = new ObjectEntity();
					$entity->setObject(['same' => true]);
					return array_fill(0, $count, $entity);
				}

				$objects = [];
				for ($i = 0; $i < $count; $i++) {
					$entity = new ObjectEntity();
					$entity->setObject(['n' => $i]);
					$objects[] = $entity;
				}

				return $objects;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (...$args) use ($level): void {
					$context = $args[1] ?? [];
					unset($context['file'], $context['line'], $context['memory_usage'], $context['memory_after']);
					$this->calls[] = ['log.' . $level, $args[0], $context];
				}
			);
		}

		return new ValidationHandler(
			validateHandler: $this->createMock(ValidateObject::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			magicMapper: $magic,
			logger: $logger
		);
	}//end handler()

	/**
	 * A value with every float marked, so 2 and 2.0 record differently.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return mixed
	 */
	private function typed(mixed $value): mixed {
		if (is_float($value) === true) {
			return 'float:' . var_export($value, true);
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->typed(value: $item), $value);
		}

		if ($value instanceof Schema === true || $value instanceof Register === true) {
			return get_class($value) . '#' . $value->getId();
		}

		return $value;
	}//end typed()
}//end class
