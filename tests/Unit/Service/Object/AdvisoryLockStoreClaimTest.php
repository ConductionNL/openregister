<?php

/**
 * AdvisoryLockStore takes an advisory lock atomically when it has the
 * connection: an insert-if-absent for a new lock, a conditional UPDATE over an
 * expired one, and a claim for a named owner without a session.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the atomic advisory lock.
 */
#[CoversClass(AdvisoryLockStore::class)]
#[UsesClass(LockRequest::class)]
#[UsesClass(LockedException::class)]
class AdvisoryLockStoreClaimTest extends TestCase {

	/**
	 * What insertIfNotExist reports, in order.
	 *
	 * @var list<int>
	 */
	private array $inserts = [];

	/**
	 * What the conditional UPDATE reports, in order.
	 *
	 * @var list<int>
	 */
	private array $updates = [];

	/**
	 * The stored value a fresh read returns.
	 *
	 * @var string|false
	 */
	private string|false $stored = false;

	/**
	 * The values written, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * How often the app config was told to reload.
	 *
	 * @var int
	 */
	private int $reloads = 0;

	/**
	 * A store over a recording connection, for the given signed-in user (null for none).
	 *
	 * @param string|null $user The signed-in user.
	 *
	 * @return AdvisoryLockStore
	 */
	private function store(?string $user=null): AdvisoryLockStore {
		$session = $this->createMock(IUserSession::class);
		$account = null;
		if ($user !== null) {
			$account = $this->createMock(IUser::class);
			$account->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($account);
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');
		$config->method('clearCache')->willReturnCallback(function (): void {
			$this->reloads++;
		});

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value): string {
				if (is_string($value) === true && str_contains($value, '"advisory":true') === true && str_contains($value, '"created"') === true) {
					$this->written[] = json_decode($value, true);
				}

				return ':p';
			}
		);
		foreach (['update', 'set', 'where', 'andWhere', 'select', 'from'] as $verb) {
			$qb->method($verb)->willReturnSelf();
		}

		$qb->method('executeStatement')->willReturnCallback(fn (): int => (array_shift($this->updates) ?? 0));
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturnCallback(fn () => $this->stored);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('insertIfNotExist')->willReturnCallback(
			function (string $table, array $input, ?array $compare) {
				$this->assertSame('*PREFIX*appconfig', $table);
				$this->assertSame(['appid', 'configkey'], $compare);
				$this->assertSame('openregister', $input['appid']);
				$this->written[] = json_decode($input['configvalue'], true);
				return (array_shift($this->inserts) ?? 0);
			}
		);

		return new AdvisoryLockStore(appConfig: $config, userSession: $session, logger: new NullLogger(), db: $db);
	}//end store()

	/**
	 * A stored advisory lock of an owner.
	 *
	 * @param string $owner   The holder.
	 * @param string $expires When it expires.
	 *
	 * @return string
	 */
	private static function storedLock(string $owner, string $expires): string {
		return (string)json_encode(['user' => $owner, 'process' => 'p', 'expiration' => (new DateTime($expires))->format(DateTime::ATOM), 'advisory' => true]);
	}//end storedLock()

	public function testOfTwoClaimersOfAFreeIdentifierOnlyTheFirstInsertWins(): void {
		$this->inserts = [1, 0];

		$this->assertTrue($this->store()->claim(new LockRequest(identifier: 'createApp:x', duration: 60), 'worker-a'));
		$this->assertFalse($this->store()->claim(new LockRequest(identifier: 'createApp:x', duration: 60), 'worker-b'));
		$this->assertSame('worker-a', $this->written[0]['user']);
		$this->assertSame(1, $this->reloads, 'only the winner tells the app config to reload');
	}//end testOfTwoClaimersOfAFreeIdentifierOnlyTheFirstInsertWins()

	public function testAnExpiredLockIsReplacedOnlyWhileItIsUnchanged(): void {
		$this->stored  = self::storedLock(owner: 'worker-a', expires: '-1 minute');
		$this->updates = [1, 0];

		$this->assertTrue($this->store()->claim(new LockRequest(identifier: 'job:1'), 'worker-b'));
		$this->assertFalse($this->store()->claim(new LockRequest(identifier: 'job:1'), 'worker-c'), 'the row changed under the second claim');
	}//end testAnExpiredLockIsReplacedOnlyWhileItIsUnchanged()

	public function testALiveLockIsClaimedByItsOwnerOnly(): void {
		$this->stored  = self::storedLock(owner: 'worker-a', expires: '+10 minutes');
		$this->updates = [1];

		$this->assertFalse($this->store()->claim(new LockRequest(identifier: 'job:1'), 'worker-b'));
		$this->assertSame([], $this->written, 'nothing is written for a lock someone else holds');
		$this->assertTrue($this->store()->claim(new LockRequest(identifier: 'job:1', duration: 30), 'worker-a'));
		$this->assertSame(30, $this->written[0]['duration']);
		$this->assertFalse($this->store()->claim(new LockRequest(identifier: 'job:1'), ''), 'no owner, no claim');
	}//end testALiveLockIsClaimedByItsOwnerOnly()

	public function testAcquireStaysRefusedOnALiveLockAndIsAtomicOnAFreeOne(): void {
		$this->stored = self::storedLock(owner: 'anna', expires: '+10 minutes');
		try {
			$this->store(user: 'anna')->acquire(new LockRequest(identifier: 'createApp:x'));
			$this->fail('A live advisory lock must refuse an acquire, as before.');
		} catch (LockedException $e) {
			$this->assertSame("Advisory lock 'createApp:x' is already held", $e->getMessage());
		}

		$this->stored  = false;
		$this->inserts = [0];
		$this->expectException(LockedException::class);
		$this->store(user: 'bob')->acquire(new LockRequest(identifier: 'createApp:x'));
	}//end testAcquireStaysRefusedOnALiveLockAndIsAtomicOnAFreeOne()

	public function testAcquireOnAFreeIdentifierNamesTheSignedInHolder(): void {
		$this->inserts = [1];

		$result = $this->store(user: 'bob')->acquire(new LockRequest(identifier: 'createApp:x', process: 'wizard'));
		$this->assertSame('bob', $result['locked']['user']);
		$this->assertSame('wizard', $result['locked']['process']);
		$this->assertSame('createApp:x', $result['uuid']);
	}//end testAcquireOnAFreeIdentifierNamesTheSignedInHolder()
}//end class
