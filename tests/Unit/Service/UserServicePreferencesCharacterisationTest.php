<?php

/**
 * Characterisation of the notification preference read and write, and of the API token expiry check.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\User\UserDirectory;
use OCA\OpenRegister\Service\User\UserPlatform;
use OCA\OpenRegister\Service\User\UserStorage;
use OCA\OpenRegister\Service\UserService;
use OCP\Accounts\IAccountManager;
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
use ReflectionMethod;

/**
 * getNotificationPreferences, setNotificationPreferences and requestedExpiry.
 *
 * @covers \OCA\OpenRegister\Service\UserService
 */
class UserServicePreferencesCharacterisationTest extends TestCase {

	private UserService $service;
	private IConfig&MockObject $config;
	private IUser&MockObject $user;

	/**
	 * Build the service over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->user   = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('jan');

		$this->service = new UserService(
			directory: new UserDirectory(
				userManager: $this->createMock(IUserManager::class),
				groupManager: $this->createMock(IGroupManager::class),
				accountManager: $this->createMock(IAccountManager::class),
				avatarManager: $this->createMock(IAvatarManager::class)
			),
			platform: new UserPlatform(
				userSession: $this->createMock(IUserSession::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				l10nFactory: $this->createMock(IFactory::class),
				logger: $this->createMock(LoggerInterface::class)
			),
			storage: new UserStorage(
				config: $this->config,
				db: $this->createMock(IDBConnection::class),
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				secureRandom: $this->createMock(ISecureRandom::class)
			),
			organisationService: $this->createMock(OrganisationService::class)
		);
	}//end setUp()

	/**
	 * Stored values: '1' and 'true' are true, anything else false for a boolean; a string is kept.
	 *
	 * @return void
	 */
	public function testStoredValuesAreConverted(): void {
		$this->config->method('getUserValue')->willReturnMap(
			[
				['jan', 'openregister', 'notification_objectChanges', '', '1'],
				['jan', 'openregister', 'notification_assignments', '', 'yes'],
				['jan', 'openregister', 'notification_organisationChanges', '', 'true'],
				['jan', 'openregister', 'notification_systemAnnouncements', '', ''],
				['jan', 'openregister', 'notification_emailDigest', '', 'none'],
			]
		);

		$this->assertSame(
			['objectChanges' => true, 'assignments' => false, 'organisationChanges' => true, 'systemAnnouncements' => true, 'emailDigest' => 'none'],
			$this->service->getNotificationPreferences($this->user)
		);
	}//end testStoredValuesAreConverted()

	/**
	 * Known keys are stored in order as strings; unknown keys are skipped; the full set is returned.
	 *
	 * @return void
	 */
	public function testKnownKeysAreStoredAsStrings(): void {
		$stored = [];
		$this->config->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $value) use (&$stored): void {
				$stored[] = [$uid, $app, $key, $value];
			}
		);
		$this->config->method('getUserValue')->willReturn('');

		$result = $this->service->setNotificationPreferences(
			$this->user,
			['assignments' => false, 'bogus' => true, 'objectChanges' => true, 'emailDigest' => 'weekly', 'systemAnnouncements' => 0]
		);

		$this->assertSame(
			[
				['jan', 'openregister', 'notification_assignments', 'false'],
				['jan', 'openregister', 'notification_objectChanges', 'true'],
				['jan', 'openregister', 'notification_emailDigest', 'weekly'],
				['jan', 'openregister', 'notification_systemAnnouncements', '0'],
			],
			$stored
		);
		$this->assertSame('daily', $result['emailDigest']);
	}//end testKnownKeysAreStoredAsStrings()

	/**
	 * An invalid digest is refused before anything is stored; a null digest is not checked.
	 *
	 * @return void
	 */
	public function testAnInvalidDigestIsRefusedFirst(): void {
		$this->config->expects($this->never())->method('setUserValue');
		try {
			$this->service->setNotificationPreferences($this->user, ['objectChanges' => true, 'emailDigest' => 'hourly']);
			$this->fail('expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Invalid emailDigest value. Allowed: none, daily, weekly', $e->getMessage());
		}
	}//end testAnInvalidDigestIsRefusedFirst()

	/**
	 * A null digest skips the digest check and is stored as ''.
	 *
	 * @return void
	 */
	public function testANullDigestIsNotChecked(): void {
		$this->config->expects($this->once())->method('setUserValue')->with('jan', 'openregister', 'notification_emailDigest', '');
		$this->config->method('getUserValue')->willReturn('');

		$this->service->setNotificationPreferences($this->user, ['emailDigest' => null]);
	}//end testANullDigestIsNotChecked()

	/**
	 * No lifetime is no expiry; a valid one is an ISO date; a malformed one is refused.
	 *
	 * @return void
	 */
	public function testRequestedExpiry(): void {
		$method = new ReflectionMethod(UserService::class, 'requestedExpiry');

		$this->assertNull($method->invoke($this->service, null));
		$this->assertNull($method->invoke($this->service, ''));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $method->invoke($this->service, '90d'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid expiresIn value "5x" — expected a number followed by d (days), h (hours), or m (minutes), e.g. "90d".');
		$method->invoke($this->service, '5x');
	}//end testRequestedExpiry()
}//end class
