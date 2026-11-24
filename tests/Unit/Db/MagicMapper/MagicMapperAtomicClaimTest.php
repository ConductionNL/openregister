<?php

/**
 * MagicMapper's lock claim is one conditional UPDATE: of two claimers that both
 * read an object unlocked only one holds it, an expired lock is claimable, the
 * same owner re-claims, and a job claims without a signed-in user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\LockTerms;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicLockClaim;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Tests for the atomic lock claim.
 */
#[CoversClass(MagicMapper::class)]
#[UsesClass(MagicLockClaim::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(LockTerms::class)]
#[UsesClass(RegisterSchema::class)]
#[UsesClass(Register::class)]
#[UsesClass(Schema::class)]
class MagicMapperAtomicClaimTest extends TestCase {

	/**
	 * The rows each conditional UPDATE changes, in order.
	 *
	 * @var list<int>
	 */
	private array $rows = [];

	/**
	 * What a fresh read of the row returns (JSON or false).
	 *
	 * @var string|false
	 */
	private string|false $stored = false;

	/**
	 * Events dispatched.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * A mapper over a recording connection, for the given signed-in user (null for none).
	 *
	 * @param string|null $user The signed-in user.
	 *
	 * @return MagicMapper
	 */
	private function mapper(?string $user): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['updateObjectEntity', 'getTableNameForRegisterSchema'])
			->getMock();
		$mapper->method('updateObjectEntity')->willReturnArgument(0);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');

		$session = $this->createMock(IUserSession::class);
		$account = null;
		if ($user !== null) {
			$account = $this->createMock(IUser::class);
			$account->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($account);
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = (new \ReflectionClass($event))->getShortName();
			}
		);

		foreach (['userSession' => $session, 'eventDispatcher' => $events, 'logger' => new NullLogger(), 'db' => $this->db()] as $name => $value) {
			(new ReflectionProperty(MagicMapper::class, $name))->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	/**
	 * A connection whose UPDATEs change the queued number of rows.
	 *
	 * @return IDBConnection
	 */
	private function db(): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		foreach (['update', 'set', 'where', 'andWhere', 'select', 'from'] as $verb) {
			$qb->method($verb)->willReturnSelf();
		}

		$qb->method('executeStatement')->willReturnCallback(fn (): int => (array_shift($this->rows) ?? 0));
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturnCallback(fn () => $this->stored);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);

		return $db;
	}//end db()

	/**
	 * A stored object.
	 *
	 * @param array|null $locked Its lock as read.
	 *
	 * @return ObjectEntity
	 */
	private static function object(?array $locked=null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$entity->setLocked($locked);

		return $entity;
	}//end object()

	/**
	 * A user lock held by an owner, live or expired.
	 *
	 * @param string $owner   The holder.
	 * @param string $expires When it expires (relative DateTime text).
	 *
	 * @return array
	 */
	private static function lockOf(string $owner, string $expires): array {
		return [
			'kind' => ObjectEntity::LOCK_KIND_USER,
			'runUuid' => null,
			'user' => $owner,
			'process' => 'p',
			'created' => (new DateTime('-1 hour'))->format('c'),
			'duration' => 60,
			'expiration' => (new DateTime($expires))->format('c'),
		];
	}//end lockOf()

	/**
	 * The register and schema.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private static function table(): array {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		return [$register, $schema];
	}//end table()

	public function testOfTwoClaimersThatBothReadItUnlockedOnlyTheFirstHoldsIt(): void {
		[$register, $schema] = self::table();
		$first  = self::object();
		$second = self::object();

		$this->rows = [1];
		$this->assertTrue($this->mapper(user: null)->claimObjectEntity($first, $register, $schema, 'worker-a', 60));

		// The second claimer's UPDATE matches no row: the row now holds worker-a's lock.
		$this->rows   = [0];
		$this->stored = json_encode($first->getLocked());
		$this->assertFalse($this->mapper(user: null)->claimObjectEntity($second, $register, $schema, 'worker-b', 60));

		$this->assertSame('worker-a', $first->getLocked()['user']);
		$this->assertSame('worker-a', $second->getLocked()['user'], 'the loser sees the lock the table holds');
		$this->assertSame(['ObjectLockedEvent'], $this->events, 'only the winner announces a lock');
	}//end testOfTwoClaimersThatBothReadItUnlockedOnlyTheFirstHoldsIt()

	public function testAnExpiredLockIsClaimable(): void {
		[$register, $schema] = self::table();
		$entity = self::object(locked: self::lockOf(owner: 'worker-a', expires: '-1 minute'));

		$this->rows = [1];
		$this->assertTrue($this->mapper(user: null)->claimObjectEntity($entity, $register, $schema, 'worker-b', 60));
		$this->assertSame('worker-b', $entity->getLocked()['user']);
		$this->assertTrue($entity->isLocked());
	}//end testAnExpiredLockIsClaimable()

	public function testALiveLockOfAnotherOwnerIsNotClaimedAndNothingIsWritten(): void {
		[$register, $schema] = self::table();
		$held   = self::lockOf(owner: 'worker-a', expires: '+10 minutes');
		$entity = self::object(locked: $held);

		$this->rows = [1];
		$this->assertFalse($this->mapper(user: null)->claimObjectEntity($entity, $register, $schema, 'worker-b', 60));
		$this->assertSame([1], $this->rows, 'no UPDATE ran');
		$this->assertSame($held, $entity->getLocked());
	}//end testALiveLockOfAnotherOwnerIsNotClaimedAndNothingIsWritten()

	public function testTheSameOwnerReClaimsAndKeepsWhenItFirstTookTheLock(): void {
		[$register, $schema] = self::table();
		$held   = self::lockOf(owner: 'worker-a', expires: '+10 minutes');
		$entity = self::object(locked: $held);

		$this->rows = [1];
		$this->assertTrue($this->mapper(user: null)->claimObjectEntity($entity, $register, $schema, 'worker-a', 600));
		$this->assertSame('worker-a', $entity->getLocked()['user']);
		$this->assertSame($held['created'], $entity->getLocked()['created']);
		$this->assertSame(600, $entity->getLocked()['duration']);
	}//end testTheSameOwnerReClaimsAndKeepsWhenItFirstTookTheLock()

	public function testAJobClaimsWithoutASignedInUserAndAnEmptyOwnerClaimsNothing(): void {
		[$register, $schema] = self::table();
		$entity = self::object();

		$this->rows = [1];
		$this->assertFalse($this->mapper(user: null)->claimObjectEntity($entity, $register, $schema, ' ', 60));
		$this->assertNull($entity->getLocked());

		$this->assertTrue($this->mapper(user: null)->claimObjectEntity($entity, $register, $schema, 'job:sync', 60, 'sync', 'run-1'));
		$this->assertSame('job:sync', $entity->getLocked()['user']);
		$this->assertSame('run-1', $entity->getLockedByRun());
		$this->assertSame('sync', $entity->getLocked()['process']);
	}//end testAJobClaimsWithoutASignedInUserAndAnEmptyOwnerClaimsNothing()

	public function testAStaleReadIsJudgedAgainOnTheLockTheTableHolds(): void {
		[$register, $schema] = self::table();
		// The entity was read while worker-a held a lock that has since been released.
		$entity = self::object(locked: self::lockOf(owner: 'worker-a', expires: '-1 minute'));

		$this->rows   = [0, 1];
		$this->stored = false;
		$this->assertTrue($this->mapper(user: null)->claimObjectEntity($entity, $register, $schema, 'worker-b', 60));
		$this->assertSame('worker-b', $entity->getLocked()['user']);
		$this->assertSame([], $this->rows, 'a second UPDATE ran, on the lock read back');
	}//end testAStaleReadIsJudgedAgainOnTheLockTheTableHolds()

	public function testALockForAPersonIsRefusedWhenAnotherCallerTookItBetweenReadAndWrite(): void {
		[$register, $schema] = self::table();
		$entity = self::object();

		$this->rows   = [0];
		$this->stored = json_encode(self::lockOf(owner: 'bob', expires: '+10 minutes'));
		try {
			$this->mapper(user: 'anna')->lockObjectEntity($entity, $register, $schema, 60);
			$this->fail('A lock another caller took first must be refused.');
		} catch (Exception $e) {
			$this->assertStringStartsWith('Object is locked by', $e->getMessage());
		}

		$this->assertSame([], $this->events);
		$this->assertSame('bob', $entity->getLocked()['user'], 'the entity carries the lock the table holds');
	}//end testALockForAPersonIsRefusedWhenAnotherCallerTookItBetweenReadAndWrite()

	public function testALockForAPersonStillNeedsASignedInUser(): void {
		[$register, $schema] = self::table();

		$this->expectExceptionMessage('No user logged in');
		$this->mapper(user: null)->lockObjectEntity(self::object(), $register, $schema, 60);
	}//end testALockForAPersonStillNeedsASignedInUser()
}//end class
