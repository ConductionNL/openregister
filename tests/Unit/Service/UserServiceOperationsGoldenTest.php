<?php

/**
 * Characterisation of UserService's token, activity, avatar and password operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionWindow;
use OCA\OpenRegister\Db\AuditTrail\ActorTrailQuery;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\UserService;
use OCP\Accounts\IAccountManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAvatar;
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
use RuntimeException;
use OCA\OpenRegister\Service\User\ActivityPeriod;
use OCA\OpenRegister\Service\User\ActivityQuery;
use OCA\OpenRegister\Service\User\ApiTokenRequest;
use OCA\OpenRegister\Service\User\AvatarUpload;
use OCA\OpenRegister\Service\User\PasswordChange;

/**
 * Pins what the public operations return and refuse before their bodies move
 * behind value objects (ADR-011 Rule 5).
 */
class UserServiceOperationsGoldenTest extends TestCase {

	private IConfig&MockObject $config;
	private IUserManager&MockObject $userManager;
	private IAvatarManager&MockObject $avatarManager;
	private AuditTrailMapperInterface&MockObject $audit;
	private ISecureRandom&MockObject $random;
	private UserService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->avatarManager = $this->createMock(IAvatarManager::class);
		$this->audit = $this->createMock(AuditTrailMapperInterface::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->random->method('generate')->willReturnCallback(static fn (int $length): string => str_repeat('k', $length));
		$this->service = new UserService(
			$this->userManager,
			$this->createMock(IUserSession::class),
			$this->config,
			$this->createMock(IGroupManager::class),
			$this->createMock(IAccountManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationServiceInterface::class),
			$this->createMock(IEventDispatcher::class),
			$this->avatarManager,
			$this->audit,
			$this->random,
			$this->createMock(IDBConnection::class),
			$this->createMock(IFactory::class)
		);
	}

	private function user(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		return $user;
	}

	public function testAMalformedExpiryIsRefusedAndNothingIsStored(): void {
		$this->config->method('getUserValue')->willReturn('');
		$this->config->expects($this->never())->method('setUserValue');

		$this->expectException(InvalidArgumentException::class);
		$this->service->issueApiToken($this->user(), new ApiTokenRequest('CI', '5x'));
	}

	public function testAnEmptyExpiryMintsANonExpiringTokenAndStoresOnlyItsHash(): void {
		$this->config->method('getUserValue')->willReturn('');
		$stored = null;
		$this->config->expects($this->once())->method('setUserValue')
			->willReturnCallback(function (string $uid, string $app, string $key, string $value) use (&$stored): void {
				$stored = json_decode($value, true);
			});

		$result = $this->service->issueApiToken($this->user(), new ApiTokenRequest('CI', ''));

		$this->assertSame(str_repeat('k', 16), $result['id']);
		$this->assertSame('CI', $result['name']);
		$this->assertSame(str_repeat('k', 64), $result['token']);
		$this->assertNull($result['expires']);
		$this->assertSame(['id', 'name', 'token', 'created', 'expires'], array_keys($result));
		$row = $stored[str_repeat('k', 16)];
		$this->assertSame(hash('sha256', str_repeat('k', 64)), $row['token']);
		$this->assertSame('kkkk', $row['preview']);
		$this->assertNull($row['lastUsed']);
		$this->assertNull($row['expires']);
	}

	public function testAValidExpiryIsInTheFuture(): void {
		$this->config->method('getUserValue')->willReturn('');
		$result = $this->service->issueApiToken($this->user(), new ApiTokenRequest('CI', '2h'));
		$this->assertGreaterThan(time(), strtotime($result['expires']));
	}

	public function testActivityPassesItsFiltersAndShapesEachEntry(): void {
		$entry = new AuditTrail();
		$entry->setAction('update');
		$entry->setObjectUuid('o-1');
		$this->audit->expects($this->once())->method('findActorTrail')
			->with(new ActorTrailQuery('jan', 5, 10, new ActionWindow('update', '2026-01-01', '2026-02-01')))
			->willReturn(['results' => [$entry], 'total' => 7]);

		$result = $this->service->activity($this->user(), new ActivityQuery(5, 10, 'update', new ActivityPeriod('2026-01-01', '2026-02-01')));

		$this->assertSame(7, $result['total']);
		$this->assertSame('update', $result['results'][0]['type']);
		$this->assertSame('o-1', $result['results'][0]['objectUuid']);
		$this->assertSame('update on object', $result['results'][0]['summary']);
	}

	public function testActivityDefaultsToTheFirstPageWithoutFilters(): void {
		$this->audit->expects($this->once())->method('findActorTrail')
			->with(new ActorTrailQuery('jan', 25, 0, new ActionWindow(null, null, null)))
			->willReturn(['results' => [], 'total' => 0]);

		$this->assertSame(['results' => [], 'total' => 0], $this->service->activity($this->user(), new ActivityQuery()));
	}

	public function testAnAllowedAvatarIsStoredAndARefusedOneIsNot(): void {
		$user = $this->user();
		$user->method('canChangeAvatar')->willReturn(true);
		$avatar = $this->createMock(IAvatar::class);
		$avatar->expects($this->once())->method('set')->with('bytes');
		$this->avatarManager->method('getAvatar')->willReturn($avatar);

		$this->assertSame(
			['success' => true, 'avatarUrl' => '/avatar/jan/128'],
			$this->service->setAvatar($user, new AvatarUpload('bytes', 'image/png', 5242880))
		);

		try {
			$this->service->setAvatar($user, new AvatarUpload('bytes', 'image/png', 5242881));
			$this->fail('An avatar over the limit must be refused.');
		} catch (RuntimeException $exception) {
			$this->assertSame(400, $exception->getCode());
		}
	}

	public function testAPasswordIsOnlySetAfterTheCurrentOneIsVerified(): void {
		$user = $this->user();
		$user->method('canChangePassword')->willReturn(true);
		$this->userManager->method('checkPassword')->willReturnCallback(
			static fn (string $uid, string $password): IUser|false => $password === 'right' ? $user : false
		);
		$user->expects($this->once())->method('setPassword')->with('new')->willReturn(true);

		try {
			$this->service->applyPasswordChange($user, new PasswordChange('wrong', 'new'));
			$this->fail('A wrong current password must be refused.');
		} catch (RuntimeException $exception) {
			$this->assertSame(403, $exception->getCode());
		}

		$this->assertSame(
			['success' => true, 'message' => 'Password updated successfully'],
			$this->service->applyPasswordChange($user, new PasswordChange('right', 'new'))
		);
	}
}
