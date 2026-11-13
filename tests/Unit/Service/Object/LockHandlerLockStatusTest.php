<?php

/**
 * Unit tests for the lock status reads of the lock handler.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\LockAuthorization;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\LockStores;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what isLocked() and getLockInfo() answer for a locked, an unlocked,
 * an expired and an unreadable object.
 *
 * @covers \OCA\OpenRegister\Service\Object\LockHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Object\AdvisoryLockStore
 * @uses   \OCA\OpenRegister\Service\Object\LockAuthorization
 * @uses   \OCA\OpenRegister\Service\Object\LockStores
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 */
final class LockHandlerLockStatusTest extends TestCase {

	/**
	 * The magic mapper double.
	 *
	 * @var MagicMapper&MockObject
	 */
	private $magicMapper;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The handler under test.
	 *
	 * @var LockHandler
	 */
	private LockHandler $handler;

	/**
	 * Wire fresh doubles for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$userSession       = $this->createMock(IUserSession::class);

		$this->handler = new LockHandler(
			new LockStores(
				magicMapper: $this->magicMapper,
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				advisory: new AdvisoryLockStore($this->createMock(IAppConfig::class), $userSession, $this->logger)
			),
			$this->logger,
			new LockAuthorization(
				$userSession,
				$this->createMock(IGroupManager::class),
				$this->createMock(SchemaMapper::class),
				$this->createMock(PermissionHandlerInterface::class)
			)
		);
	}//end setUp()

	/**
	 * Let the mapper resolve the identifier to an object with the given lock payload.
	 *
	 * @param array|null $locked The lock payload.
	 *
	 * @return void
	 */
	private function storedWithLock(?array $locked): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setLocked($locked);

		$this->magicMapper->expects($this->once())
			->method('findAcrossAllSources')
			->with('obj-1', false, true, true)
			->willReturn(['object' => $object, 'register' => null, 'schema' => null]);
	}//end storedWithLock()

	/**
	 * A current lock payload with an expiration in the future.
	 *
	 * @return array The payload.
	 */
	private function activeLock(): array {
		return [
			'user'       => 'alice',
			'process'    => 'import',
			'created'    => '2026-01-01T10:00:00+00:00',
			'duration'   => 3600,
			'expiration' => (new DateTime('+1 day'))->format(DATE_ATOM),
		];
	}//end activeLock()

	/**
	 * An object with an active lock reports locked.
	 *
	 * @return void
	 */
	public function testAnActiveLockIsLocked(): void {
		$this->storedWithLock(locked: $this->activeLock());

		$this->assertTrue($this->handler->isLocked('obj-1'));
	}//end testAnActiveLockIsLocked()

	/**
	 * An expired lock does not count.
	 *
	 * @return void
	 */
	public function testAnExpiredLockIsNotLocked(): void {
		$lock               = $this->activeLock();
		$lock['expiration'] = (new DateTime('-1 day'))->format(DATE_ATOM);
		$this->storedWithLock(locked: $lock);

		$this->assertFalse($this->handler->isLocked('obj-1'));
	}//end testAnExpiredLockIsNotLocked()

	/**
	 * An object the mapper cannot find reads as unlocked, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectIsReportedUnlockedAndLogged(): void {
		$this->magicMapper->method('findAcrossAllSources')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[LockHandler] Failed to check lock status', $this->callback(static fn (array $context): bool => in_array('obj-1', $context, true) === true));

		$this->assertFalse($this->handler->isLocked('obj-1'));
	}//end testAnUnreadableObjectIsReportedUnlockedAndLogged()

	/**
	 * Lock info maps the raw payload to the public snake_case shape.
	 *
	 * @return void
	 */
	public function testLockInfoIsThePublicShapeOfThePayload(): void {
		$lock = $this->activeLock();
		$this->storedWithLock(locked: $lock);

		$this->assertSame(
			[
				'locked_at'  => '2026-01-01T10:00:00+00:00',
				'locked_by'  => 'alice',
				'process'    => 'import',
				'expires_at' => $lock['expiration'],
				'duration'   => 3600,
			],
			$this->handler->getLockInfo('obj-1')
		);
	}//end testLockInfoIsThePublicShapeOfThePayload()

	/**
	 * Absent payload fields come back as null rather than being dropped.
	 *
	 * @return void
	 */
	public function testLockInfoFillsAbsentFieldsWithNull(): void {
		$this->storedWithLock(locked: ['user' => 'alice']);

		$this->assertSame(
			['locked_at' => null, 'locked_by' => 'alice', 'process' => null, 'expires_at' => null, 'duration' => null],
			$this->handler->getLockInfo('obj-1')
		);
	}//end testLockInfoFillsAbsentFieldsWithNull()

	/**
	 * An unlocked object has no lock info.
	 *
	 * @return void
	 */
	public function testAnUnlockedObjectHasNoLockInfo(): void {
		$this->storedWithLock(locked: null);

		$this->assertNull($this->handler->getLockInfo('obj-1'));
	}//end testAnUnlockedObjectHasNoLockInfo()

	/**
	 * An unreadable object has no lock info, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectHasNoLockInfo(): void {
		$this->magicMapper->method('findAcrossAllSources')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())->method('warning')->with('[LockHandler] Failed to get lock info', $this->anything());

		$this->assertNull($this->handler->getLockInfo('obj-1'));
	}//end testAnUnreadableObjectHasNoLockInfo()
}//end class
