<?php

/**
 * Phase-0 regression: advisory (pre-creation) lock fallback in LockHandler.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks the Phase-0 fix where lock() falls back to an advisory (pre-creation)
 * lock when the identifier does not resolve to a stored object — e.g. the
 * openbuild wizard locks `createApp:<slug>` BEFORE the object exists. Without
 * this, create-then-store flows failed with a 404/422 instead of locking.
 */
class LockHandlerAdvisoryTest extends TestCase {

	private MagicMapper&MockObject $magicMapper;

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private LoggerInterface&MockObject $logger;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private SchemaMapper&MockObject $schemaMapper;

	private IAppConfig&MockObject $appConfig;

	private LockHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->handler = new LockHandler(
			$this->magicMapper,
			$this->auditTrailMapper,
			$this->logger,
			$this->userSession,
			$this->groupManager,
			$this->schemaMapper,
			$this->appConfig
		);
	}//end setUp()

	/**
	 * The advisory app-config key for an identifier is `advisory_lock_<md5>`.
	 *
	 * @param string $identifier The advisory-lock identifier.
	 *
	 * @return string The expected app-config key.
	 */
	private function advisoryKey(string $identifier): string {
		return 'advisory_lock_' . md5($identifier);
	}//end advisoryKey()

	public function testLockOnUnresolvableIdAcquiresAdvisoryLock(): void {
		$identifier = 'createApp:my-new-app';

		// The id never resolves to a stored object.
		$this->magicMapper->method('findAcrossAllSources')
			->willThrowException(new DoesNotExistException('not found'));

		// No prior advisory lock stored.
		$this->appConfig->method('getValueString')
			->with('openregister', $this->advisoryKey($identifier), '')
			->willReturn('');

		// The advisory lock MUST be persisted under the namespaced key.
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with(
				'openregister',
				$this->advisoryKey($identifier),
				$this->callback(
					static function (string $json): bool {
						$decoded = json_decode($json, true);
						return is_array($decoded)
							&& ($decoded['advisory'] ?? false) === true
							&& isset($decoded['expiration']);
					}
				)
			);

		$result = $this->handler->lock($identifier);

		$this->assertSame($identifier, $result['uuid']);
		$this->assertTrue($result['locked']['advisory']);
		$this->assertArrayHasKey('expiration', $result['locked']);
	}//end testLockOnUnresolvableIdAcquiresAdvisoryLock()

	public function testLockOnUnresolvableIdRespectsStillValidAdvisoryLock(): void {
		$identifier = 'createApp:my-new-app';

		$this->magicMapper->method('findAcrossAllSources')
			->willThrowException(new DoesNotExistException('not found'));

		// A still-valid advisory lock already exists (expires in the future).
		$existing = json_encode(
			[
				'advisory' => true,
				'expiration' => (new \DateTime('+1 hour'))->format(\DateTime::ATOM),
			]
		);
		$this->appConfig->method('getValueString')
			->with('openregister', $this->advisoryKey($identifier), '')
			->willReturn($existing);

		// Holding lock must NOT be overwritten.
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->expectException(LockedException::class);
		$this->handler->lock($identifier);
	}//end testLockOnUnresolvableIdRespectsStillValidAdvisoryLock()

	public function testLockOnUnresolvableIdOverwritesExpiredAdvisoryLock(): void {
		$identifier = 'createApp:my-new-app';

		$this->magicMapper->method('findAcrossAllSources')
			->willThrowException(new DoesNotExistException('not found'));

		// An EXPIRED advisory lock exists — it must be silently overwritten.
		$expired = json_encode(
			[
				'advisory' => true,
				'expiration' => (new \DateTime('-1 hour'))->format(\DateTime::ATOM),
			]
		);
		$this->appConfig->method('getValueString')
			->with('openregister', $this->advisoryKey($identifier), '')
			->willReturn($expired);

		$this->appConfig->expects($this->once())->method('setValueString');

		$result = $this->handler->lock($identifier);
		$this->assertSame($identifier, $result['uuid']);
		$this->assertTrue($result['locked']['advisory']);
	}//end testLockOnUnresolvableIdOverwritesExpiredAdvisoryLock()

	public function testUnlockOnUnresolvableIdReleasesAdvisoryLock(): void {
		$identifier = 'createApp:my-new-app';

		// The id never resolves to a stored object — unlock must take the
		// advisory-release path rather than 404/throw.
		$this->magicMapper->method('findAcrossAllSources')
			->willThrowException(new DoesNotExistException('not found'));

		// A held advisory lock exists under the namespaced key.
		$this->appConfig->method('getValueString')
			->with('openregister', $this->advisoryKey($identifier), '')
			->willReturn(
				json_encode(
					[
						'advisory' => true,
						'expiration' => (new \DateTime('+1 hour'))->format(\DateTime::ATOM),
					]
				)
			);

		// The advisory lock MUST be cleared from app-config.
		$this->appConfig->expects($this->once())
			->method('deleteKey')
			->with('openregister', $this->advisoryKey($identifier));

		$this->assertTrue($this->handler->unlock($identifier));
	}//end testUnlockOnUnresolvableIdReleasesAdvisoryLock()

	public function testUnlockOnUnresolvableIdIsNoOpWhenNoAdvisoryLockHeld(): void {
		$identifier = 'createApp:never-locked';

		$this->magicMapper->method('findAcrossAllSources')
			->willThrowException(new DoesNotExistException('not found'));

		// No advisory lock stored — releaseAdvisoryLock() short-circuits and
		// must NOT issue a delete, yet unlock() still reports success.
		$this->appConfig->method('getValueString')
			->with('openregister', $this->advisoryKey($identifier), '')
			->willReturn('');

		$this->appConfig->expects($this->never())->method('deleteKey');

		$this->assertTrue($this->handler->unlock($identifier));
	}//end testUnlockOnUnresolvableIdIsNoOpWhenNoAdvisoryLockHeld()
}//end class
