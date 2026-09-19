<?php

/**
 * Unit tests for ElevationService.
 *
 * Every test here drives the principal that must be refused: a signed-in
 * administrator who has not confirmed a password, or whose period has run out.
 * A test that only proved "elevating works" would pass on a guard that never
 * refuses anything, which is the failure mode worth catching.
 *
 * The clock is a double so the period can be crossed without waiting, and the
 * session is a real array behind the interface, because what matters is what
 * the NEXT read sees rather than that a setter was called.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Service\Hardening\ElevationRequiredException;
use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\ElevationService
 */
class ElevationServiceTest extends TestCase {

	private HardeningAuditWriter&MockObject $audit;
	private IUserManager&MockObject $users;
	private ElevationService $service;
	private int $now = 1758182400;

	/** @var array<string, mixed> */
	private array $session = [];

	protected function setUp(): void {
		parent::setUp();

		$session = $this->createMock(ISession::class);
		$session->method('set')->willReturnCallback(
			function (string $key, $value): void {
				$this->session[$key] = $value;
			}
		);
		$session->method('get')->willReturnCallback(
			fn (string $key) => ($this->session[$key] ?? null)
		);
		$session->method('remove')->willReturnCallback(
			function (string $key): void {
				unset($this->session[$key]);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->users = $this->createMock(IUserManager::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$this->audit = $this->createMock(HardeningAuditWriter::class);

		$this->service = new ElevationService(
			session: $session,
			userSession: $userSession,
			users: $this->users,
			time: $time,
			policy: new HardeningPolicy($appConfig),
			audit: $this->audit
		);
	}

	// ---- Task 2.1: an open session is not an elevated one. -----------------

	public function testASignedInAdministratorIsNotElevatedUntilThePasswordIsConfirmed(): void {
		$this->assertFalse($this->service->isElevated());
		$this->expectException(ElevationRequiredException::class);
		$this->service->requireElevated();
	}

	public function testAWrongPasswordElevatesNothingAndIsAudited(): void {
		$this->users->method('checkPassword')->willReturn(false);
		$this->audit->expects($this->atLeastOnce())->method('record');

		$this->assertFalse($this->service->elevate(password: 'guess'));
		$this->assertFalse($this->service->isElevated());
	}

	public function testAnEmptyPasswordIsRefusedWithoutAskingTheUserManager(): void {
		$this->users->expects($this->never())->method('checkPassword');

		$this->assertFalse($this->service->elevate(password: ''));
	}

	public function testAConfirmedPasswordStartsThePeriod(): void {
		$this->users->method('checkPassword')->willReturn($this->createMock(IUser::class));

		$this->assertTrue($this->service->elevate(password: 'correct horse'));
		$this->assertTrue($this->service->isElevated());
		$this->assertSame(900, $this->service->remainingSeconds());
	}

	// ---- Task 2.2: the period lapses, and the write is refused after it. ---

	public function testTheWriteIsRefusedOnceTheAdministeredPeriodHasPassed(): void {
		$this->users->method('checkPassword')->willReturn($this->createMock(IUser::class));
		$this->service->elevate(password: 'correct horse');

		$this->now += ($this->service->periodSeconds() + 1);

		$this->assertFalse($this->service->isElevated(), 'the elevated period must end by itself');
		$this->assertSame(0, $this->service->remainingSeconds());
		$this->expectException(ElevationRequiredException::class);
		$this->service->requireElevated();
	}

	public function testTheRefusalNamesHowLongAnElevatedSessionLastsHere(): void {
		try {
			$this->service->requireElevated();
			$this->fail('an unelevated session must be refused');
		} catch (ElevationRequiredException $refusal) {
			$this->assertSame(900, $refusal->getPeriodSeconds());
			$this->assertTrue($refusal->toArray()['elevationRequired']);
		}
	}

	public function testDroppingTheElevationEndsItWithoutEndingTheSession(): void {
		$this->users->method('checkPassword')->willReturn($this->createMock(IUser::class));
		$this->service->elevate(password: 'correct horse');

		$this->service->drop();

		$this->assertFalse($this->service->isElevated());
	}

	// ---- Fail closed on a clock or a value it cannot trust. ----------------

	public function testAStoredMomentInTheFutureCountsAsNoElevation(): void {
		$this->session[ElevationService::SESSION_KEY] = ($this->now + 5000);

		$this->assertFalse($this->service->isElevated());
	}

	public function testAStoredValueThatIsNotAMomentCountsAsNoElevation(): void {
		$this->session[ElevationService::SESSION_KEY] = ['not', 'a', 'moment'];

		$this->assertFalse($this->service->isElevated());
	}

	// ---- Task 2.3: the grant and the refusal are both on the record. -------

	public function testTheGrantIsWrittenToTheAuditTrail(): void {
		$this->users->method('checkPassword')->willReturn($this->createMock(IUser::class));
		$this->audit->expects($this->once())
			->method('record')
			->with(
				'elevation.granted',
				'',
				['user' => 'beheerder', 'periodSeconds' => 900],
				true
			);

		$this->service->elevate(password: 'correct horse');
	}

	public function testALapsedWriteAttemptIsWrittenToTheAuditTrail(): void {
		$this->audit->expects($this->once())
			->method('record')
			->with('elevation.lapsed', '', 'beheerder', false, $this->stringContains('lapsed'));

		try {
			$this->service->requireElevated();
		} catch (ElevationRequiredException) {
			// The audit row is the assertion; the refusal itself is asserted above.
		}
	}
}//end class
