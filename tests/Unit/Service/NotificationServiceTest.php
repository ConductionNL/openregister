<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Service\NotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationServiceTest extends TestCase
{
    private IManager&MockObject $notificationManager;
    private IGroupManager&MockObject $groupManager;
    private LoggerInterface&MockObject $logger;
    private NotificationService $service;

    protected function setUp(): void
    {
        $this->notificationManager = $this->createMock(IManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // NotificationService has no constructor, so we use reflection to set readonly props
        $this->service = new NotificationService();
        $ref = new \ReflectionClass($this->service);

        $prop = $ref->getProperty('notificationManager');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->notificationManager);

        $prop = $ref->getProperty('groupManager');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->groupManager);

        $prop = $ref->getProperty('logger');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->logger);
    }

    private function createConfiguration(int $id, string $title, array $groups = [], ?string $localVersion = '1.0', ?string $remoteVersion = '2.0'): Configuration
    {
        $config = new Configuration();
        $config->setTitle($title);
        $config->setNotificationGroups($groups);
        $config->setLocalVersion($localVersion);
        $config->setRemoteVersion($remoteVersion);
        $ref = new \ReflectionClass($config);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($config, $id);
        return $config;
    }

    private function createUser(string $uid): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function createGroup(string $groupId, array $users): IGroup&MockObject
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn($users);
        return $group;
    }

    public function testNotifyConfigurationUpdateSendsToAdminGroup(): void
    {
        $config = $this->createConfiguration(1, 'TestConfig');
        $adminUser = $this->createUser('admin');
        $adminGroup = $this->createGroup('admin', [$adminUser]);

        $this->groupManager->method('get')->willReturnMap([
            ['admin', $adminGroup],
        ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $count = $this->service->notifyConfigurationUpdate($config);

        $this->assertSame(1, $count);
    }

    public function testNotifyConfigurationUpdateAlwaysIncludesAdmin(): void
    {
        $config = $this->createConfiguration(1, 'TestConfig', ['editors']);
        $user1 = $this->createUser('editor1');
        $adminUser = $this->createUser('admin');

        $editorsGroup = $this->createGroup('editors', [$user1]);
        $adminGroup = $this->createGroup('admin', [$adminUser]);

        $this->groupManager->method('get')->willReturnMap([
            ['editors', $editorsGroup],
            ['admin', $adminGroup],
        ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->exactly(2))->method('notify');

        $count = $this->service->notifyConfigurationUpdate($config);

        $this->assertSame(2, $count);
    }

    public function testNotifyConfigurationUpdateDeduplicatesUsers(): void
    {
        $config = $this->createConfiguration(1, 'TestConfig', ['admin']);
        $adminUser = $this->createUser('admin');
        $adminGroup = $this->createGroup('admin', [$adminUser]);

        // admin is already in the notificationGroups, should not be added twice
        $this->groupManager->method('get')->willReturnMap([
            ['admin', $adminGroup],
        ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);
        // admin should only be notified once even though it appears in both the explicit list and forced admin
        $this->notificationManager->expects($this->once())->method('notify');

        $count = $this->service->notifyConfigurationUpdate($config);

        $this->assertSame(1, $count);
    }

    public function testNotifyConfigurationUpdateSkipsNonexistentGroup(): void
    {
        $config = $this->createConfiguration(1, 'TestConfig', ['nonexistent']);
        $adminUser = $this->createUser('admin');
        $adminGroup = $this->createGroup('admin', [$adminUser]);

        $this->groupManager->method('get')->willReturnMap([
            ['nonexistent', null],
            ['admin', $adminGroup],
        ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $count = $this->service->notifyConfigurationUpdate($config);

        $this->assertSame(1, $count);
    }

    public function testNotifyConfigurationUpdateHandlesNotificationFailure(): void
    {
        $config = $this->createConfiguration(1, 'TestConfig');
        $adminUser = $this->createUser('admin');
        $adminGroup = $this->createGroup('admin', [$adminUser]);

        $this->groupManager->method('get')->willReturnMap([
            ['admin', $adminGroup],
        ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->method('notify')
            ->willThrowException(new \Exception('Notification failed'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $count = $this->service->notifyConfigurationUpdate($config);

        $this->assertSame(0, $count);
    }

    public function testNotifyConfigurationUpdateNoGroups(): void
    {
        $config = $this->createConfiguration(1, 'TestConfig', []);

        // No groups configured, but admin is always added
        $adminUser = $this->createUser('admin');
        $adminGroup = $this->createGroup('admin', [$adminUser]);

        $this->groupManager->method('get')->willReturnMap([
            ['admin', $adminGroup],
        ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);

        $count = $this->service->notifyConfigurationUpdate($config);

        $this->assertSame(1, $count);
    }

    public function testMarkConfigurationUpdated(): void
    {
        $config = $this->createConfiguration(1, 'TestConfig');

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('markProcessed');

        $this->service->markConfigurationUpdated($config);
    }

    public function testNotifyConfigurationUpdateMultipleGroups(): void
    {
        $config = $this->createConfiguration(1, 'TestConfig', ['editors', 'reviewers']);
        $editor = $this->createUser('editor1');
        $reviewer = $this->createUser('reviewer1');
        $adminUser = $this->createUser('admin');

        $editorsGroup = $this->createGroup('editors', [$editor]);
        $reviewersGroup = $this->createGroup('reviewers', [$reviewer]);
        $adminGroup = $this->createGroup('admin', [$adminUser]);

        $this->groupManager->method('get')->willReturnMap([
            ['editors', $editorsGroup],
            ['reviewers', $reviewersGroup],
            ['admin', $adminGroup],
        ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->exactly(3))->method('notify');

        $count = $this->service->notifyConfigurationUpdate($config);

        $this->assertSame(3, $count);
    }
}
