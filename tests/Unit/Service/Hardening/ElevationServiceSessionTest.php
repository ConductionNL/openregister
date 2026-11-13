<?php

/**
 * Unit tests for ElevationService: an elevated session passes the write gate, and a session that cannot be read is not elevated.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Hardening\ElevationIdentity;
use OCA\OpenRegister\Service\Hardening\ElevationRequiredException;
use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningPosture;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsStore;
use OCA\OpenRegister\Service\Hardening\PlatformSecurityReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\ElevationService
 * @uses \OCA\OpenRegister\Service\Hardening\ElevationRequiredException
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningPolicy
 * @uses \OCA\OpenRegister\Service\Hardening\ElevationIdentity
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningFact
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningPosture
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningSettingsStore
 */
class ElevationServiceSessionTest extends TestCase {

	private HardeningAuditWriter&MockObject $audit;

	private IUserManager&MockObject $users;

	/**
	 * A service over the given session double, at a fixed moment.
	 *
	 * @param ISession $session The session.
	 *
	 * @return ElevationService
	 */
	private function service(ISession $session): ElevationService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$this->users = $this->createMock(IUserManager::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$this->audit = $this->createMock(HardeningAuditWriter::class);

		return new ElevationService(
			identity: new ElevationIdentity(session: $session, userSession: $userSession, users: $this->users),
			time: $time,
			posture: new HardeningPosture(
				policy: new HardeningPolicy($appConfig),
				store: new HardeningSettingsStore($appConfig, $this->createMock(AuditTrailMapperInterface::class), $this->createMock(LoggerInterface::class)),
				audit: $this->audit,
				platform: $this->createMock(PlatformSecurityReader::class),
			)
		);
	}//end service()

	public function testAnElevatedSessionPassesTheWriteGateWithoutARefusal(): void {
		$stored = [];
		$session = $this->createMock(ISession::class);
		$session->method('set')->willReturnCallback(
			function (string $key, $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);
		$session->method('get')->willReturnCallback(static function (string $key) use (&$stored) {
			return ($stored[$key] ?? null);
		});
		$service = $this->service($session);
		$this->users->method('checkPassword')->willReturn($this->createMock(IUser::class));
		$this->assertTrue($service->elevate(password: 'correct horse'));
		$this->audit->expects($this->never())->method('refused');

		$service->requireElevated();

		$this->assertTrue($service->isElevated());
	}

	public function testASessionThatCannotBeReadIsNotElevated(): void {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willThrowException(new RuntimeException('session store down'));
		$service = $this->service($session);

		$this->assertFalse($service->isElevated());
		$this->assertSame(0, $service->remainingSeconds());
		$this->expectException(ElevationRequiredException::class);
		$service->requireElevated();
	}

	public function testAStoredMomentThatIsNotANumberIsNotElevated(): void {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn(['not', 'a', 'moment']);

		$this->assertFalse($this->service($session)->isElevated());
	}

	public function testAMomentStoredAsTextStillCounts(): void {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn('1799999900');
		$service = $this->service($session);

		$this->assertTrue($service->isElevated());
		$this->assertSame(800, $service->remainingSeconds());
	}
}
