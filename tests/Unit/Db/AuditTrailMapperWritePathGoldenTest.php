<?php

/**
 * Golden record of the AuditTrailMapper write path: who a row names, what it holds, when an edit merges.
 *
 * Pins the rows recordObjectEvent(), recordToolInvocation(),
 * recordSharedMasterDataRefusal() and recordExport() write into the
 * migrated table, the merge decisions createAuditTrail() takes under an
 * aggregation window (every way an edit is refused a merge, and the merge
 * itself), the multi-row INSERT insertAuditTrails() issues and the rows it
 * refuses, and toIsoMoment(). Generated uuids and timestamps are left out:
 * they differ on every run.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\ToolInvocation;
use OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusalOptions;
use OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusal;
use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions;
use OCA\OpenRegister\Db\AuditTrail\ExportOutcome;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class AuditTrailMapperWritePathGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/audit-trail-write-path-golden.json';

	/**
	 * Columns that differ per run and are left out of the record.
	 */
	private const VOLATILE = ['uuid', 'created', 'expires'];

	/**
	 * Warnings the mapper logged, per case.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Every outcome matches the recording.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::entryCases() as $name => $write) {
			$actual['entry: ' . $name] = $this->rowsAfter(write: $write, uid: ($name === 'session user' || str_ends_with($name, 'with session') === true ? 'alice' : null));
		}

		foreach (self::mergeCases() as $name => $case) {
			$actual['merge: ' . $name] = $this->mergeOutcome(case: $case);
		}

		foreach (self::bulkCases() as $name => $entries) {
			$actual['bulk: ' . $name] = $this->bulkOutcome(entries: $entries, chunkSize: 2);
		}

		foreach (self::momentCases() as $name => $value) {
			$method = new ReflectionMethod(AuditTrailMapper::class, 'toIsoMoment');
			$actual['moment: ' . $name] = $method->invoke($this->sqliteMapper(database: null, uid: null, window: null), $value);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testOutcomesMatchGolden()

	/**
	 * Direct entry writes, each a closure over the mapper.
	 *
	 * @return array<string, callable(AuditTrailMapper): mixed>
	 */
	private static function entryCases(): array {
		$object = static function (): ObjectEntity {
			$object = new ObjectEntity();
			$object->setId(7);
			$object->setUuid('u-7');
			$object->setRegister('1');
			$object->setSchema('2');
			return $object;
		};

		return [
			'archival no actor no session' => fn (AuditTrailMapper $m) => $m->recordObjectEvent(new ObjectAuditEvent($object(), 'archival.destroyed', ['k' => 'v'])),
			'archival no actor with session' => fn (AuditTrailMapper $m) => $m->recordObjectEvent(new ObjectAuditEvent($object(), 'archival.destroyed')),
			'archival actor and name' => fn (AuditTrailMapper $m) => $m->recordObjectEvent(new ObjectAuditEvent($object(), 'registry.update', [], new ObjectAuditEventOptions(new NamedActor('registry:brp', 'BRP')))),
			'archival actor without name' => fn (AuditTrailMapper $m) => $m->recordObjectEvent(new ObjectAuditEvent($object(), 'registry.update', [], new ObjectAuditEventOptions(new NamedActor('registry:brp')))),
			'archival name without actor with session' => fn (AuditTrailMapper $m) => $m->recordObjectEvent(new ObjectAuditEvent($object(), 'x', [], new ObjectAuditEventOptions(new NamedActor(null, 'Ignored')))),
			'archival ip trimmed' => fn (AuditTrailMapper $m) => $m->recordObjectEvent(new ObjectAuditEvent($object(), 'link.opened', [], new ObjectAuditEventOptions(new NamedActor('link:1'), ' 10.0.0.1 '))),
			'archival ip blank' => fn (AuditTrailMapper $m) => $m->recordObjectEvent(new ObjectAuditEvent($object(), 'link.opened', [], new ObjectAuditEventOptions(new NamedActor('link:1'), '   '))),
			'archival ip empty' => fn (AuditTrailMapper $m) => $m->recordObjectEvent(new ObjectAuditEvent($object(), 'link.opened', [], new ObjectAuditEventOptions(new NamedActor('link:1'), ''))),
			'tool dotted no session' => fn (AuditTrailMapper $m) => $m->recordToolInvocation(new ToolInvocation('keepiq.lead.search', 'd1', ['count' => 2])),
			'tool undotted with session' => fn (AuditTrailMapper $m) => $m->recordToolInvocation(new ToolInvocation('search', 'd2', [], new AuditedObject(1, 2, 3, 'o-3'))),
			'tool trailing dot with session' => fn (AuditTrailMapper $m) => $m->recordToolInvocation(new ToolInvocation('keepiq.', 'd3', ['isError' => true])),
			'refusal minimal' => fn (AuditTrailMapper $m) => $m->recordSharedMasterDataRefusal(new SharedMasterDataRefusal('org-h', 'org-s', 'register')),
			'refusal empty actor and reference' => fn (AuditTrailMapper $m) => $m->recordSharedMasterDataRefusal(new SharedMasterDataRefusal('org-h', 'org-s', 'schema', new SharedMasterDataRefusalOptions(['a' => 1], new AuditedObject(4, 5, null, 'o-1'), new NamedActor('', '')))),
			'refusal actor no reference' => fn (AuditTrailMapper $m) => $m->recordSharedMasterDataRefusal(new SharedMasterDataRefusal('org-h', 'org-s', 'schema', new SharedMasterDataRefusalOptions(actor: new NamedActor('bob')))),
			'refusal actor and reference' => fn (AuditTrailMapper $m) => $m->recordSharedMasterDataRefusal(new SharedMasterDataRefusal('org-h', 'org-s', 'schema', new SharedMasterDataRefusalOptions(actor: new NamedActor('bob', 'p-123')))),
			'refusal context cannot override outcome' => fn (AuditTrailMapper $m) => $m->recordSharedMasterDataRefusal(new SharedMasterDataRefusal('org-h', 'org-s', 'register', new SharedMasterDataRefusalOptions(['outcome' => 'allowed', 'holderOrganisation' => 'forged', 'extra' => 'x']))),
			'refusal reference without actor with session' => fn (AuditTrailMapper $m) => $m->recordSharedMasterDataRefusal(new SharedMasterDataRefusal('org-h', 'org-s', 'register', new SharedMasterDataRefusalOptions(actor: new NamedActor(null, 'p-9')))),
			'export no actor no session' => fn (AuditTrailMapper $m) => $m->recordExport(new ExportOutcome('completed', ['rows' => 3])),
			'export no actor with session' => fn (AuditTrailMapper $m) => $m->recordExport(new ExportOutcome('refused', ['reason' => 'cap'], new AuditedObject(1, 2))),
			'export actor' => fn (AuditTrailMapper $m) => $m->recordExport(new ExportOutcome('completed', [], new AuditedObject(), 'svc-export')),
			'export empty actor' => fn (AuditTrailMapper $m) => $m->recordExport(new ExportOutcome('completed', [], new AuditedObject(), '')),
			'session user' => fn (AuditTrailMapper $m) => $m->recordToolInvocation(new ToolInvocation('a.b', 'd4', [])),
		];
	}//end entryCases()

	/**
	 * Merge cases: window, the two edits, and what happens to the first row before the second edit.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function mergeCases(): array {
		$a = ['title' => 'A'];
		$b = ['title' => 'B'];
		$c = ['title' => 'C', 'note' => 'n'];
		return [
			'no window configured' => ['window' => 0, 'edits' => [[$a, $b], [$b, $c]]],
			'window merges two updates' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]]],
			'window merges three updates' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c], [$c, $a]]],
			'create is never merged into' => ['window' => 3600, 'edits' => [[null, $b], [$b, $c]]],
			'delete is never merged' => ['window' => 3600, 'edits' => [[$a, $b], [$b, null]]],
			'different actor' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]], 'actors' => ['alice', 'bob']],
			'same actor' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]], 'actors' => ['alice', 'alice']],
			'actor after no actor' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]], 'actors' => [null, 'alice']],
			'previous too old' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]], 'tamper' => ['created' => '2000-01-01 00:00:00']],
			'previous in the future' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]], 'tamper' => ['created' => '2999-01-01 00:00:00']],
			'previous sealed' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]], 'tamper' => ['hash' => 'sealed']],
			'previous for another object' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]], 'tamper' => ['object_uuid' => 'other']],
			'object without uuid' => ['window' => 3600, 'edits' => [[$a, $b], [$b, $c]], 'uuid' => null],
			'app config not an app config' => ['window' => 'not-config', 'edits' => [[$a, $b], [$b, $c]]],
			'app config unavailable' => ['window' => 'throws', 'edits' => [[$a, $b], [$b, $c]]],
			'window clamped from negative' => ['window' => -5, 'edits' => [[$a, $b], [$b, $c]]],
		];
	}//end mergeCases()

	/**
	 * Bulk cases: the entries passed to insertAuditTrails().
	 *
	 * @return array<string, callable(): array>
	 */
	private static function bulkCases(): array {
		$row = static function (string $uuid, array $set): AuditTrail {
			$trail = new AuditTrail();
			$trail->setUuid($uuid);
			foreach ($set as $setter => $value) {
				$trail->$setter($value);
			}

			return $trail;
		};

		return [
			'empty' => static fn (): array => [],
			'one row' => static fn (): array => [$row('b-1', ['setAction' => 'create', 'setChanged' => ['t' => 1], 'setUser' => 'u'])],
			'fields differ per row, chunked' => static fn (): array => [
				$row('b-1', ['setAction' => 'create', 'setUser' => 'u']),
				$row('b-2', ['setAction' => 'update', 'setProcessingActivityId' => 'pa-1']),
				$row('b-3', ['setAction' => 'delete', 'setImportJobId' => 'job-1', 'setSize' => 20]),
			],
			'id set on a row is not a column' => static fn (): array => [$row('b-1', ['setId' => 99, 'setAction' => 'create'])],
			'unknown uuid in the readback' => static fn (): array => [$row('b-1', ['setAction' => 'create']), $row('b-ghost', ['setAction' => 'read'])],
			'row without uuid is refused' => static fn (): array => [$row('b-1', ['setAction' => 'create']), new AuditTrail()],
			'row with empty uuid is refused' => static fn (): array => [$row('', ['setAction' => 'create'])],
			'scalar entry is refused' => static fn (): array => [$row('b-1', ['setAction' => 'create']), 'nope'],
			'null entry is refused' => static fn (): array => [null],
		];
	}//end bulkCases()

	/**
	 * Values toIsoMoment() is asked to render.
	 *
	 * @return array<string, mixed>
	 */
	private static function momentCases(): array {
		return [
			'datetime' => new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC')),
			'datetime with offset' => new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('+02:00')),
			'utc string' => '2026-03-04 05:06:07',
			'empty string' => '',
			'unreadable string' => 'not a date',
			'integer' => 1700000000,
			'null' => null,
		];
	}//end momentCases()

	/**
	 * The rows in the table after one write, or the exception it threw.
	 *
	 * @param callable $write The write.
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return array<string, mixed>
	 */
	private function rowsAfter(callable $write, ?string $uid): array {
		$database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);
		$mapper = $this->sqliteMapper(database: $database, uid: $uid, window: null);
		try {
			$returned = $write($mapper);
		} catch (\Throwable $e) {
			return ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		return ['returnedId' => $returned->getId(), 'rows' => $this->rows(database: $database)];
	}//end rowsAfter()

	/**
	 * The rows and warnings after a sequence of createAuditTrail() edits.
	 *
	 * @param array<string, mixed> $case The merge case.
	 *
	 * @return array<string, mixed>
	 */
	private function mergeOutcome(array $case): array {
		$database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);
		$this->logged = [];
		$returned = [];
		foreach ($case['edits'] as $index => [$old, $new]) {
			$actor = ($case['actors'][$index] ?? null);
			$mapper = $this->sqliteMapper(database: $database, uid: $actor, window: $case['window']);
			$uuid = array_key_exists('uuid', $case) === true ? $case['uuid'] : 'u-7';
			$trail = $mapper->createAuditTrail(
				old: self::object(data: $old, uuid: $uuid),
				new: self::object(data: $new, uuid: $uuid)
			);
			$returned[] = $trail->getId();
			if ($index === 0 && isset($case['tamper']) === true) {
				$database->connection()->update('openregister_audit_trails', $case['tamper'], ['id' => $trail->getId()]);
			}
		}

		return ['returnedIds' => $returned, 'rows' => $this->rows(database: $database), 'warnings' => $this->logged];
	}//end mergeOutcome()

	/**
	 * The statements, readback and result of one insertAuditTrails() call.
	 *
	 * @param callable $entries Builds the entries.
	 * @param int $chunkSize The chunk size.
	 *
	 * @return array<string, mixed>
	 */
	private function bulkOutcome(callable $entries, int $chunkSize): array {
		$log = [];
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getTableName')->willReturnCallback(static fn (string $table): string => '*PREFIX*' . $table);
		$db->method('getQueryBuilder')->willReturn($qb);
		$platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
		$platform->method('quoteIdentifier')->willReturnCallback(static fn (string $id): string => '`' . $id . '`');
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []) use (&$log): int {
				$log[] = ['statement' => $sql, 'params' => $params];
				return 1;
			}
		);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use (&$log): IResult {
				$log[] = ['query' => $sql, 'params' => $params];
				$rows = [];
				foreach ($params as $index => $uuid) {
					if ($uuid === 'b-ghost') {
						continue;
					}

					$rows[] = ['id' => (string)(100 + $index), 'uuid' => $uuid];
				}

				$rows[] = ['id' => '999', 'uuid' => 'not-in-chunk'];
				$rows[] = false;
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
				$result->method('closeCursor')->willReturnCallback(
					function () use (&$log): bool {
						$log[] = 'closeCursor';
						return true;
					}
				);
				return $result;
			}
		);

		$mapper = new AuditTrailMapper($db, new AuditTrailMapperServices($this->container(window: null), $this->logger(), $this->session(uid: null), $this->request()));
		try {
			$inserted = $mapper->insertAuditTrails($entries(), $chunkSize);
		} catch (\Throwable $e) {
			return ['thrown' => get_class($e), 'message' => $e->getMessage(), 'log' => $log];
		}

		$ids = [];
		foreach ($inserted as $trail) {
			$ids[] = [$trail->getUuid(), $trail->getId()];
		}

		return ['ids' => $ids, 'log' => $log];
	}//end bulkOutcome()

	/**
	 * Every row in the table, volatile columns left out.
	 *
	 * @param MigratedSqliteDatabase $database The database.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(MigratedSqliteDatabase $database): array {
		$rows = $database->connection()->fetchAllAssociative('SELECT * FROM openregister_audit_trails ORDER BY id');
		$out = [];
		foreach ($rows as $row) {
			foreach (self::VOLATILE as $column) {
				unset($row[$column]);
			}

			$out[] = array_filter($row, static fn ($value): bool => $value !== null);
		}

		return $out;
	}//end rows()

	/**
	 * An object with the given data, or null.
	 *
	 * @param array|null $data The data, or null for no object.
	 * @param string|null $uuid Its uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private static function object(?array $data, ?string $uuid): ?ObjectEntity {
		if ($data === null) {
			return null;
		}

		$object = new ObjectEntity();
		$object->setId(7);
		if ($uuid !== null) {
			$object->setUuid($uuid);
		}

		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * A mapper over the migrated table (or a bare connection when none is given).
	 *
	 * @param MigratedSqliteDatabase|null $database The database.
	 * @param string|null $uid The signed-in uid.
	 * @param int|string|null $window The aggregation window, 'not-config', 'throws', or null for none.
	 *
	 * @return AuditTrailMapper
	 */
	private function sqliteMapper(?MigratedSqliteDatabase $database, ?string $uid, int|string|null $window): AuditTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		if ($database !== null) {
			$db = $database->idbConnection();
		}

		return new AuditTrailMapper($db, new AuditTrailMapperServices($this->container(window: $window), $this->logger(), $this->session(uid: $uid), $this->request()));
	}//end sqliteMapper()

	/**
	 * A container that answers IAppConfig according to the window and refuses everything else.
	 *
	 * @param int|string|null $window The window.
	 *
	 * @return ContainerInterface
	 */
	private function container(int|string|null $window): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($window): mixed {
				if ($id !== IAppConfig::class || $window === null || $window === 'throws') {
					throw new \RuntimeException('not registered: ' . $id);
				}

				if ($window === 'not-config') {
					return new \stdClass();
				}

				$config = $this->createMock(IAppConfig::class);
				$config->method('getValueInt')->willReturn($window);
				return $config;
			}
		);

		return $container;
	}//end container()

	/**
	 * A session for the given uid, or none.
	 *
	 * @param string|null $uid The uid.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A logger that keeps the warnings it is given in $this->logged.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param AuditTrailMapperWritePathGoldenTest $test The test that keeps the messages.
			 */
			public function __construct(
				private readonly AuditTrailMapperWritePathGoldenTest $test,
			) {
			}

			/**
			 * Keep warnings and worse.
			 *
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				if (in_array($level, ['debug', 'info', 'notice'], true) === true) {
					return;
				}

				$this->test->keep(message: $level . ': ' . $message);
			}
		};
	}//end logger()

	/**
	 * Keep one logged message.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	public function keep(string $message): void {
		$this->logged[] = $message;
	}//end keep()

	/**
	 * A request with a fixed id and address.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		return $request;
	}//end request()
}//end class
