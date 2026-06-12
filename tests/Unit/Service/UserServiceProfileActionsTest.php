<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\UserService;
use OCP\Accounts\IAccountManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAvatarManager;
use OCP\IAvatar;
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
 * Unit tests for UserService profile action methods
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class UserServiceProfileActionsTest extends TestCase
{
    private UserService $service;
    private IUserManager&MockObject $userManager;
    private IUserSession&MockObject $userSession;
    private IConfig&MockObject $config;
    private IGroupManager&MockObject $groupManager;
    private IAccountManager&MockObject $accountManager;
    private LoggerInterface&MockObject $logger;
    private OrganisationService&MockObject $organisationService;
    private IEventDispatcher&MockObject $eventDispatcher;
    private IAvatarManager&MockObject $avatarManager;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private ISecureRandom&MockObject $secureRandom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->accountManager = $this->createMock(IAccountManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->avatarManager = $this->createMock(IAvatarManager::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);

        $this->service = new UserService(
            $this->userManager,
            $this->userSession,
            $this->config,
            $this->groupManager,
            $this->accountManager,
            $this->logger,
            $this->organisationService,
            $this->eventDispatcher,
            $this->avatarManager,
            $this->auditTrailMapper,
            $this->secureRandom,
            $this->createMock(IDBConnection::class),
            $this->createMock(IFactory::class)
        );
    }

    // ── changePassword() ──

    public function testChangePasswordSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');
        $user->method('canChangePassword')->willReturn(true);
        $user->method('setPassword')->willReturn(true);

        $this->userManager->method('checkPassword')->willReturn($user);

        $result = $this->service->changePassword($user, 'OldPass!', 'NewPass!');

        $this->assertTrue($result['success']);
        $this->assertEquals('Password updated successfully', $result['message']);
    }

    public function testChangePasswordBackendUnsupported(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('canChangePassword')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);

        $this->service->changePassword($user, 'old', 'new');
    }

    public function testChangePasswordIncorrectCurrent(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');
        $user->method('canChangePassword')->willReturn(true);

        $this->userManager->method('checkPassword')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);

        $this->service->changePassword($user, 'wrong', 'new');
    }

    public function testChangePasswordPolicyViolation(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');
        $user->method('canChangePassword')->willReturn(true);
        $user->method('setPassword')->willReturn(false);

        $this->userManager->method('checkPassword')->willReturn($user);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(400);

        $this->service->changePassword($user, 'old', 'abc');
    }

    // ── uploadAvatar() ──

    public function testUploadAvatarSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');
        $user->method('canChangeAvatar')->willReturn(true);

        $avatar = $this->createMock(IAvatar::class);
        $avatar->expects($this->once())->method('set');
        $this->avatarManager->method('getAvatar')->willReturn($avatar);

        $result = $this->service->uploadAvatar($user, 'imagedata', 'image/jpeg', 1024);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('/avatar/jan/128', $result['avatarUrl']);
    }

    public function testUploadAvatarUnsupportedType(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('canChangeAvatar')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(400);

        $this->service->uploadAvatar($user, 'data', 'image/bmp', 1024);
    }

    public function testUploadAvatarTooLarge(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('canChangeAvatar')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(400);

        $this->service->uploadAvatar($user, 'data', 'image/jpeg', 6000000);
    }

    public function testUploadAvatarBackendUnsupported(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('canChangeAvatar')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);

        $this->service->uploadAvatar($user, 'data', 'image/jpeg', 1024);
    }

    // ── deleteAvatar() ──

    public function testDeleteAvatarSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');
        $user->method('canChangeAvatar')->willReturn(true);

        $avatar = $this->createMock(IAvatar::class);
        $avatar->expects($this->once())->method('remove');
        $this->avatarManager->method('getAvatar')->willReturn($avatar);

        $result = $this->service->deleteAvatar($user);

        $this->assertTrue($result['success']);
    }

    // ── getNotificationPreferences() ──

    public function testGetNotificationPreferencesDefaults(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->getNotificationPreferences($user);

        $this->assertTrue($result['objectChanges']);
        $this->assertTrue($result['assignments']);
        $this->assertEquals('daily', $result['emailDigest']);
    }

    public function testGetNotificationPreferencesStored(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')
            ->willReturnMap([
                ['jan', 'openregister', 'notification_objectChanges', '', 'false'],
                ['jan', 'openregister', 'notification_assignments', '', 'true'],
                ['jan', 'openregister', 'notification_organisationChanges', '', ''],
                ['jan', 'openregister', 'notification_systemAnnouncements', '', ''],
                ['jan', 'openregister', 'notification_emailDigest', '', 'weekly'],
            ]);

        $result = $this->service->getNotificationPreferences($user);

        $this->assertFalse($result['objectChanges']);
        $this->assertTrue($result['assignments']);
        $this->assertEquals('weekly', $result['emailDigest']);
    }

    // ── setNotificationPreferences() ──

    public function testSetNotificationPreferencesSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn('');
        $this->config->expects($this->atLeastOnce())->method('setUserValue');

        $result = $this->service->setNotificationPreferences($user, ['objectChanges' => false]);

        $this->assertArrayHasKey('objectChanges', $result);
    }

    public function testSetNotificationPreferencesInvalidDigest(): void
    {
        $user = $this->createMock(IUser::class);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->setNotificationPreferences($user, ['emailDigest' => 'hourly']);
    }

    // ── getUserActivity() ──

    public function testGetUserActivitySuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->auditTrailMapper->method('findByActor')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->service->getUserActivity($user);

        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(0, $result['total']);
    }

    // ── Token management ──

    public function testCreateApiTokenSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn('');
        $this->secureRandom->method('generate')
            ->willReturnOnConsecutiveCalls('abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab', 'tokenid123456789');

        $result = $this->service->createApiToken($user, 'CI Pipeline', '90d');

        $this->assertEquals('CI Pipeline', $result['name']);
        $this->assertNotEmpty($result['token']);
        $this->assertNotNull($result['expires']);
    }

    public function testCreateApiTokenMaxReached(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $tokens = [];
        for ($i = 0; $i < 10; $i++) {
            $tokens["token_$i"] = ['id' => "token_$i", 'name' => "Token $i"];
        }
        $this->config->method('getUserValue')->willReturn(json_encode($tokens));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(400);

        $this->service->createApiToken($user, 'One more');
    }

    public function testListApiTokensMasked(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn(json_encode([
            'tok1' => [
                'id' => 'tok1',
                'name' => 'CI',
                'preview' => 'abcd',
                'created' => '2026-03-24T10:00:00Z',
            ],
        ]));

        $result = $this->service->listApiTokens($user);

        $this->assertCount(1, $result);
        $this->assertEquals('****abcd', $result[0]['preview']);
    }

    public function testRevokeApiTokenSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn(json_encode([
            'tok1' => ['id' => 'tok1', 'name' => 'CI'],
        ]));

        $result = $this->service->revokeApiToken($user, 'tok1');
        $this->assertTrue($result['success']);
    }

    public function testRevokeApiTokenNotFound(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(404);

        $this->service->revokeApiToken($user, 'nonexistent');
    }

    // ── Deactivation ──

    public function testRequestDeactivationSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->requestDeactivation($user, 'Leaving');

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
    }

    public function testRequestDeactivationDuplicate(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn(
            json_encode(['status' => 'pending', 'requestedAt' => '2026-03-24T10:00:00Z'])
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);

        $this->service->requestDeactivation($user, 'Again');
    }

    public function testGetDeactivationStatusActive(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->getDeactivationStatus($user);

        $this->assertEquals('active', $result['status']);
        $this->assertNull($result['pendingRequest']);
    }

    public function testGetDeactivationStatusPending(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn(
            json_encode(['status' => 'pending', 'requestedAt' => '2026-03-24T10:00:00Z'])
        );

        $result = $this->service->getDeactivationStatus($user);

        $this->assertEquals('pending', $result['status']);
        $this->assertNotNull($result['pendingRequest']);
    }

    public function testCancelDeactivationSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn(
            json_encode(['status' => 'pending'])
        );

        $result = $this->service->cancelDeactivation($user);

        $this->assertTrue($result['success']);
        $this->assertEquals('active', $result['status']);
    }

    public function testCancelDeactivationNoPending(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        $this->config->method('getUserValue')->willReturn('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(404);

        $this->service->cancelDeactivation($user);
    }

    // ── exportPersonalData() ──

    public function testExportPersonalDataRateLimited(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');

        // Last export was 5 minutes ago.
        $this->config->method('getUserValue')
            ->willReturnMap([
                ['jan', 'openregister', 'last_export_time', '0', (string)(time() - 300)],
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(429);

        $this->service->exportPersonalData($user);
    }
}
