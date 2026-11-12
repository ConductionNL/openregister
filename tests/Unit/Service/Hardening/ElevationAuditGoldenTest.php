<?php

/**
 * Golden coverage for what ElevationService::elevate decides and audits.
 *
 * Pins the allowed and refused outcomes of confirming a password, the exact
 * facts and refusal texts written to the audit trail, and the moment stored
 * in the session.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Hardening\ElevationIdentity;
use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningFact;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks each outcome of an elevation attempt and its audit entry.
 */
final class ElevationAuditGoldenTest extends TestCase {

	/**
	 * Audit calls as [kind, fact, before, after, refusal].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $audited = [];

	/**
	 * Session writes.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	/**
	 * Passwords the user manager was asked to check.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $checked = [];

	/**
	 * A service for the given signed-in uid (or nobody) whose password check answers `$confirms`.
	 *
	 * @param string|null $uid      The signed-in uid, or null.
	 * @param boolean     $confirms What the password check answers.
	 *
	 * @return ElevationService The service.
	 */
	private function service(?string $uid, bool $confirms): ElevationService {
		$session = $this->createMock(ISession::class);
		$session->method('set')->willReturnCallback(
			function (string $key, $value): void {
				$this->stored[$key] = $value;
			}
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('checkPassword')->willReturnCallback(
			function (string $loginName, string $password) use ($confirms) {
				$this->checked[] = [$loginName, $password];
				return $confirms;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1758182400);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$audit = $this->createMock(HardeningAuditWriter::class);
		$audit->method('refused')->willReturnCallback(
			function (HardeningFact $change, string $refusal): void {
				$this->audited[] = ['refused', $change->fact, $change->before, $change->after, $refusal];
			}
		);
		$audit->method('accepted')->willReturnCallback(
			function (HardeningFact $change): void {
				$this->audited[] = ['accepted', $change->fact, $change->before, $change->after, null];
			}
		);

		return new ElevationService(
			identity: new ElevationIdentity(session: $session, userSession: $userSession, users: $users),
			time: $time,
			posture: new HardeningPosture(
				policy: new HardeningPolicy($appConfig),
				store: new HardeningSettingsStore($appConfig, $this->createMock(AuditTrailMapperInterface::class), $this->createMock(LoggerInterface::class)),
				audit: $audit,
				platform: $this->createMock(PlatformSecurityReader::class),
			)
		);
	}//end service()

	/**
	 * Nobody signed in, or an empty password, is refused without checking any password.
	 *
	 * @return void
	 */
	public function testNoAccountOrNoPasswordIsRefusedUnchecked(): void {
		foreach ([[null, 'secret'], ['beheerder', '']] as [$uid, $password]) {
			$this->audited = [];
			$this->assertFalse($this->service($uid, true)->elevate($password));
			$this->assertSame(
				[['refused', 'elevation.refused', '', '', 'No signed-in account, or no password given.']],
				$this->audited
			);
		}

		$this->assertSame([], $this->checked);
		$this->assertSame([], $this->stored);
	}//end testNoAccountOrNoPasswordIsRefusedUnchecked()

	/**
	 * A password that is not confirmed is refused, naming the account, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnUnconfirmedPasswordIsRefused(): void {
		$this->assertFalse($this->service('beheerder', false)->elevate('wrong'));

		$this->assertSame([['beheerder', 'wrong']], $this->checked);
		$this->assertSame(
			[['refused', 'elevation.refused', '', 'beheerder', 'The password was not confirmed.']],
			$this->audited
		);
		$this->assertSame([], $this->stored);
	}//end testAnUnconfirmedPasswordIsRefused()

	/**
	 * A confirmed password stores the moment and audits the grant with its period.
	 *
	 * @return void
	 */
	public function testAConfirmedPasswordIsGrantedAndAudited(): void {
		$this->assertTrue($this->service('beheerder', true)->elevate('right'));

		$this->assertSame([1758182400], array_values($this->stored));
		$this->assertCount(1, $this->audited);
		$this->assertSame(['accepted', 'elevation.granted', ''], array_slice($this->audited[0], 0, 3));
		$this->assertSame('beheerder', $this->audited[0][3]['user']);
		$this->assertIsInt($this->audited[0][3]['periodSeconds']);
	}//end testAConfirmedPasswordIsGrantedAndAudited()
}//end class
