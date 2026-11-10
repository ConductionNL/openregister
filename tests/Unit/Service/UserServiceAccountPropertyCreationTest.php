<?php

/**
 * UserService account-property creation tests.
 *
 * Covers the branch in updateProfileProperties() that CREATES an account
 * property the user has never set before. That path was unreachable until
 * openregister#2697: the code guarded on `$account->getProperty($p) !== null`,
 * but IAccount::getProperty() is declared `: IAccountProperty` and signals a
 * missing property by THROWING PropertyDoesNotExistException — so the guard
 * was always true, the `continue` always ran, and the create call below it
 * never executed. The exception escaped to the method's outer catch, which
 * logged a warning and abandoned the WHOLE account update, silently dropping
 * the other fields in the same request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\UserService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that a never-before-set profile field is created rather than dropped.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) UserService takes 13 collaborators.
 * @SuppressWarnings(PHPMD.TooManyFields)          One property per collaborator.
 */
class UserServiceAccountPropertyCreationTest extends TestCase {
	private UserService $service;
	private IUserManager&MockObject $userManager;
	private IUserSession&MockObject $userSession;
	private IConfig&MockObject $config;
	private IGroupManager&MockObject $groupManager;
	private IAccountManager&MockObject $accountManager;
	private LoggerInterface&MockObject $logger;

	/**
	 * Wire UserService with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->config = $this->createMock(IConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->accountManager = $this->createMock(IAccountManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// updateUserProperties() snapshots the user via buildUserDataArray()
		// before it touches anything, which reads the group list.
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$this->service = new UserService(
			$this->userManager,
			$this->userSession,
			$this->config,
			$this->groupManager,
			$this->accountManager,
			$this->logger,
			$this->createMock(OrganisationService::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IAvatarManager::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(IFactory::class)
		);
	}//end setUp()

	/**
	 * Build a user whose display name and password are not being changed.
	 *
	 * @return IUser&MockObject The user under test.
	 */
	private function user(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$user->method('canChangeDisplayName')->willReturn(false);
		$user->method('canChangePassword')->willReturn(false);

		return $user;
	}//end user()

	/**
	 * A profile field the user has never set before is CREATED.
	 *
	 * This is the regression test for the unreachable create path: with the old
	 * `!== null` guard the account manager received no setProperty() call at all
	 * and updateAccount() was never reached.
	 *
	 * @return void
	 */
	public function testAProfileFieldTheUserHasNeverSetIsCreated(): void {
		$user = $this->user();

		$account = $this->createMock(IAccount::class);
		// The property does not exist yet — the real IAccount throws here.
		$account->method('getProperty')
			->willThrowException(new PropertyDoesNotExistException('phone'));

		$account->expects($this->once())
			->method('setProperty')
			->with(
				IAccountManager::PROPERTY_PHONE,
				'+31 6 12345678',
				$this->anything(),
				IAccountManager::NOT_VERIFIED
			);

		$this->accountManager->method('getAccount')->willReturn($account);
		$this->accountManager->expects($this->once())->method('updateAccount')->with($account);

		$result = $this->service->updateUserProperties($user, ['phone' => '+31 6 12345678']);

		$this->assertTrue($result['success'], 'Creating a new profile property must report success');
	}//end testAProfileFieldTheUserHasNeverSetIsCreated()

	/**
	 * A field the user already has is UPDATED in place, not re-created.
	 *
	 * The must-FAIL control for the test above: if the create path ran
	 * unconditionally, setProperty() would fire here too.
	 *
	 * @return void
	 */
	public function testAnExistingProfileFieldIsUpdatedInPlace(): void {
		$user = $this->user();

		$property = $this->createMock(IAccountProperty::class);
		$property->method('getValue')->willReturn('old value');
		$property->expects($this->once())->method('setValue')->with('new value');

		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->willReturn($property);
		$account->expects($this->never())->method('setProperty');

		$this->accountManager->method('getAccount')->willReturn($account);
		$this->accountManager->expects($this->once())->method('updateAccount')->with($account);

		$result = $this->service->updateUserProperties($user, ['phone' => 'new value']);

		$this->assertTrue($result['success']);
	}//end testAnExistingProfileFieldIsUpdatedInPlace()

	/**
	 * One missing property does not abandon the other fields in the request.
	 *
	 * This is the user-visible half of the bug: the escaping exception aborted
	 * the whole loop, so a request setting three fields persisted none of them
	 * as soon as one of them was new.
	 *
	 * @return void
	 */
	public function testAMissingPropertyDoesNotDiscardTheOtherFieldsInTheSameRequest(): void {
		$user = $this->user();

		$existing = $this->createMock(IAccountProperty::class);
		$existing->method('getValue')->willReturn('old');
		$existing->expects($this->once())->method('setValue')->with('https://example.org');

		$account = $this->createMock(IAccount::class);
		// `phone` is new (throws); `website` already exists (returns).
		$account->method('getProperty')->willReturnCallback(
			static function (string $property) use ($existing): IAccountProperty {
				if ($property === IAccountManager::PROPERTY_PHONE) {
					throw new PropertyDoesNotExistException($property);
				}

				return $existing;
			}
		);

		$account->expects($this->once())
			->method('setProperty')
			->with(IAccountManager::PROPERTY_PHONE, '+31 6 12345678', $this->anything(), IAccountManager::NOT_VERIFIED);

		$this->accountManager->method('getAccount')->willReturn($account);
		$this->accountManager->expects($this->once())->method('updateAccount')->with($account);

		$result = $this->service->updateUserProperties(
			$user,
			[
				'phone' => '+31 6 12345678',
				'website' => 'https://example.org',
			]
		);

		$this->assertTrue($result['success']);
	}//end testAMissingPropertyDoesNotDiscardTheOtherFieldsInTheSameRequest()
}//end class
