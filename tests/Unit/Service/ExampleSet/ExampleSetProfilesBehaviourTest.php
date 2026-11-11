<?php

/**
 * Unit test for ExampleSetProfiles: what apply writes for a cast person's
 * profile, absence and status, and the reasons it gives when a part fails.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetProfiles;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetProfiles
 */
class ExampleSetProfilesBehaviourTest extends TestCase {

	/**
	 * The accounts.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The profile properties.
	 *
	 * @var IAccountManager&MockObject
	 */
	private IAccountManager&MockObject $profiles;

	/**
	 * The calls the absence and status services received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * What the absence and status services throw, by method name.
	 *
	 * @var array<string, string>
	 */
	private array $failing = [];

	/**
	 * A profile with nothing in it.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY = ['headline' => '', 'role' => '', 'organisation' => '', 'absence' => null, 'status' => null];

	/**
	 * Mocked account managers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->users    = $this->createMock(IUserManager::class);
		$this->profiles = $this->createMock(IAccountManager::class);
	}//end setUp()

	/**
	 * The class under test, with a container that hands out recording absence and status services.
	 *
	 * @param bool $appsThere Whether the dav and user_status services are there.
	 *
	 * @return ExampleSetProfiles
	 */
	private function subject(bool $appsThere=true): ExampleSetProfiles {
		$test    = $this;
		$service = new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param ExampleSetProfilesBehaviourTest $test The test that records the calls.
			 */
			public function __construct(private readonly ExampleSetProfilesBehaviourTest $test) {
			}

			/**
			 * Record a call.
			 *
			 * @param string            $name The method.
			 * @param array<int, mixed> $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				return $this->test->record(name: $name, args: $args);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($service, $appsThere): object {
				if ($appsThere === false) {
					throw new RuntimeException($id.' is not there');
				}

				return $service;
			}
		);
		return new ExampleSetProfiles($this->users, $this->profiles, $container);
	}//end subject()

	/**
	 * Record a service call, or throw what the test says it throws.
	 *
	 * @param string            $name The method.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return null
	 */
	public function record(string $name, array $args): mixed {
		if (isset($this->failing[$name]) === true) {
			throw new RuntimeException($this->failing[$name]);
		}

		$this->calls[] = array_merge([$name], array_map(fn ($a) => $a instanceof IUser ? 'user:'.$a->getUID() : $a, $args));
		return null;
	}//end record()

	/**
	 * A user that answers its uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->users->method('get')->willReturnCallback(fn (string $id): ?IUser => $id === $uid ? $user : null);
		return $user;
	}//end user()

	/**
	 * An account that is not there gets nothing written, also when profiles are filled in.
	 *
	 * @return void
	 */
	public function testAMissingAccountGetsNothing(): void {
		$this->users->method('get')->willReturn(null);
		$this->profiles->expects($this->never())->method('getAccount');

		$this->assertSame(['account ghost is not there'], $this->subject()->apply(uid: 'ghost', profile: self::EMPTY));
		$this->assertSame(['account ghost is not there'], $this->subject()->fillMissing(uid: 'ghost', profile: self::EMPTY));
	}//end testAMissingAccountGetsNothing()

	/**
	 * An empty profile writes no property, absence or status.
	 *
	 * @return void
	 */
	public function testAnEmptyProfileWritesNothing(): void {
		$this->user(uid: 'anna');
		$this->profiles->expects($this->never())->method('getAccount');

		$this->assertSame([], $this->subject()->apply(uid: 'anna', profile: self::EMPTY));
		$this->assertSame([], $this->calls);
	}//end testAnEmptyProfileWritesNothing()

	/**
	 * The filled properties are written locally and unverified, the empty ones are skipped.
	 *
	 * @return void
	 */
	public function testFilledPropertiesAreWritten(): void {
		$user    = $this->user(uid: 'anna');
		$account = $this->createMock(IAccount::class);
		$this->profiles->method('getAccount')->with($user)->willReturn($account);
		$account->expects($this->once())->method('setProperty')
			->with(IAccountManager::PROPERTY_ROLE, 'Behandelaar', IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
		$this->profiles->expects($this->once())->method('updateAccount')->with($account);

		$this->assertSame([], $this->subject()->apply(uid: 'anna', profile: ['role' => 'Behandelaar'] + self::EMPTY));
	}//end testFilledPropertiesAreWritten()

	/**
	 * A property write that fails is reported with the account.
	 *
	 * @return void
	 */
	public function testAFailingPropertyWriteIsReported(): void {
		$this->user(uid: 'anna');
		$this->profiles->method('getAccount')->willThrowException(new RuntimeException('ldap is read-only'));

		$this->assertSame(
			['profile of anna: ldap is read-only'],
			$this->subject()->apply(uid: 'anna', profile: ['headline' => 'Jurist'] + self::EMPTY)
		);
	}//end testAFailingPropertyWriteIsReported()

	/**
	 * An absence is written through the absence service, and a status with message and icon through the status service.
	 *
	 * @return void
	 */
	public function testAbsenceAndStatusAreWritten(): void {
		$this->user(uid: 'anna');
		$profile = [
			'absence' => ['firstDay' => '2026-10-10', 'lastDay' => '2026-10-20', 'status' => 'Vakantie', 'message' => 'Terug op 21'],
			'status'  => ['status' => 'away', 'message' => 'Even weg', 'icon' => '🌴'],
		] + self::EMPTY;

		$this->assertSame([], $this->subject()->apply(uid: 'anna', profile: $profile));
		$this->assertSame(
			[
				['createOrUpdateAbsence', 'user:anna', '2026-10-10', '2026-10-20', 'Vakantie', 'Terug op 21'],
				['setStatus', 'anna', 'away', null, true],
				['setCustomMessage', 'anna', '🌴', 'Even weg', null],
			],
			$this->calls
		);
	}//end testAbsenceAndStatusAreWritten()

	/**
	 * A status without a message sets no custom message, and one without an icon sets a null icon.
	 *
	 * @return void
	 */
	public function testAStatusWithoutMessageOrIcon(): void {
		$this->user(uid: 'anna');

		$this->subject()->apply(uid: 'anna', profile: ['status' => ['status' => 'dnd', 'message' => '', 'icon' => '']] + self::EMPTY);
		$this->subject()->apply(uid: 'anna', profile: ['status' => ['status' => 'online', 'message' => 'Hier', 'icon' => '']] + self::EMPTY);

		$this->assertSame(
			[
				['setStatus', 'anna', 'dnd', null, true],
				['setStatus', 'anna', 'online', null, true],
				['setCustomMessage', 'anna', null, 'Hier', null],
			],
			$this->calls
		);
	}//end testAStatusWithoutMessageOrIcon()

	/**
	 * Without the dav and user_status apps the absence and status are reported, not thrown.
	 *
	 * @return void
	 */
	public function testMissingAppsAreReported(): void {
		$this->user(uid: 'anna');
		$profile = [
			'absence' => ['firstDay' => '2026-10-10', 'lastDay' => '2026-10-20', 'status' => 'Vakantie', 'message' => ''],
			'status'  => ['status' => 'away', 'message' => '', 'icon' => ''],
		] + self::EMPTY;

		$this->assertSame(
			[
				'absence of anna: OCA\DAV\Service\AbsenceService is not there',
				'status of anna: OCA\UserStatus\Service\StatusService is not there',
			],
			$this->subject(appsThere: false)->apply(uid: 'anna', profile: $profile)
		);
	}//end testMissingAppsAreReported()

	/**
	 * A service that refuses is reported with its message.
	 *
	 * @return void
	 */
	public function testARefusingServiceIsReported(): void {
		$this->user(uid: 'anna');
		$this->failing = ['createOrUpdateAbsence' => 'last day before first', 'setStatus' => 'unknown status'];
		$profile       = [
			'absence' => ['firstDay' => '2026-10-20', 'lastDay' => '2026-10-10', 'status' => '', 'message' => ''],
			'status'  => ['status' => 'sleeping', 'message' => '', 'icon' => ''],
		] + self::EMPTY;

		$this->assertSame(
			['absence of anna: last day before first', 'status of anna: unknown status'],
			$this->subject()->apply(uid: 'anna', profile: $profile)
		);
	}//end testARefusingServiceIsReported()

	/**
	 * Filling in an account that already carries every value writes nothing.
	 *
	 * @return void
	 */
	public function testFillingInAFullAccountWritesNothing(): void {
		$user     = $this->user(uid: 'anna');
		$account  = $this->createMock(IAccount::class);
		$property = $this->createMock(IAccountProperty::class);
		$property->method('getValue')->willReturn('Jurist');
		$account->method('getProperty')->willReturn($property);
		$this->profiles->method('getAccount')->with($user)->willReturn($account);
		$account->expects($this->never())->method('setProperty');
		$this->profiles->expects($this->never())->method('updateAccount');

		$this->assertSame([], $this->subject()->fillMissing(uid: 'anna', profile: ['headline' => 'Advocaat'] + self::EMPTY));
	}//end testFillingInAFullAccountWritesNothing()

	/**
	 * Filling in an account whose read fails reports why.
	 *
	 * @return void
	 */
	public function testAFailingFillInIsReported(): void {
		$this->user(uid: 'anna');
		$this->profiles->method('getAccount')->willThrowException(new RuntimeException('no account table'));

		$this->assertSame(['profile of anna: no account table'], $this->subject()->fillMissing(uid: 'anna', profile: ['role' => 'x'] + self::EMPTY));
	}//end testAFailingFillInIsReported()
}//end class
