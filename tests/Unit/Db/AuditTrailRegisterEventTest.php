<?php

/**
 * Register-level events on the hash-chained audit trail.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailFieldTypes;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\RegisterAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A register event is written as a row about the register, and the chain seals, re-chains and verifies it.
 */
class AuditTrailRegisterEventTest extends TestCase {

	/**
	 * The rows the mapper was asked to insert.
	 *
	 * @var list<AuditTrail>
	 */
	private array $inserted = [];

	/**
	 * The in-memory audit table the hash service reads and writes, by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $table = [];

	/**
	 * The session the mapper resolves its actor from.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Build the real mapper with only insert() captured.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(): AuditTrailMapper {
		$this->session = $this->createMock(IUserSession::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));

		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					new AuditTrailMapperServices(
						container: $container,
						logger: $this->createMock(LoggerInterface::class),
						userSession: $this->session,
						request: $this->createMock(IRequest::class)
					),
				]
			)
			->onlyMethods(['insert'])
			->getMock();
		$mapper->method('insert')->willReturnCallback(
			function (AuditTrail $row): AuditTrail {
				$this->inserted[] = $row;
				return $row;
			}
		);

		return $mapper;

	}//end mapper()

	/**
	 * Sign a user in on the mapper's session.
	 *
	 * @param string $uid         The user id.
	 * @param string $displayName The display name.
	 *
	 * @return void
	 */
	private function signIn(string $uid, string $displayName): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->session->method('getUser')->willReturn($user);

	}//end signIn()

	/**
	 * The row is about the register: action, register, details and the session actor; no object, no schema.
	 *
	 * @return void
	 */
	public function testTheEntryNamesTheRegisterTheActionTheDetailsAndTheSessionActor(): void {
		$mapper = $this->mapper();
		$this->signIn(uid: 'fin-officer', displayName: 'Finance Officer');

		$details = ['profile' => 'annual-report', 'period' => '2026'];
		$mapper->recordRegisterEvent(new RegisterAuditEvent(register: 12, action: 'compliance.export_requested', details: $details));

		$this->assertCount(1, $this->inserted);
		$row = $this->inserted[0];
		$this->assertSame('compliance.export_requested', $row->getAction());
		$this->assertSame(12, $row->getRegister());
		$this->assertNull($row->getObject());
		$this->assertNull($row->getObjectUuid());
		$this->assertNull($row->getSchema());
		$this->assertSame($details, $row->getResultSummary());
		$this->assertSame([], $row->getChanged());
		$this->assertSame('fin-officer', $row->getUser());
		$this->assertSame('Finance Officer', $row->getUserName());
		$this->assertNotEmpty($row->getUuid());
		$this->assertNotNull($row->getCreated());
		$this->assertNull($row->getHash(), 'sealing is left to the sweep, as for every other row');

	}//end testTheEntryNamesTheRegisterTheActionTheDetailsAndTheSessionActor()

	/**
	 * A named actor wins over the session; with neither, the row names `system`.
	 *
	 * @return void
	 */
	public function testTheActorIsTheNamedPrincipalOrTheSystem(): void {
		$mapper = $this->mapper();
		$this->session->method('getUser')->willReturn(null);

		$mapper->recordRegisterEvent(
			new RegisterAuditEvent(register: 3, action: 'compliance.export_requested', actor: new NamedActor(id: 'connector:sap'))
		);
		$mapper->recordRegisterEvent(new RegisterAuditEvent(register: 3, action: 'compliance.export_requested'));

		$this->assertSame('connector:sap', $this->inserted[0]->getUser());
		$this->assertSame('connector:sap', $this->inserted[0]->getUserName());
		$this->assertSame('system', $this->inserted[1]->getUser());
		$this->assertSame('System', $this->inserted[1]->getUserName());

	}//end testTheActorIsTheNamedPrincipalOrTheSystem()

	/**
	 * A plain object action or a missing register is refused before anything is written.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function invalidEvents(): array {
		return [
			'plain object action' => [5, 'update'],
			'no area'             => [5, '.requested'],
			'no register'         => [0, 'compliance.export_requested'],
		];

	}//end invalidEvents()

	/**
	 * The value object refuses an event the trail could misread.
	 *
	 * @param int    $register The register id.
	 * @param string $action   The action.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('invalidEvents')]
	public function testAnEventTheTrailCouldMisreadIsRefused(int $register, string $action): void {
		$this->expectException(InvalidArgumentException::class);

		new RegisterAuditEvent(register: $register, action: $action);

	}//end testAnEventTheTrailCouldMisreadIsRefused()

	/**
	 * Between two object rows, a register row re-chains, verifies and counts as sealed.
	 *
	 * @return void
	 */
	public function testARegisterRowChainsVerifiesAndCountsLikeAnObjectRow(): void {
		$service = $this->chainOver($this->trailWithARegisterEventInTheMiddle());

		$this->assertSame(['rechained' => 3, 'tombstonesPreserved' => 0], $service->rechainAll());

		$this->assertSame($this->table[1]['hash'], $this->table[2]['previous_hash']);
		$this->assertSame($this->table[2]['hash'], $this->table[3]['previous_hash']);

		$report = $service->verifyChain();
		$this->assertTrue($report['valid']);
		$this->assertSame(3, $report['entriesVerified']);
		$this->assertSame(0, $report['skippedNullHashes']);

		$status = $service->getIntegrityStatus();
		$this->assertSame(3, $status['total']);
		$this->assertSame(3, $status['sealed']);
		$this->assertSame(0, $status['unsealed']);
		$this->assertSame(100.0, $status['coverage']);
		$this->assertSame(3, $status['lastSealedId']);

	}//end testARegisterRowChainsVerifiesAndCountsLikeAnObjectRow()

	/**
	 * Editing a sealed register row's details breaks the chain at that row.
	 *
	 * @return void
	 */
	public function testEditingASealedRegisterRowBreaksTheChainThere(): void {
		$service = $this->chainOver($this->trailWithARegisterEventInTheMiddle());
		$service->rechainAll();

		$this->table[2]['result_summary'] = json_encode(['profile' => 'something-else', 'period' => '2026']);

		$report = $service->verifyChain();
		$this->assertFalse($report['valid']);
		$this->assertSame(2, $report['brokenAt']);

	}//end testEditingASealedRegisterRowBreaksTheChainThere()

	/**
	 * Three unsealed rows: an object update, the register event the mapper wrote, an object update.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function trailWithARegisterEventInTheMiddle(): array {
		$mapper = $this->mapper();
		$this->signIn(uid: 'fin-officer', displayName: 'Finance Officer');
		$mapper->recordRegisterEvent(
			new RegisterAuditEvent(register: 12, action: 'compliance.export_requested', details: ['profile' => 'annual-report', 'period' => '2026'])
		);

		$before = new AuditTrail();
		$before->setUuid('object-row-1');
		$before->setAction('update');
		$before->setRegister(12);
		$before->setSchema(4);
		$before->setObject(40);
		$before->setChanged(['status' => ['old' => 'open', 'new' => 'closed']]);

		$after = new AuditTrail();
		$after->setUuid('object-row-3');
		$after->setAction('create');
		$after->setRegister(12);
		$after->setSchema(4);
		$after->setObject(41);
		$after->setChanged(['title' => ['old' => null, 'new' => 'Jaarrekening']]);

		return [
			1 => $this->rowOf(entry: $before, id: 1),
			2 => $this->rowOf(entry: $this->inserted[0], id: 2),
			3 => $this->rowOf(entry: $after, id: 3),
		];

	}//end trailWithARegisterEventInTheMiddle()

	/**
	 * The database row an entity is stored as: snake_case columns, JSON encoded, dates as strings.
	 *
	 * @param AuditTrail $entry The entity.
	 * @param int        $id    The row id.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rowOf(AuditTrail $entry, int $id): array {
		$row = ['id' => $id];
		foreach (AuditTrailFieldTypes::TYPES as $field => $type) {
			$value = (fn (): mixed => $this->{$field})->call($entry);
			if ($type === 'json' && $value !== null) {
				$value = json_encode($value);
			}

			if ($value instanceof DateTimeInterface) {
				$value = $value->format('Y-m-d H:i:s');
			}

			$row[strtolower((string)preg_replace('/[A-Z]/', '_$0', $field))] = $value;
		}

		return $row;

	}//end rowOf()

	/**
	 * The hash service over an in-memory table.
	 *
	 * Serves the three query shapes the chain uses: a window of rows after an id, an UPDATE of one
	 * row's hash and previous hash, and the COUNT/MAX summary of getIntegrityStatus().
	 *
	 * @param array<int, array<string, mixed>> $rows The table, by id.
	 *
	 * @return AuditHashService
	 */
	private function chainOver(array $rows): AuditHashService {
		$this->table = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return new AuditHashService(
			$db,
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class)
		);

	}//end chainOver()

	/**
	 * One query builder over the in-memory table.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$query = new \stdClass();
		$query->params = [];
		$query->conditions = [];
		$query->function = null;
		$query->limit = null;

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['gt', 'eq', 'lte', 'lt', 'neq', 'isNull', 'isNotNull'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				function () use ($operator, $query): string {
					$query->conditions[] = $operator;
					return $operator;
				}
			);
		}

		$function = $this->createMock(IFunctionBuilder::class);
		foreach (['count', 'max'] as $name) {
			$function->method($name)->willReturnCallback(
				function () use ($name, $query): IQueryFunction {
					$query->function = $name;
					return $this->createMock(IQueryFunction::class);
				}
			);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'update', 'set'] as $chained) {
			$qb->method($chained)->willReturnSelf();
		}

		$qb->method('setMaxResults')->willReturnCallback(
			function (?int $limit) use ($qb, $query): IQueryBuilder {
				$query->limit = $limit;
				return $qb;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($function);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use ($query): string {
				$query->params[] = $value;
				return ':p' . count($query->params);
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($query): int {
				[$hash, $previousHash, $id] = $query->params;
				$this->table[$id]['hash']          = $hash;
				$this->table[$id]['previous_hash'] = $previousHash;
				return 1;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->selectResult(query: $query));

		return $qb;

	}//end queryBuilder()

	/**
	 * The result of one SELECT over the in-memory table.
	 *
	 * @param object $query The recorded query.
	 *
	 * @return IResult
	 */
	private function selectResult(object $query): IResult {
		$sealed = array_filter($this->table, static fn (array $row): bool => ($row['hash'] ?? null) !== null);

		$cursor = $this->createMock(IResult::class);
		if ($query->function === 'count') {
			$counted = $this->table;
			if (in_array('isNull', $query->conditions, true) === true) {
				$counted = array_diff_key($this->table, $sealed);
			}

			$cursor->method('fetchOne')->willReturn(count($counted));
			return $cursor;
		}

		if ($query->function === 'max') {
			$cursor->method('fetchOne')->willReturn(($sealed === [] ? false : max(array_keys($sealed))));
			return $cursor;
		}

		$afterId = (int)$query->params[0];
		$rows    = array_values(array_filter($this->table, static fn (array $row): bool => $row['id'] > $afterId));
		$cursor->method('fetchAll')->willReturn(array_slice($rows, 0, $query->limit));

		return $cursor;

	}//end selectResult()
}//end class
