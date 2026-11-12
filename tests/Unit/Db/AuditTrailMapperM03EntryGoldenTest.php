<?php

/**
 * Golden record of the AuditTrailMapper entry writers and per-object scopes the M03 lane reshapes.
 *
 * Pins the rows recordHardeningChange(), recordPurposeRefusal(),
 * recordPartyQueryRefusal() and recordSlugBackfill() write into the
 * migrated table, with and without a session user, and what countObjectTrail()
 * and tombstoneObjectTrail() count and leave behind for every action scope:
 * allowed rows and refused (excluded) rows alike. Generated uuids and
 * timestamps are left out: they differ on every run.
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

use OCA\OpenRegister\Db\AuditTrail\PartyQueryRefusal;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\SlugBackfill;
use OCA\OpenRegister\Db\AuditTrail\PurposeRefusal;
use OCA\OpenRegister\Db\AuditTrail\HardeningChange;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrail\ActionScope;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @coversNothing
 */
final class AuditTrailMapperM03EntryGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/audit-trail-m03-entry-golden.json';

	/**
	 * Columns that differ per run and are left out of the record.
	 */
	private const VOLATILE = ['uuid', 'created', 'expires', 'purged_at'];

	/**
	 * The query calls of the scope case running.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * Every outcome matches the recording.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach ([null, 'alice'] as $uid) {
			foreach (self::entryCases() as $name => $write) {
				$actual['entry: ' . $name . ' as ' . ($uid ?? 'nobody')] = $this->rowsAfter(write: $write, uid: $uid);
			}
		}

		foreach (self::scopeCases() as $name => $scope) {
			$actual['scope: ' . $name] = $this->scopeOutcome(only: $scope[0], exclude: $scope[1]);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testOutcomesMatchGolden()

	/**
	 * Mutation check: a refused hardening change is never recorded as a change.
	 *
	 * @return void
	 */
	public function testRefusedHardeningChangeIsNotRecordedAsChanged(): void {
		$rows = $this->rowsAfter(write: fn (AuditTrailMapper $m) => $m->recordHardeningChange(new HardeningChange('c', 1, 2, 'floor'), false), uid: null);

		$this->assertSame('hardening.refused', $rows['rows'][0]['action']);
		$this->assertStringContainsString('"refusal":"floor"', $rows['rows'][0]['result_summary']);
	}//end testRefusedHardeningChangeIsNotRecordedAsChanged()

	/**
	 * Mutation check: an excluded action survives a tombstone of its object.
	 *
	 * @return void
	 */
	public function testExcludedActionSurvivesTombstone(): void {
		$outcome = $this->scopeOutcome(only: null, exclude: ['destroyed']);

		$exclusions = array_filter($outcome['log'], static fn (string $call): bool => str_contains($call, 'notIn') === true && str_contains($call, 'destroyed') === true);
		$this->assertCount(2, $exclusions);
	}//end testExcludedActionSurvivesTombstone()

	/**
	 * Direct entry writes, each a closure over the mapper.
	 *
	 * @return array<string, callable(AuditTrailMapper): mixed>
	 */
	private static function entryCases(): array {
		$changes = [];
		for ($i = 0; $i < 1002; $i++) {
			$changes[] = ['uuid' => 'u-' . $i, 'old' => null, 'new' => 's-' . $i, 'extra' => 'dropped'];
		}

		return [
			'hardening accepted' => fn (AuditTrailMapper $m) => $m->recordHardeningChange(new HardeningChange('cors.origins', ['a'], ['a', 'b']), true),
			'hardening accepted with stray refusal' => fn (AuditTrailMapper $m) => $m->recordHardeningChange(new HardeningChange('x', 1, 2, 'ignored?'), true),
			'hardening refused' => fn (AuditTrailMapper $m) => $m->recordHardeningChange(new HardeningChange('session.ttl', 30, 9000, 'above floor'), false),
			'hardening refused without reason' => fn (AuditTrailMapper $m) => $m->recordHardeningChange(new HardeningChange('session.ttl', 'a', 'b'), false),
			'purpose minimal' => fn (AuditTrailMapper $m) => $m->recordPurposeRefusal(new PurposeRefusal('missing')),
			'purpose full' => fn (AuditTrailMapper $m) => $m->recordPurposeRefusal(new PurposeRefusal('unknown', 'p-1', new AuditedObject(3, 4))),
			'party minimal' => fn (AuditTrailMapper $m) => $m->recordPartyQueryRefusal(new PartyQueryRefusal('jan', 50, 400)),
			'party with schema' => fn (AuditTrailMapper $m) => $m->recordPartyQueryRefusal(new PartyQueryRefusal('', 0, 1, 9)),
			'slug empty' => fn (AuditTrailMapper $m) => $m->recordSlugBackfill(new SlugBackfill([], ['filled' => 0])),
			'slug some' => fn (AuditTrailMapper $m) => $m->recordSlugBackfill(new SlugBackfill([['uuid' => 'a', 'old' => null, 'new' => 'x'], ['new' => 'y']], ['filled' => 2], new AuditedObject(1, 2))),
			'slug truncated' => fn (AuditTrailMapper $m) => $m->recordSlugBackfill(new SlugBackfill($changes, [], new AuditedObject(5))),
		];
	}//end entryCases()

	/**
	 * Action scopes: only these actions (null for all), and the actions never touched.
	 *
	 * @return array<string, array{0: array<int, string>|null, 1: array<int, string>}>
	 */
	private static function scopeCases(): array {
		return [
			'everything' => [null, []],
			'empty only means everything' => [[], []],
			'only versions' => [['create', 'update'], []],
			'exclude destruction' => [null, ['destroyed']],
			'only and exclude' => [['update', 'destroyed'], ['destroyed']],
			'only unknown' => [['nope'], []],
		];
	}//end scopeCases()

	/**
	 * The rows in the table after one write, or the exception it threw.
	 *
	 * @param callable    $write The write.
	 * @param string|null $uid   The signed-in uid.
	 *
	 * @return array<string, mixed>
	 */
	private function rowsAfter(callable $write, ?string $uid): array {
		$database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);
		$mapper = $this->mapper(db: $database->idbConnection(), uid: $uid);
		try {
			$returned = $write($mapper);
		} catch (\Throwable $e) {
			return ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		return ['returnedId' => $returned->getId(), 'rows' => $this->rows(database: $database)];
	}//end rowsAfter()

	/**
	 * The query calls countForObject() and tombstoneForObject() make for one scope.
	 *
	 * @param array<int, string>|null $only    Only these actions.
	 * @param array<int, string>      $exclude Never these actions.
	 *
	 * @return array<string, mixed>
	 */
	private function scopeOutcome(?array $only, array $exclude): array {
		$this->log = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$mapper = $this->mapper(db: $db, uid: null);

		$this->log[] = 'count';
		$count = $mapper->countObjectTrail('o-1', new ActionScope($only, $exclude));
		$this->log[] = 'tombstone';
		$tombstoned = $mapper->tombstoneObjectTrail('o-1', new ActionScope($only, $exclude));

		return ['count' => $count, 'tombstoned' => $tombstoned, 'log' => $this->log];
	}//end scopeOutcome()

	/**
	 * A query builder that writes every call into the log and answers 3 rows.
	 *
	 * @return IQueryBuilder
	 */
	private function recordingBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$describe = static fn (string $name, array $args): string => $name . '(' . json_encode($args) . ')';
		foreach (['eq', 'in', 'notIn', 'isNull'] as $method) {
			$expr->method($method)->willReturnCallback(static fn (...$args): string => $describe($method, $args));
		}

		$func->method('count')->willReturnCallback(static fn (...$args) => self::fn(call: $describe('count', $args)));
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(static fn (...$args): string => $describe('param', $args));
		$qb->method('createFunction')->willReturnCallback(static fn (string $call) => self::fn(call: $call));
		foreach (['select', 'from', 'where', 'andWhere', 'update', 'set'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $method, $describe): IQueryBuilder {
					$this->log[] = $describe($method, array_map(static fn ($arg) => is_object($arg) === true ? (string)$arg : $arg, $args));
					return $qb;
				}
			);
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn('3');
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(2);
		$qb->method('getTableName')->willReturn('openregister_audit_trails');

		return $qb;
	}//end recordingBuilder()

	/**
	 * A query function that renders as the given text.
	 *
	 * @param string $call The text.
	 *
	 * @return IQueryFunction
	 */
	private static function fn(string $call): IQueryFunction {
		return new class($call) implements IQueryFunction {
			/**
			 * Constructor.
			 *
			 * @param string $call The text.
			 */
			public function __construct(private readonly string $call) {
			}

			/**
			 * The text.
			 *
			 * @return string
			 */
			public function __toString(): string {
				return $this->call;
			}
		};
	}//end fn()

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
	 * A mapper over the given connection.
	 *
	 * @param IDBConnection $db  The connection.
	 * @param string|null   $uid The signed-in uid.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(IDBConnection $db, ?string $uid): AuditTrailMapper {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
		}

		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));

		return new AuditTrailMapper($db, new AuditTrailMapperServices($container, new NullLogger(), $session, $request));
	}//end mapper()
}//end class
