<?php

/**
 * OpenRegister - the transform, persist, classify and reconstruct steps of a bulk save chunk.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Dmn\DecisionTableBulkGuard;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkRowRules;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Pins, by reflection, the outputs, the calls to the mapper and the log lines of
 * the chunk steps a bulk save runs after the safeguards. Recorded on the code
 * before those steps were split up; the expected values live in
 * `fixtures/save-objects-pipeline.php`.
 */
class SaveObjectsPipelineGoldenTest extends TestCase {

	private const REFERENCE = '22222222-2222-4222-8222-222222222222';

	/**
	 * The calls made, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The log lines.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * Every step gives the recorded outcome.
	 *
	 * @return void
	 */
	public function testEveryStepGivesTheRecordedOutcome(): void {
		$expected = require __DIR__.'/fixtures/save-objects-pipeline.php';

		$this->assertSame($expected, $this->record());
	}//end testEveryStepGivesTheRecordedOutcome()

	/**
	 * Run every step and collect its outcome.
	 *
	 * @return array<string, mixed>
	 */
	private function record(): array {
		$out = [];
		foreach ($this->steps() as $name => $step) {
			$this->calls = [];
			$this->lines = [];
			$ref = new ReflectionClass(SaveObjects::class);
			foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $cache) {
				$ref->getProperty($cache)->setValue(null, []);
			}

			try {
				$value = $step($this->handler());
			} catch (\Throwable $e) {
				$value = 'threw '.get_class($e).': '.$e->getMessage();
			}

			$out[$name] = ['value' => self::plain(value: $value), 'calls' => $this->calls, 'log' => $this->lines];
		}

