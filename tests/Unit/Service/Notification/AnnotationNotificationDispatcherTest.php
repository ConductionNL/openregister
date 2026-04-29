<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\RecipientResolverInterface;
use OCP\Activity\IEvent as IActivityEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the dispatcher's persistent-webhook escape: when
 * webhook.persistent is true, the inline POST is skipped because
 * a Webhook entity (created by NotificationsAnnotationInstaller)
 * is delivering through the standard pipeline. Without this skip,
 * each event would double-fire.
 */
class AnnotationNotificationDispatcherTest extends TestCase
{
    private SchemaMapper&MockObject $schemaMapper;
    private INotificationManager&MockObject $notificationManager;
    private LoggerInterface&MockObject $logger;
    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private IMailer&MockObject $mailer;
    private IActivityManager&MockObject $activityManager;
    private IClientService&MockObject $httpClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->groupManager        = $this->createMock(IGroupManager::class);
        $this->userManager         = $this->createMock(IUserManager::class);
        $this->mailer              = $this->createMock(IMailer::class);
        $this->activityManager     = $this->createMock(IActivityManager::class);
        $this->httpClient          = $this->createMock(IClientService::class);
    }

    public function testInlinePostSkippedWhenWebhookPersistent(): void
    {
        $schema = $this->schemaWithNotification([
            'persistent-hook' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['webhook'],
                'webhook'    => [
                    'persistent' => true,
                    'url'        => 'https://example.com/hooks/x',
                ],
                'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                'subject'    => 'persistent only',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        // The persistent path goes through the WebhookService pipeline,
        // so the dispatcher must NOT instantiate an HTTP client.
        $this->httpClient->expects($this->never())->method('newClient');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }

    public function testTalkChannelPostsOnceWithToken(): void
    {
        $schema = $this->schemaWithNotification([
            'talkOnly' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['talk'],
                'talk'       => ['token' => 'abc123'],
                'recipients' => [['kind' => 'users', 'users' => ['admin', 'bob']]],
                'subject'    => 'hello {{title}}',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $captured = null;
        $client   = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, array $opts) use (&$captured) {
                $captured = ['url' => $url, 'opts' => $opts];
                return $this->createMock(\OCP\Http\Client\IResponse::class);
            });

        // Talk is one-shot per dispatch — even with two recipients, exactly
        // one HTTP client gets created (the chat post itself).
        $this->httpClient->expects($this->once())->method('newClient')->willReturn($client);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');

        $this->assertStringContainsString('/ocs/v2.php/apps/spreed/api/v1/chat/abc123', $captured['url']);
        $this->assertSame('hello demo', $captured['opts']['body']['message']);
        $this->assertSame('bots', $captured['opts']['body']['actorType']);
    }

    public function testTalkChannelSilentWhenTokenMissing(): void
    {
        $schema = $this->schemaWithNotification([
            'talkNoToken' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['talk'],
                // No `talk` block at all.
                'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                'subject'    => 'hi',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        // Missing token should fail silent — no HTTP traffic, no exception.
        $this->httpClient->expects($this->never())->method('newClient');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }

    public function testObjectAclManageReturnsOwnerOnly(): void
    {
        $schema = $this->schemaWithNotification([
            'aclManage' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['nc-notification'],
                'recipients' => [['kind' => 'object-acl', 'permission' => 'manage']],
                'subject'    => 'managers ping',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $object = $this->object($schema);
        $object->setOwner('alice');
        $object->setGroups(['readers']);

        // Manage permission must NOT include groups — read-only would
        // get pinged on every change otherwise.
        $this->groupManager->expects($this->never())->method('get');

        $delivered = [];
        $this->expectNotificationManagerCalls($delivered);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated');

        $this->assertSame(['alice'], $delivered);
    }

    public function testObjectAclReadIncludesOwnerAndGroupMembers(): void
    {
        $schema = $this->schemaWithNotification([
            'aclRead' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['nc-notification'],
                'recipients' => [['kind' => 'object-acl', 'permission' => 'read']],
                'subject'    => 'readers ping',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $object = $this->object($schema);
        $object->setOwner('alice');
        $object->setGroups(['readers']);

        $bob   = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $carol = $this->createMock(IUser::class);
        $carol->method('getUID')->willReturn('carol');

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$bob, $carol]);
        $this->groupManager->method('get')->with('readers')->willReturn($group);

        $delivered = [];
        $this->expectNotificationManagerCalls($delivered);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated');

        $this->assertEqualsCanonicalizing(['alice', 'bob', 'carol'], $delivered);
    }

    public function testExpressionResolverReceivesObjectAndContext(): void
    {
        // Register a resolver in the OC server container so the dispatcher
        // can look it up by tag.
        $resolver = new class implements RecipientResolverInterface {
            public ?ObjectEntity $sawObject = null;
            public array $sawContext        = [];

            public function resolve(ObjectEntity $object, array $context): array
            {
                $this->sawObject  = $object;
                $this->sawContext = $context;
                return ['eve', 'frank'];
            }
        };
        $tag = 'OCA\\Test\\DummyResolver_' . bin2hex(random_bytes(4));
        \OC::$server->registerService($tag, fn() => $resolver);

        $schema = $this->schemaWithNotification([
            'expr' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['nc-notification'],
                'recipients' => [['kind' => 'expression', 'resolver' => $tag]],
                'subject'    => 'expression ping',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $object = $this->object($schema);

        $delivered = [];
        $this->expectNotificationManagerCalls($delivered);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated', ['action' => 'close']);

        $this->assertSame(['eve', 'frank'], $delivered);
        $this->assertSame($object->getUuid(), $resolver->sawObject?->getUuid());
        $this->assertSame('close', $resolver->sawContext['action'] ?? null);
    }

    public function testExpressionResolverFailsClosedOnInterfaceMismatch(): void
    {
        $tag = 'OCA\\Test\\BadResolver_' . bin2hex(random_bytes(4));
        \OC::$server->registerService($tag, fn() => new \stdClass());

        $schema = $this->schemaWithNotification([
            'badExpr' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['nc-notification'],
                'recipients' => [['kind' => 'expression', 'resolver' => $tag]],
                'subject'    => 'should never deliver',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        // No INotification should be queued because there are no recipients.
        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }

    public function testExpressionResolverFailsClosedOnMissingService(): void
    {
        $schema = $this->schemaWithNotification([
            'missingExpr' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['nc-notification'],
                'recipients' => [['kind' => 'expression', 'resolver' => 'OCA\\Test\\NotRegistered']],
                'subject'    => 'should never deliver',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }

    public function testInlinePostFiresWhenWebhookNotPersistent(): void
    {
        $schema = $this->schemaWithNotification([
            'inline-hook' => [
                'trigger'    => ['type' => 'updated'],
                'channels'   => ['webhook'],
                'webhook'    => [
                    'url' => 'https://example.com/hooks/x',
                ],
                'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                'subject'    => 'inline',
            ],
        ]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('request');
        $this->httpClient->expects($this->once())->method('newClient')->willReturn($client);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }

    /**
     * @param array<string, mixed> $notifications
     */
    private function schemaWithNotification(array $notifications): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug('s');
        $schema->setConfiguration(['x-openregister-notifications' => $notifications]);
        return $schema;
    }

    private function object(Schema $schema): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('uuid-1');
        $object->setSchema((string) $schema->getSlug());
        $object->setRegister('r');
        $object->setObject(['title' => 'demo']);
        return $object;
    }

    private function makeDispatcher(): AnnotationNotificationDispatcher
    {
        return new AnnotationNotificationDispatcher(
            $this->schemaMapper,
            $this->notificationManager,
            $this->logger,
            $this->groupManager,
            $this->userManager,
            $this->mailer,
            $this->activityManager,
            $this->httpClient
        );
    }

    /**
     * Stub INotificationManager so each notify() call appends the recipient
     * uid to $delivered. Lets tests assert the full set of resolved uids
     * without caring about INotification's verbose builder API.
     *
     * @param array<int, string> $delivered Out-param.
     */
    private function expectNotificationManagerCalls(array &$delivered): void
    {
        $this->notificationManager->method('createNotification')
            ->willReturnCallback(function () use (&$delivered) {
                $notif = $this->createMock(INotification::class);
                $notif->method('setApp')->willReturnSelf();
                $notif->method('setUser')->willReturnCallback(function (string $uid) use ($notif, &$delivered) {
                    $delivered[] = $uid;
                    return $notif;
                });
                $notif->method('setDateTime')->willReturnSelf();
                $notif->method('setObject')->willReturnSelf();
                $notif->method('setSubject')->willReturnSelf();
                $notif->method('setMessage')->willReturnSelf();
                return $notif;
            });
    }
}
