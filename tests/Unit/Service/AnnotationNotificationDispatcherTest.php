<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\AnnotationNotificationDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AnnotationNotificationDispatcher.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5.1
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5.2
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5.3
 */
class AnnotationNotificationDispatcherTest extends TestCase
{
    private INotificationManager&MockObject $notificationManager;
    private IGroupManager&MockObject $groupManager;
    private LoggerInterface&MockObject $logger;
    private AnnotationNotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->dispatcher = new AnnotationNotificationDispatcher(
            notificationManager: $this->notificationManager,
            groupManager: $this->groupManager,
            logger: $this->logger
        );
    }

    private function buildRule(string $trigger = 'updated', ?array $condition = null, array $groups = ['admin']): array
    {
        return [
            'trigger'    => $trigger,
            'condition'  => $condition,
            'recipients' => [['kind' => 'groups', 'groups' => $groups]],
            'subject'    => ['nl' => 'Gewijzigd: {{title}}', 'en' => 'Changed: {{title}}'],
        ];
    }

    private function createUserMock(string $uid): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function createGroupMock(string $groupId, array $users): IGroup&MockObject
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn($users);
        return $group;
    }

    private function createNotificationMock(): INotification&MockObject
    {
        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        return $notification;
    }

    // --- Task 5.1: Synchronization (configuration) failure dispatches to admin ---

    public function testSyncFailureDispatchesNotificationToAdminGroup(): void
    {
        $rules = [
            $this->buildRule(
                trigger: 'updated',
                condition: ['field' => 'syncStatus', 'operator' => 'equals', 'value' => 'failed'],
                groups: ['admin']
            ),
        ];

        $newData = ['id' => 1, 'uuid' => 'abc', 'title' => 'Test Config', 'syncStatus' => 'failed'];
        $oldData = ['id' => 1, 'uuid' => 'abc', 'title' => 'Test Config', 'syncStatus' => 'running'];

        $adminUser = $this->createUserMock('admin_user');
        $adminGroup = $this->createGroupMock('admin', [$adminUser]);
        $this->groupManager->method('get')->willReturnMap([['admin', $adminGroup]]);

        $notification = $this->createNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $count = $this->dispatcher->dispatch(
            entityType: 'configuration',
            trigger: 'updated',
            newData: $newData,
            oldData: $oldData,
            rules: $rules
        );

        $this->assertSame(1, $count);
    }

    // --- Task 5.2: Configuration update dispatches updated rule to admin ---

    public function testConfigurationUpdateDispatchesToAdminGroup(): void
    {
        $rules = [$this->buildRule(trigger: 'updated', condition: null, groups: ['admin'])];

        $newData = ['id' => 2, 'uuid' => 'def', 'title' => 'My Config'];
        $oldData = ['id' => 2, 'uuid' => 'def', 'title' => 'Old Config'];

        $adminUser = $this->createUserMock('admin1');
        $adminGroup = $this->createGroupMock('admin', [$adminUser]);
        $this->groupManager->method('get')->willReturnMap([['admin', $adminGroup]]);

        $notification = $this->createNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $count = $this->dispatcher->dispatch(
            entityType: 'configuration',
            trigger: 'updated',
            newData: $newData,
            oldData: $oldData,
            rules: $rules
        );

        $this->assertSame(1, $count);
    }

    // --- Task 5.3: Source health updated+condition dispatches to integration-ops ---

    public function testSourceHealthConditionDispatchesToGroup(): void
    {
        $rules = [
            $this->buildRule(
                trigger: 'updated',
                condition: ['field' => 'status', 'operator' => 'equals', 'value' => 'unhealthy'],
                groups: ['integration-ops']
            ),
        ];

        $newData = ['id' => 3, 'uuid' => 'ghi', 'title' => 'My Source', 'status' => 'unhealthy'];
        $oldData = ['id' => 3, 'uuid' => 'ghi', 'title' => 'My Source', 'status' => 'healthy'];

        $opsUser = $this->createUserMock('ops_user');
        $opsGroup = $this->createGroupMock('integration-ops', [$opsUser]);
        $this->groupManager->method('get')->willReturnMap([['integration-ops', $opsGroup]]);

        $notification = $this->createNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $count = $this->dispatcher->dispatch(
            entityType: 'source',
            trigger: 'updated',
            newData: $newData,
            oldData: $oldData,
            rules: $rules
        );

        $this->assertSame(1, $count);
    }

    // --- Condition: changed operator ---

    public function testChangedConditionFiresWhenValueDiffers(): void
    {
        $rules = [
            $this->buildRule(
                trigger: 'updated',
                condition: ['field' => 'status', 'operator' => 'changed'],
                groups: ['admin']
            ),
        ];

        $newData = ['id' => 4, 'uuid' => 'jkl', 'title' => 'T', 'status' => 'closed'];
        $oldData = ['id' => 4, 'uuid' => 'jkl', 'title' => 'T', 'status' => 'open'];

        $adminUser = $this->createUserMock('u1');
        $adminGroup = $this->createGroupMock('admin', [$adminUser]);
        $this->groupManager->method('get')->willReturnMap([['admin', $adminGroup]]);

        $notification = $this->createNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $count = $this->dispatcher->dispatch(
            entityType: 'schema',
            trigger: 'updated',
            newData: $newData,
            oldData: $oldData,
            rules: $rules
        );

        $this->assertSame(1, $count);
    }

    public function testChangedConditionDoesNotFireWhenValueSame(): void
    {
        $rules = [
            $this->buildRule(
                trigger: 'updated',
                condition: ['field' => 'status', 'operator' => 'changed'],
                groups: ['admin']
            ),
        ];

        $newData = ['id' => 5, 'uuid' => 'mno', 'title' => 'T', 'status' => 'open'];
        $oldData = ['id' => 5, 'uuid' => 'mno', 'title' => 'T', 'status' => 'open'];

        $this->notificationManager->expects($this->never())->method('notify');

        $count = $this->dispatcher->dispatch(
            entityType: 'schema',
            trigger: 'updated',
            newData: $newData,
            oldData: $oldData,
            rules: $rules
        );

        $this->assertSame(0, $count);
    }

    // --- Fail closed: condition present but old data unavailable ---

    public function testConditionFailsClosedWhenOldDataMissing(): void
    {
        $rules = [
            $this->buildRule(
                trigger: 'updated',
                condition: ['field' => 'status', 'operator' => 'equals', 'value' => 'failed'],
                groups: ['admin']
            ),
        ];

        $newData = ['id' => 6, 'uuid' => 'pqr', 'title' => 'T', 'status' => 'failed'];

        $this->notificationManager->expects($this->never())->method('notify');

        $count = $this->dispatcher->dispatch(
            entityType: 'configuration',
            trigger: 'updated',
            newData: $newData,
            oldData: null,  // old data unavailable → fail closed
            rules: $rules
        );

        $this->assertSame(0, $count);
    }

    // --- No condition: fires on every trigger ---

    public function testNullConditionFiresOnEveryUpdate(): void
    {
        $rules = [$this->buildRule(trigger: 'updated', condition: null, groups: ['admin'])];

        $newData = ['id' => 7, 'uuid' => 'stu', 'title' => 'T'];

        $adminUser = $this->createUserMock('u2');
        $adminGroup = $this->createGroupMock('admin', [$adminUser]);
        $this->groupManager->method('get')->willReturnMap([['admin', $adminGroup]]);

        $notification = $this->createNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        // null oldData is fine when condition is null.
        $count = $this->dispatcher->dispatch(
            entityType: 'schema',
            trigger: 'updated',
            newData: $newData,
            oldData: null,
            rules: $rules
        );

        $this->assertSame(1, $count);
    }

    // --- Trigger mismatch: rule does not fire ---

    public function testRuleDoesNotFireOnTriggerMismatch(): void
    {
        $rules = [$this->buildRule(trigger: 'created', condition: null, groups: ['admin'])];

        $newData = ['id' => 8, 'uuid' => 'vwx', 'title' => 'T'];

        $this->notificationManager->expects($this->never())->method('notify');

        $count = $this->dispatcher->dispatch(
            entityType: 'schema',
            trigger: 'updated',  // rule says 'created', so should not fire
            newData: $newData,
            oldData: null,
            rules: $rules
        );

        $this->assertSame(0, $count);
    }

    // --- Task 5.4: Stored-object notification behaviour is unchanged ---

    public function testStoredObjectRulePathUnaffected(): void
    {
        // Dispatcher with empty rules → zero notifications, no errors.
        $count = $this->dispatcher->dispatch(
            entityType: 'some_object_schema',
            trigger: 'updated',
            newData: ['id' => 9, 'title' => 'Test object'],
            oldData: ['id' => 9, 'title' => 'Old object'],
            rules: []
        );

        $this->assertSame(0, $count);
    }

    // --- Missing group is skipped ---

    public function testMissingGroupIsSkipped(): void
    {
        $rules = [$this->buildRule(trigger: 'updated', condition: null, groups: ['nonexistent'])];

        $newData = ['id' => 10, 'uuid' => 'yyy', 'title' => 'T'];

        $this->groupManager->method('get')->willReturn(null);
        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->notificationManager->expects($this->never())->method('notify');

        $count = $this->dispatcher->dispatch(
            entityType: 'schema',
            trigger: 'updated',
            newData: $newData,
            oldData: null,
            rules: $rules
        );

        $this->assertSame(0, $count);
    }

    // --- Equals with 'from' constraint ---

    public function testEqualsWithFromConstraintRequiresOldValueMatch(): void
    {
        $rules = [
            $this->buildRule(
                trigger: 'updated',
                condition: ['field' => 'status', 'operator' => 'equals', 'value' => 'closed', 'from' => 'open'],
                groups: ['admin']
            ),
        ];

        $adminUser = $this->createUserMock('u3');
        $adminGroup = $this->createGroupMock('admin', [$adminUser]);
        $this->groupManager->method('get')->willReturnMap([['admin', $adminGroup]]);

        $notification = $this->createNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);

        // Correct old value → fires.
        $this->notificationManager->expects($this->once())->method('notify');
        $this->dispatcher->dispatch(
            entityType: 'schema',
            trigger: 'updated',
            newData: ['id' => 11, 'title' => 'T', 'status' => 'closed'],
            oldData: ['id' => 11, 'title' => 'T', 'status' => 'open'],
            rules: $rules
        );
    }

    public function testEqualsWithFromConstraintDoesNotFireWhenOldValueMismatch(): void
    {
        $rules = [
            $this->buildRule(
                trigger: 'updated',
                condition: ['field' => 'status', 'operator' => 'equals', 'value' => 'closed', 'from' => 'open'],
                groups: ['admin']
            ),
        ];

        $this->notificationManager->expects($this->never())->method('notify');

        // Old value is 'pending', not 'open' → should not fire.
        $this->dispatcher->dispatch(
            entityType: 'schema',
            trigger: 'updated',
            newData: ['id' => 12, 'title' => 'T', 'status' => 'closed'],
            oldData: ['id' => 12, 'title' => 'T', 'status' => 'pending'],
            rules: $rules
        );
    }
}