		return $out;
	}//end record()

	/**
	 * The steps, each a closure over a fresh handler.
	 *
	 * @return array<string, callable(SaveObjects): mixed>
	 */
	private function steps(): array {
		$schemaCache = [7 => $this->schema(id: 7, properties: ['name' => ['type' => 'string'], 'ref' => ['type' => 'string']])];
		$rows        = [
			['register' => false, 'schema' => 7, 'object' => ['a' => 1]],
			['register' => 10, 'schema' => false, 'object' => ['a' => 2]],
			['register' => 10, 'schema' => 99, 'object' => ['a' => 3]],
			['id' => 'given-1', 'register' => 10, 'schema' => 7, 'object' => ['a' => 4], 'relations' => ['x' => 'y']],
			['@self' => ['id' => 'given-2', 'register' => 10, 'schema' => 7, 'organisation' => 'org-9'], 'name' => 'kept', 'title' => 'gone?', 'uuid' => 'u', 'slug' => 's'],
			['register' => 10, 'schema' => 7, 'object' => ['ref' => self::REFERENCE]],
			['id' => '   ', 'register' => 10, 'schema' => 7, 'description' => 'd', 'owner' => 'mallory', 'extra' => 1],
		];

		return [
			'transform'                => fn (SaveObjects $h) => $this->call($h, 'transformObjectsToDatabaseFormatInPlace', [&$rows, $schemaCache]),
			'transform chunk'          => function (SaveObjects $h) use ($rows, $schemaCache): array {
				$result = $this->call($h, 'emptyChunkResult', []);
				$valid  = $this->call($h, 'transformChunk', [$rows, $schemaCache, &$result]);
				return ['valid' => $valid, 'result' => $result];
			},
			'extract new structure'    => fn (SaveObjects $h) => $this->call($h, 'extractBusinessData', [['object' => ['k' => 'v'], 'name' => 'n'], null]),
			'extract legacy declared'  => fn (SaveObjects $h) => $this->call(
				$h,
				'extractBusinessData',
				[['name' => 'n', 'summary' => 's', 'uuid' => 'u', 'k' => 'v', '@self' => []], $schemaCache[7]]
			),
			'extract legacy no schema' => fn (SaveObjects $h) => $this->call($h, 'extractBusinessData', [['name' => 'n', 'image' => 'i', 'k' => 'v'], 'not a schema']),
			'required fields'          => fn (SaveObjects $h) => [
				$this->call($h, 'validateObjectRequiredFields', [['register' => false, 'schema' => 7], ['o' => 1], 0, $schemaCache]),
				$this->call($h, 'validateObjectRequiredFields', [['register' => 1, 'schema' => false], ['o' => 2], 1, $schemaCache]),
				$this->call($h, 'validateObjectRequiredFields', [['register' => 1, 'schema' => 5], ['o' => 3], 2, $schemaCache]),
				$this->call($h, 'validateObjectRequiredFields', [['register' => null, 'schema' => 7], ['o' => 4], 3, $schemaCache]),
			],
			'persist ids'              => fn (SaveObjects $h) => $this->call($h, 'persistChunk', [[['uuid' => 'a']], 10, 7, false]),
			'persist failing ids'      => fn (SaveObjects $h) => $this->call($h, 'persistChunk', [[['uuid' => 'a']], 404, 404, true]),
			'persist entities'         => fn (SaveObjects $h) => $this->call($h, 'persistChunk', [[['uuid' => 'a']], $this->register(id: 11), $schemaCache[7]]),
			'persist nothing named'    => fn (SaveObjects $h) => $this->call($h, 'persistChunk', [[['uuid' => 'a']]]),
			'build non array'          => fn (SaveObjects $h) => $this->buildResults($h, 'not an array', [['uuid' => 'a'], ['uuid' => 'b']]),
			'build legacy uuids'       => fn (SaveObjects $h) => $this->buildResults($h, ['a', 'c', null], [['uuid' => 'a', 'object' => []], ['uuid' => 'b'], ['object' => []]]),
			'build empty'              => fn (SaveObjects $h) => $this->buildResults($h, [], [['uuid' => 'a']]),
			'build classified'         => fn (SaveObjects $h) => $this->buildResults($h, $this->classifiedRows(), []),
			'reconstruct'              => fn (SaveObjects $h) => $this->call(
				$h,
				'reconstructSavedObjects',
				[
					[['uuid' => 'a', 'name' => 'A'], ['uuid' => ''], ['uuid' => 'z'], ['name' => 'no uuid']],
					[$this->entity(uuid: 'u1', object: ['x' => 2]), $this->entity(uuid: 'u2', object: ['y' => 1]), $this->entity(uuid: 'gone', object: [])],
					['a', 'u1', 'u2', null, ['nested']],
					['u1' => $this->entity(uuid: 'u1', object: ['x' => 1, 'keep' => true])],
				]
			),
			'result shapes'            => fn (SaveObjects $h) => [$this->call($h, 'initializeSaveResult', [3]), $this->call($h, 'emptyChunkResult', [])],
			'hydrated metadata'        => function (SaveObjects $h): array {
				$entity = new ObjectEntity();
				$entity->setName('N');
				$entity->setSlug('s-1');
				$object = ['title' => 't'];
				$self   = $this->call($h, 'applyHydratedMetadata', [['id' => 'i', 'name' => 'old'], &$object, $entity]);
				return ['self' => $self, 'object' => $object];
			},
			'decision tables off'      => fn (SaveObjects $h) => $this->refuseDecisionTables($h, null),
			'decision tables refuse'   => fn (SaveObjects $h) => $this->refuseDecisionTables($h, [1 => 'bad table', 'k' => 'bad key']),
		];
	}//end steps()

	/**
	 * Run buildChunkResults with an empty chunk result.
	 *
	 * @param SaveObjects $handler     The handler
	 * @param mixed       $bulkResult  What the mapper returned
	 * @param array       $transformed The chunk's rows
	 *
	 * @return array
	 */
	private function buildResults(SaveObjects $handler, mixed $bulkResult, array $transformed): array {
		$result      = $this->call($handler, 'emptyChunkResult', []);
		$sideEffects = $this->call($handler, 'buildChunkResults', [$bulkResult, $transformed, &$result]);

		return ['sideEffects' => $sideEffects, 'result' => $result];
	}//end buildResults()

	/**
	 * Run refuseInvalidDecisionTables, with a guard refusing the given positions or without one.
	 *
	 * @param SaveObjects       $handler  The handler (replaced when a guard is given)
	 * @param array|null        $refusals Position => reason, or null for no guard
	 *
	 * @return array
	 */
	private function refuseDecisionTables(SaveObjects $handler, ?array $refusals): array {
		if ($refusals !== null) {
			$guard = $this->createMock(DecisionTableBulkGuard::class);
			$guard->method('refusals')->willReturnCallback(
				function (...$args) use ($refusals): array {
					$this->calls[] = 'refusals '.json_encode([array_keys($args[0]), $args[1], $args[2]]);
					return $refusals;
				}
			);
			$handler = $this->handler(rowRules: new BulkRowRules(decisionTables: $guard));
		}

		$result = ['invalid' => [], 'errors' => [], 'statistics' => ['invalid' => 0, 'errors' => 0]];
		$rows   = [0 => ['a' => 0], 1 => ['a' => 1], 'k' => ['a' => 'k'], 3 => ['a' => 3]];
		$passed = $this->call($handler, 'refuseInvalidDecisionTables', [$rows, 10, 7, &$result]);

		return ['passed' => $passed, 'result' => $result];
	}//end refuseDecisionTables()

	/**
	 * Rows as the mapper returns them with a database-computed status.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function classifiedRows(): array {
		return [
			['_uuid' => 'c1', '_register' => 10, '_schema' => 7, 'object_status' => 'created', 'operation_start_time' => 't'],
			['_uuid' => 'u1', '_register' => 10, '_schema' => 7, 'object_status' => 'updated', '_pre_update_row' => ['_uuid' => 'u1', '_register' => 10, '_schema' => 7]],
			['_uuid' => 'u2', '_register' => 10, '_schema' => 7, 'object_status' => 'updated', '_pre_update_row' => 'not a row'],
			['_uuid' => 'n1', '_register' => 10, '_schema' => 7, 'object_status' => 'unchanged'],
			['_uuid' => 'x1', '_register' => 10, '_schema' => 7, 'object_status' => 'weird'],
			['uuid' => 'x2', 'object_status' => 'created'],
			['_uuid' => 'f1', '_register' => 10, '_schema' => 404, 'object_status' => 'created'],
			['_uuid' => 'b1', '_register' => 10, '_schema' => 7, 'object_status' => 'created', 'boom' => true],
		];
	}//end classifiedRows()

	/**
	 * Call a private method.
	 *
	 * @param SaveObjects $handler The handler
	 * @param string      $method  The method
	 * @param array       $args    The arguments, references kept
	 *
	 * @return mixed
	 */
	private function call(SaveObjects $handler, string $method, array $args): mixed {
		return (new ReflectionMethod(SaveObjects::class, $method))->invokeArgs($handler, $args);
	}//end call()

	/**
	 * A value with entities replaced by their serialisation, so it can be compared.
	 *
	 * @param mixed $value The value
	 *
	 * @return mixed
	 */
	private static function plain(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return ['entity' => $value->getUuid(), 'object' => $value->getObject(), 'name' => $value->getName()];
		}

		if (is_array($value) === true) {
			return array_map(static fn ($item) => self::plain(value: $item), $value);
		}

		// A uuid the save generated differs per run; the recorded one is fixed.
		if (is_string($value) === true && $value !== self::REFERENCE
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1
		) {
			return 'generated-uuid';
		}

		return $value;
	}//end plain()

	/**
	 * The handler with recording collaborators.
	 *
	 * @param BulkRowRules|null $rowRules The row rules
	 *
	 * @return SaveObjects
	 */
	private function handler(?BulkRowRules $rowRules = null): SaveObjects {
		$test   = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param SaveObjectsPipelineGoldenTest $test The test that keeps the lines
			 */
			public function __construct(private readonly SaveObjectsPipelineGoldenTest $test) {
			}//end __construct()

			/**
			 * Record a log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array             $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context=[]): void {
				unset($context['file'], $context['line']);
				$this->test->logLine(line: $level.': '.$message.' '.json_encode($context));
			}//end log()
		};

		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($account);
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getOrganisationForNewEntity')->willReturn('org-1');
		$organisations->method('hasAccessToOrganisation')->willReturn(false);

		return new SaveObjects(
			stores: new BulkSaveStores(
				objectEntityMapper: $this->objectMapper(),
				schemaMapper: $this->schemaMapper(),
				registerMapper: $this->registerMapper(),
				saveHandler: $this->createMock(SaveObject::class)
			),
			session: new BulkSaveSession(userSession: $session, organisationService: $organisations),
			reporting: new BulkSaveReporting(logger: $logger),
			checks: new BulkRowChecks(rowRules: $rowRules)
		);
	}//end handler()

	/**
	 * Keep a log line.
	 *
	 * @param string $line The line
	 *
	 * @return void
	 */
	public function logLine(string $line): void {
		$this->lines[] = preg_replace_callback(
			'/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/',
			static fn (array $match): string => self::plain(value: $match[0]),
			$line
		);
	}//end logLine()

	/**
	 * A mapper that records the bulk save and converts rows unless they carry `boom`.
	 *
	 * @return MagicMapper
	 */
	private function objectMapper(): MagicMapper {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('ultraFastBulkSave')->willReturnCallback(
			function (...$args): array {
				$this->calls[] = 'ultraFastBulkSave '.json_encode(
					[$args[0], $args[1], ($args[2] ?? null)?->getId(), ($args[3] ?? null)?->getId(), ($args[4] ?? 'default')]
				);
				return ['saved'];
			}
		);
		$mapper->method('convertRowToObjectEntity')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->calls[] = 'convert '.json_encode([$args[0]['_uuid'] ?? null, $args[1]->getId(), $args[2]->getId()]);
				if (isset($args[0]['boom']) === true) {
					throw new RuntimeException('cannot convert');
				}

				return $this->entity(uuid: (string) $args[0]['_uuid'], object: ['from' => 'row']);
			}
		);

		return $mapper;
	}//end objectMapper()

	/**
	 * A schema mapper that knows schema 7.
	 *
	 * @return SchemaMapper
	 */
	private function schemaMapper(): SchemaMapper {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args): Schema {
				$this->calls[] = 'schema find '.json_encode($args[0]);
				if ((int) $args[0] !== 7) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('no schema');
				}

				return $this->schema(id: 7, properties: []);
			}
		);

		return $mapper;
	}//end schemaMapper()

	/**
	 * A register mapper that knows register 10.
	 *
	 * @return RegisterMapper
	 */
	private function registerMapper(): RegisterMapper {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->calls[] = 'register find '.json_encode($args[0]);
				if ((int) $args[0] !== 10) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('no register');
				}

				return $this->register(id: 10);
			}
		);

		return $mapper;
	}//end registerMapper()

	/**
	 * A schema.
	 *
	 * @param int   $id         The id
	 * @param array $properties The properties
	 *
	 * @return Schema
	 */
	private function schema(int $id, array $properties): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * A register.
	 *
	 * @param int $id The id
	 *
	 * @return Register
	 */
	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);

		return $register;
	}//end register()

	/**
	 * An object entity.
	 *
	 * @param string $uuid   The uuid
	 * @param array  $object The business data
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object);

		return $entity;
	}//end entity()
}//end class
