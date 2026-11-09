<?php

declare(strict_types=1);

/**
 * Golden record of the name warm-up, the preload and the table name queries of CacheHandler
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\IAppContainer;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IMemcache;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Pins what the warm-up, the preload and the table queries answer, log and store.
 */
class CacheHandlerNameWarmupGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/cache-handler-name-warmup-golden.json';
	private const UUID_A = 'aaaaaaaa-0000-0000-0000-000000000001';
	private const UUID_B = 'bbbbbbbb-0000-0000-0000-000000000002';
	private const UUID_C = 'cccccccc-0000-0000-0000-000000000003';
	private const ORG = 'dddddddd-0000-0000-0000-000000000004';

	/** @var list<array<string, mixed>> Everything the handler did. */
	private array $calls = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$this->calls = [];
			$answer = null;
			try {
				$answer = $scenario();
			} catch (\Throwable $e) {
				$answer = ['thrown' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = ['answer' => $this->plain($answer), 'calls' => $this->calls];
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertStringNotContainsString('PHPUnit', (string) json_encode($actual));
		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));
	}

	/**
	 * The scenarios, by name.
	 *
	 * @return array<string, callable(): mixed>
	 */
	private function scenarios(): array {
		return array_merge($this->preloadScenarios(), $this->warmupScenarios(), $this->tableScenarios());
	}

	/**
	 * Preload scenarios.
	 *
	 * @return array<string, callable(): mixed>
	 */
	private function preloadScenarios(): array {
		$a = $this->object(uuid: self::UUID_A, name: 'A');
		$b = $this->object(uuid: self::UUID_B, name: 'B');

		return [
			'preload nothing' => fn () => $this->handler([])->preloadObjects([]),
			'preload from the database' => function () use ($a, $b) {
				$handler = $this->handler(['findMultiple' => [$a, $b]]);
				return [$handler->preloadObjects([self::UUID_A, self::UUID_B, self::UUID_A]), $handler->getStats()['preloads']];
			},
			'preload all cached keeps positions' => function () use ($a, $b) {
				$handler = $this->handler(['findMultiple' => [$a, $b]]);
				$handler->preloadObjects([self::UUID_A, self::UUID_B]);
				$this->calls = [];
				return $handler->preloadObjects(['x' => self::UUID_B, 'y' => self::UUID_A]);
			},
			'preload loads only what is missing' => function () use ($a, $b) {
				$handler = $this->handler(['findMultiple' => [$a]]);
				$handler->preloadObjects([self::UUID_A]);
				$this->calls = [];
				$second = $this->handler(['findMultiple' => [$b]]);
				return [$second->preloadObjects([self::UUID_B, self::UUID_C]), $second->getStats()['preloads']];
			},
			'preload failure' => fn () => $this->handler(['findMultiple' => new RuntimeException('down')])->preloadObjects([self::UUID_A, self::UUID_B]),
			'preload in a register' => fn () => $this->handler(['acrossMagic' => [$a], 'findMultiple' => [$b]])->preloadObjects([self::UUID_A, self::UUID_B], 7),
		];
	}

	/**
	 * Warm-up scenarios.
	 *
	 * @return array<string, callable(): mixed>
	 */
	private function warmupScenarios(): array {
		$rows = [['_uuid' => self::UUID_A, '_name' => 'Named', '_organisation' => self::ORG],
			['_uuid' => self::UUID_B, '_name' => '  ', '_organisation' => null],
			['_uuid' => null, '_name' => 'Skipped'], ['_uuid' => self::UUID_C]];
		$objects = [$this->object(uuid: self::UUID_A, name: 'From objects'), $this->object(uuid: self::ORG, name: 'Hidden by the organisation')];
		$warm = fn (array $setup) => function () use ($setup) {
			$handler = $this->handler($setup);
			return [$handler->warmupNameCache(), $handler->getStats(), $this->memory($handler)];
		};

		return [
			'warm-up without magic tables' => $warm(['findAll' => $objects, 'organisations' => [$this->organisation()], 'noMagic' => true]),
			'warm-up with a magic table' => $warm(['findAll' => $objects, 'organisations' => [$this->organisation()], 'rows' => $rows]),
			'warm-up with an unreadable table' => $warm(['findAll' => $objects, 'rows' => new RuntimeException('no table')]),
			'warm-up with a table outside magic mapping' => $warm(['findAll' => $objects, 'rows' => $rows, 'magicOff' => true]),
			'warm-up with an unknown schema' => $warm(['findAll' => [], 'rows' => $rows, 'schemaMissing' => true]),
			'warm-up with failing registers' => $warm(['findAll' => $objects, 'registers' => new RuntimeException('no registers')]),
			'warm-up with a register failing halfway' => $warm(['findAll' => [], 'rows' => $rows, 'brokenSecondRegister' => true]),
			'warm-up with failing organisations' => $warm(['organisations' => new RuntimeException('no organisations')]),
			'warm-up, distributed store failing' => $warm(['findAll' => $objects, 'noMagic' => true, 'setFails' => 'all']),
			'warm-up, distributed store failing after the first' => $warm(['findAll' => $objects, 'noMagic' => true, 'setFails' => 'second']),
			'warm-up, count not stored' => $warm(['findAll' => $objects, 'noMagic' => true, 'setFails' => 'metadata']),
			'warm-up without a distributed cache' => $warm(['findAll' => $objects, 'noMagic' => true, 'noCache' => true]),
			'all names warm the first time only' => function () use ($objects) {
				$handler = $this->handler(['findAll' => $objects, 'noMagic' => true, 'readable' => [self::UUID_A]]);
				$first = $handler->getAllObjectNames();
				$second = $handler->getAllObjectNames();
				$forced = $handler->getAllObjectNames(true);
				return [$first, $second, $forced, $handler->getStats()['name_warmups']];
			},
		];
	}

	/**
	 * Table name query scenarios.
	 *
	 * @return array<string, callable(): mixed>
	 */
	private function tableScenarios(): array {
		$rows = [['_uuid' => self::UUID_A, 'name_value' => 'Named', '_organisation' => 12],
			['_uuid' => self::UUID_B, 'name_value' => '  ', '_organisation' => self::ORG],
			['_uuid' => self::UUID_C, 'name_value' => 0]];
		$query = fn (array $columns, ?string $source = '1:5', array $uuids = [self::UUID_A, self::UUID_B]) => function () use ($columns, $source, $uuids) {
			$handler = $this->handler(['columns' => $columns]);
			$method = (new ReflectionClass($handler))->getMethod('queryTableForNames');
			$method->setAccessible(true);
			return $method->invoke($handler, 'tbl', $uuids, $source);
		};

		return [
			'table: no identifiers' => $query([], '1:5', []),
			'table: first column answers' => $query(['_name' => $rows]),
			'table: a missing column moves on' => $query(['_name' => 'prepare', 'naam' => $rows]),
			'table: an empty column moves on' => $query(['_name' => [], 'naam' => [], 'name' => $rows]),
			'table: blank names move on' => $query(['_name' => [['_uuid' => self::UUID_A, 'name_value' => ' ']], 'title' => $rows]),
			'table: a failure halfway keeps what it read' => $query(['_name' => [$rows[0], 'fetch'], 'naam' => [$rows[2]]]),
			'table: every column fails' => $query(['_name' => 'prepare', 'naam' => 'execute', 'name' => 'prepare', 'title' => 'prepare']),
			'table: no source' => $query(['_name' => $rows], null),
		];
	}

	/**
	 * The handler's name memory.
	 *
	 * @param CacheHandler $handler The handler.
	 *
	 * @return array<string, mixed>
	 */
	private function memory(CacheHandler $handler): array {
		$out = [];
		$ref = new ReflectionClass($handler);
		foreach (['nameCache', 'nameOrganisations', 'nameSources'] as $property) {
			$prop = $ref->getProperty($property);
			$prop->setAccessible(true);
			$out[$property] = $prop->getValue($handler);
		}

		return $out;
	}

	/**
	 * Objects as their uuid, anything else as it is.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return mixed
	 */
	private function plain(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return 'object ' . $value->getUuid();
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->plain($item), $value);
		}

		return $value;
	}

	/**
	 * An object.
	 *
	 * @param string $uuid The uuid.
	 * @param string $name The name.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $name): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		$object->setOrganisation(self::ORG);
		$object->setRegister('1');
		$object->setSchema('5');
		return $object;
	}

	/**
	 * An organisation.
	 *
	 * @return Organisation
	 */
	private function organisation(): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid(self::ORG);
		$organisation->setName('The organisation');
		return $organisation;
	}

	/**
	 * Answer a set-up value, throwing it when it is an exception.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function answer(mixed $value): mixed {
		if ($value instanceof \Throwable) {
			throw $value;
		}

		return $value;
	}

	/**
	 * The object mapper double.
	 *
	 * @param array $setup The scenario set-up.
	 *
	 * @return IAppContainer
	 */
	private function container(array $setup): IAppContainer {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$record = function (string $what, mixed ...$args): void {
			$this->calls[] = ['mapper' => $what, 'args' => $this->plain($args)];
		};
		$mapper->method('findMultiple')->willReturnCallback(function (array $ids) use ($setup, $record) {
			$record('findMultiple', $ids);
			return self::answer($setup['findMultiple'] ?? []);
		});
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturnCallback(function (array $uuids, mixed ...$rest) use ($setup, $record) {
			$record('acrossMagic', $uuids, $rest);
			return self::answer($setup['acrossMagic'] ?? []);
		});
		$mapper->method('findAll')->willReturnCallback(fn () => self::answer($setup['findAll'] ?? []));
		$mapper->method('filterReadableUuids')->willReturn($setup['readable'] ?? []);

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturn($mapper);
		return $container;
	}

	/**
	 * The distributed cache double.
	 *
	 * @param array $setup The scenario set-up.
	 *
	 * @return ICacheFactory
	 */
	private function cacheFactory(array $setup): ICacheFactory {
		$sets = 0;
		$memcache = $this->createMock(IMemcache::class);
		$memcache->method('set')->willReturnCallback(function ($key, $value, $ttl = 0) use ($setup, &$sets) {
			$sets++;
			$this->calls[] = ['set' => $key, 'value' => $value, 'ttl' => $ttl];
			$mode = ($setup['setFails'] ?? '');
			if ($mode === 'all' || ($mode === 'second' && $sets === 2) || ($mode === 'metadata' && $key === '_metadata_count')) {
				throw new RuntimeException('store down');
			}

			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($memcache);
		return $factory;
	}

	/**
	 * The database double: magic rows through executeQuery, named columns through prepare.
	 *
	 * @param array $setup The scenario set-up.
	 *
	 * @return IDBConnection
	 */
	private function database(array $setup): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(function (string $sql) use ($setup) {
			$this->calls[] = ['sql' => $sql];
			$rows = self::answer($setup['rows'] ?? []);
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
			return $result;
		});
		$db->method('prepare')->willReturnCallback(function (string $sql) use ($setup) {
			$this->calls[] = ['sql' => preg_replace('/\s+/', ' ', $sql)];
			preg_match('/_organisation, (\w+) as/', $sql, $match);
			return $this->statement(steps: ($setup['columns'][$match[1]] ?? []));
		});
		return $db;
	}

	/**
	 * A prepared statement answering rows, or failing at the named step.
	 *
	 * @param array|string $steps The rows, with 'fetch' for a failing row, or 'prepare' or 'execute'.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(array|string $steps): IPreparedStatement {
		if ($steps === 'prepare') {
			throw new RuntimeException('no such column');
		}

		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('bindValue')->willReturnCallback(function ($index, $value) {
			$this->calls[] = ['bind' => $index, 'value' => $value];
			return true;
		});
		if ($steps === 'execute') {
			$statement->method('execute')->willThrowException(new RuntimeException('no such column'));
			return $statement;
		}

		$steps[] = false;
		$statement->method('fetch')->willReturnCallback(function () use (&$steps) {
			$step = array_shift($steps);
			if ($step === 'fetch') {
				throw new RuntimeException('connection lost');
			}

			return $step;
		});
		return $statement;
	}

	/**
	 * A handler over the doubles.
	 *
	 * @param array $setup The scenario set-up.
	 *
	 * @return CacheHandler
	 */
	private function handler(array $setup): CacheHandler {
		$organisations = $this->createMock(OrganisationMapperInterface::class);
		$organisations->method('findAllWithUserCount')->willReturnCallback(fn () => self::answer($setup['organisations'] ?? []));

		$register = new class(function ($schemaId, $slug) use ($setup) {
			$this->calls[] = ['magic' => $schemaId, 'slug' => $slug];
			return ($setup['magicOff'] ?? false) === false;
		}) extends Register {
			/**
			 * A register with one schema, whose magic mapping the callback answers.
			 *
			 * @param \Closure $magic The answer.
			 */
			public function __construct(private \Closure $magic) {
				parent::__construct();
				$this->setId(1);
			}

			/**
			 * The one schema.
			 *
			 * @return array
			 */
			public function getSchemas(): array {
				return [5];
			}

			/**
			 * What the callback says.
			 *
			 * @param int         $schemaId   The schema.
			 * @param string|null $schemaSlug The slug.
			 *
			 * @return bool
			 */
			public function isMagicMappingEnabledForSchema(int $schemaId, ?string $schemaSlug = null): bool {
				return ($this->magic)($schemaId, $schemaSlug);
			}
		};
		$broken = new class extends Register {
			/**
			 * A register that cannot list its schemas.
			 *
			 * @return array
			 */
			public function getSchemas(): array {
				throw new RuntimeException('no schemas');
			}
		};
		$all = [$register];
		if (($setup['brokenSecondRegister'] ?? false) === true) {
			$all[] = $broken;
		}

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willReturnCallback(fn () => self::answer($setup['registers'] ?? $all));

		$schema = new Schema();
		$schema->setSlug('person');
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(fn () => self::answer(($setup['schemaMissing'] ?? false) ? new RuntimeException('gone') : $schema));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
				unset($context['file'], $context['line'], $context['execution_time']);
				ksort($context);
				$this->calls[] = ['log' => $level, 'message' => (string) $message, 'context' => $context];
			});
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$noMagic = ($setup['noMagic'] ?? false);

		return new CacheHandler(
			organisationMapper: $organisations,
			logger: $logger,
			cacheFactory: ($setup['noCache'] ?? false) ? null : $this->cacheFactory($setup),
			userSession: $session,
			container: $this->container($setup),
			registerMapper: $registers,
			schemaMapper: $schemas,
			db: $noMagic ? null : $this->database($setup)
		);
	}
}
