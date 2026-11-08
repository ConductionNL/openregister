<?php

/**
 * Golden record of the AuditTrailMapper read path: the queries it builds, what it groups, which activity it tags.
 *
 * Pins the exact queries and results of findByActor(), getProcessingActivities()
 * and countByPurpose(), the precedence and fall-through of the AVG processing
 * activity a row is tagged with (override, then schema, then register; with
 * every lookup that refuses or misses, and the RBAC/multitenancy flags each
 * lookup is made with), and the version handling and refusal of revertObject().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class AuditTrailMapperReadPathGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/audit-trail-read-path-golden.json';

	/**
	 * Calls made on collaborators and log lines, per case.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Every outcome matches the recording.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::actorCases() as $name => $args) {
			$actual['actor: ' . $name] = $this->recorded(
				rows: [[['id' => 1, 'uuid' => 'a-1', 'user' => 'alice']], [['total_count' => '7']]],
				call: fn (AuditTrailMapper $m): mixed => $this->summary(result: $m->findByActor(...$args))
			);
		}

		$actual['actor: no count row'] = $this->recorded(
			rows: [[], []],
			call: fn (AuditTrailMapper $m): mixed => $this->summary(result: $m->findByActor('alice'))
		);

		foreach (self::activityRowCases() as $name => $rows) {
			foreach ([null, 'org-1'] as $organisation) {
				$actual['activities: ' . $name . ' / ' . var_export($organisation, true)] = $this->recorded(
					rows: [$rows],
					call: fn (AuditTrailMapper $m): mixed => $m->getProcessingActivities($organisation)
				);
			}
		}

		$from = new DateTime('2026-01-01 00:00:00', new DateTimeZone('UTC'));
		$to = new DateTime('2026-02-01 00:00:00', new DateTimeZone('UTC'));
		$purposeRows = [
			['purpose' => 'zaakbehandeling', 'entry_count' => '4'],
			['purpose' => null, 'entry_count' => '2'],
			['purpose' => '', 'entry_count' => '3'],
			['purpose' => 0, 'entry_count' => 1],
			['purpose' => 'zaakbehandeling', 'entry_count' => '9'],
		];
		foreach (['none' => [null, null], 'from' => [$from, null], 'to' => [null, $to], 'both' => [$from, $to]] as $name => [$f, $t]) {
			$actual['purpose: ' . $name] = $this->recorded(
				rows: [$purposeRows],
				call: fn (AuditTrailMapper $m): mixed => $m->countByPurpose($f, $t)
			);
		}

		foreach (self::activityCases() as $name => $case) {
			$actual['tag: ' . $name] = $this->tagged(case: $case);
		}

		foreach (self::revertCases() as $name => $case) {
			$actual['revert: ' . $name] = $this->reverted(case: $case);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testOutcomesMatchGolden()

	/**
	 * Keep one collaborator call or log line.
	 *
	 * @param string $call The call.
	 *
	 * @return void
	 */
	public function keep(string $call): void {
		$this->calls[] = $call;
	}//end keep()

	/**
	 * Argument sets for findByActor().
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function actorCases(): array {
		return [
			'user only' => ['alice'],
			'paged' => ['alice', 10, 30],
			'action' => ['alice', 25, 0, 'update'],
			'empty strings' => ['alice', 25, 0, '', '', ''],
			'from' => ['bob', 25, 0, null, '2026-01-01'],
			'to' => ['bob', 25, 0, null, null, '2026-02-01'],
			'all' => ['bob', 5, 5, 'read', '2026-01-01', '2026-02-01'],
		];
	}//end actorCases()

	/**
	 * Row sets for getProcessingActivities().
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function activityRowCases(): array {
		return [
			'none' => [],
			'grouped' => [
				['action' => 'update', 'created' => '2026-03-02 10:00:00'],
				['action' => 'create', 'created' => '2026-03-01 09:00:00'],
				['action' => 'update', 'created' => '2026-03-03 11:00:00'],
				['action' => 'update', 'created' => '2026-02-28 08:00:00'],
			],
			'missing action and created' => [
				['created' => null],
				['action' => 'read'],
				['action' => 'read', 'created' => '2026-01-05 00:00:00'],
				['action' => 'read', 'created' => '2026-01-04 00:00:00'],
				['action' => 'read'],
			],
		];
	}//end activityRowCases()

	/**
	 * Processing-activity tagging cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function activityCases(): array {
		$known = ['pa-code' => 'uuid-code', 'pa-schema' => 'uuid-schema', 'pa-register' => 'uuid-register', 'pa-nouuid' => null];
		$annotated = static fn (mixed $value): array => ['x-openregister-processing-activity' => $value];
		return [
			'mapper unavailable' => ['vrw' => false, 'override' => 'pa-code', 'known' => $known],
			'override resolves' => ['override' => 'pa-code', 'schema' => 3, 'schemaConfig' => $annotated('pa-schema'), 'known' => $known],
			'override resolves without a uuid' => ['override' => 'pa-nouuid', 'schema' => 3, 'schemaConfig' => $annotated('pa-schema'), 'known' => $known],
			'override misses, schema resolves' => ['override' => 'typo', 'schema' => 3, 'schemaConfig' => $annotated('pa-schema'), 'known' => $known],
			'empty override, schema resolves' => ['override' => '', 'schema' => 'schema-uuid', 'schemaConfig' => $annotated('pa-schema'), 'known' => $known],
			'schema misses, register resolves' => ['schema' => 3, 'schemaConfig' => $annotated('typo'), 'register' => 4, 'registerConfig' => $annotated('pa-register'), 'known' => $known],
			'schema has no annotation' => ['schema' => 3, 'schemaConfig' => ['other' => 1], 'register' => 4, 'registerConfig' => $annotated('pa-register'), 'known' => $known],
			'schema annotation not a string' => ['schema' => 3, 'schemaConfig' => $annotated(5), 'register' => 4, 'registerConfig' => $annotated('pa-register'), 'known' => $known],
			'schema annotation empty' => ['schema' => 3, 'schemaConfig' => $annotated(''), 'register' => 4, 'registerConfig' => $annotated(['pa-register']), 'known' => $known],
			'schema lookup refused' => ['schema' => 3, 'schemaThrows' => true, 'register' => 4, 'registerConfig' => $annotated('pa-register'), 'known' => $known],
			'schema id zero string' => ['schema' => '0', 'register' => '0', 'known' => $known],
			'schema empty, register misses' => ['schema' => '', 'register' => 4, 'registerConfig' => $annotated('typo'), 'known' => $known],
			'register lookup refused' => ['register' => 4, 'registerThrows' => true, 'known' => $known],
			'register resolves without a uuid' => ['register' => 4, 'registerConfig' => $annotated('pa-nouuid'), 'known' => $known],
			'nothing set' => ['known' => $known],
		];
	}//end activityCases()

	/**
	 * revertObject() cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function revertCases(): array {
		$entry = ['id' => 5, 'uuid' => 'a-5', 'object_uuid' => 'o-1', 'action' => 'update', 'changed' => '{"title":{"old":"A","new":"B"}}'];
		return [
			'no entries, no point' => ['version' => '1.2.3', 'rows' => [[]]],
			'no entries, null version' => ['version' => null, 'rows' => [[]]],
			'no entries, keep version' => ['version' => '1.2.3', 'rows' => [[]], 'overwrite' => true],
			'entries reverted' => ['version' => '2.0.9', 'rows' => [[$entry]]],
			'point not found is refused' => ['version' => '1.0.0', 'until' => 'a-404', 'rows' => [[]]],
			'point found but nothing after it is refused' => ['version' => '1.0.0', 'until' => 'a-5', 'rows' => [[['id' => 5]], []]],
			'identifier looked up' => ['version' => '1.0.0', 'rows' => [[]], 'lookup' => true],
		];
	}//end revertCases()

	/**
	 * Run one call on a recording mapper.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $rows The row sets served to executeQuery().
	 * @param callable $call The call.
	 *
	 * @return array<string, mixed>
	 */
	private function recorded(array $rows, callable $call): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		try {
			$result = $call($this->mapper(container: $this->createMock(ContainerInterface::class)));
		} catch (\Throwable $e) {
			$result = ['thrown' => get_class($e) . ': ' . $e->getMessage()];
		}

		return ['result' => $result, 'queries' => $this->queryLog];
	}//end recorded()

	/**
	 * The ids and total of a findByActor() answer.
	 *
	 * @param array{results: array, total: int} $result The answer.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(array $result): array {
		return ['ids' => array_map(static fn ($trail) => $trail->getId(), $result['results']), 'total' => $result['total']];
	}//end summary()

	/**
	 * The activity uuid a row for the case's object is tagged with, and the calls made.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function tagged(array $case): array {
		$this->calls = [];
		$vrw = $this->createMock(VerwerkingsactiviteitMapper::class);
		$vrw->method('resolveReference')->willReturnCallback(
			function (string $reference) use ($case): ?Verwerkingsactiviteit {
				$this->keep(call: 'resolveReference(' . $reference . ')');
				if (array_key_exists($reference, $case['known']) === false) {
					return null;
				}

				$activity = new Verwerkingsactiviteit();
				if ($case['known'][$reference] !== null) {
					$activity->setUuid($case['known'][$reference]);
				}

				return $activity;
			}
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($case): Schema {
				$this->keep(call: 'schema.find(' . var_export($id, true) . ', rbac=' . var_export($_rbac, true) . ', mt=' . var_export($_multitenancy, true) . ')');
				if (($case['schemaThrows'] ?? false) === true) {
					throw new \RuntimeException('schema not found');
				}

				$schema = new Schema();
				$schema->setConfiguration($case['schemaConfig'] ?? null);
				return $schema;
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac = true, bool $_multitenancy = true) use ($case): Register {
				$this->keep(call: 'register.find(' . var_export($id, true) . ', rbac=' . var_export($_rbac, true) . ', mt=' . var_export($_multitenancy, true) . ')');
				if (($case['registerThrows'] ?? false) === true) {
					throw new \RuntimeException('register not found');
				}

				$register = new Register();
				$register->setConfiguration($case['registerConfig'] ?? null);
				return $register;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $vrw, $schemas, $registers): object {
				$this->keep(call: 'container.get(' . $id . ')');
				return match (true) {
					$id === VerwerkingsactiviteitMapper::class && ($case['vrw'] ?? true) === true => $vrw,
					$id === SchemaMapperInterface::class => $schemas,
					$id === RegisterMapperInterface::class => $registers,
					default => throw new \RuntimeException('not registered: ' . $id),
				};
			}
		);

		$object = new ObjectEntity();
		$object->setUuid('o-1');
		if (array_key_exists('override', $case) === true) {
			$object->setProcessingActivityId($case['override']);
		}

		if (array_key_exists('schema', $case) === true) {
			$object->setSchema((string)$case['schema']);
		}

		if (array_key_exists('register', $case) === true) {
			$object->setRegister((string)$case['register']);
		}

		$method = new ReflectionMethod(AuditTrailMapper::class, 'resolveProcessingActivityId');
		$result = $method->invoke($this->mapper(container: $container), $object);

		return ['result' => $result, 'calls' => $this->calls];
	}//end tagged()

	/**
	 * The version and data of a reverted object, or the refusal.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function reverted(array $case): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $case['rows'];
		$this->calls = [];

		$object = new ObjectEntity();
		$object->setId(1);
		$object->setUuid('o-1');
		$object->setVersion($case['version']);
		$object->setObject(['title' => 'B']);

		$container = $this->createMock(ContainerInterface::class);
		$magic = $this->createMock(MagicMapperInterface::class);
		$magic->method('find')->willReturnCallback(
			function (mixed $identifier) use ($object): ObjectEntity {
				$this->keep(call: 'magic.find(' . var_export($identifier, true) . ')');
				return $object;
			}
		);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => $id === MagicMapperInterface::class ? $magic : throw new \RuntimeException('not registered: ' . $id)
		);

		$identifier = $object;
		if (($case['lookup'] ?? false) === true) {
			$identifier = 'o-1';
		}

		try {
			$reverted = $this->mapper(container: $container)->revertObject($identifier, ($case['until'] ?? null), ($case['overwrite'] ?? false));
			$outcome = [
				'same instance' => $reverted === $object,
				'version' => $reverted->getVersion(),
				'object' => $reverted->getObject(),
				'original version' => $object->getVersion(),
			];
		} catch (\Throwable $e) {
			$outcome = ['thrown' => get_class($e) . ': ' . $e->getMessage()];
		}

		return $outcome + ['calls' => $this->calls, 'queries' => $this->queryLog];
	}//end reverted()

	/**
	 * A mapper over a recording connection.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(ContainerInterface $container): AuditTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(fn (string $value): string => addcslashes($value, '%_\\'));

		$test = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param AuditTrailMapperReadPathGoldenTest $test The test that keeps the lines.
			 */
			public function __construct(
				private readonly AuditTrailMapperReadPathGoldenTest $test,
			) {
			}

			/**
			 * Keep every line with its context.
			 *
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->test->keep(call: $level . ': ' . $message . ' ' . json_encode($context));
			}
		};

		return new AuditTrailMapper($db, $this->createMock(IUserSession::class), $this->createMock(IRequest::class), new AuditTrailMapperServices($container, $logger));
	}//end mapper()
}//end class
