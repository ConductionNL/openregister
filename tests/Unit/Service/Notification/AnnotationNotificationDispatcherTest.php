<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\NotificationDispatchLogMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\RecipientResolverInterface;
use OCP\Activity\IEvent as IActivityEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IServerContainer;
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

    private IServerContainer&MockObject $serverContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userManager     = $this->createMock(IUserManager::class);
        $this->mailer          = $this->createMock(IMailer::class);
        $this->activityManager = $this->createMock(IActivityManager::class);
        $this->httpClient      = $this->createMock(IClientService::class);
        $this->serverContainer = $this->createMock(IServerContainer::class);

        // F05 added a `userExists` guard to every recipient-uid path.
        // PHPUnit's default `bool` return on an unstubbed mock is `false`,
        // which would silently drop every recipient and break every emit
        // assertion below. Stub a callback that returns true for any uid
        // EXCEPT the literal `ghost-uid` sentinel, so the negative path
        // (testFieldRecipientDroppedWhenUidNotFound) can exercise the
        // drop branch without rebuilding the user-manager mock.
        $this->userManager->method('userExists')->willReturnCallback(
            static fn(string $uid): bool => $uid !== 'ghost-uid'
        );
    }//end setUp()

    public function testInlinePostSkippedWhenWebhookPersistent(): void
    {
        $schema = $this->schemaWithNotification(
                [
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
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        // The persistent path goes through the WebhookService pipeline,
        // so the dispatcher must NOT instantiate an HTTP client.
        $this->httpClient->expects($this->never())->method('newClient');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }//end testInlinePostSkippedWhenWebhookPersistent()

    public function testTalkChannelPostsOnceWithToken(): void
    {
        $schema = $this->schemaWithNotification(
                [
                    'talkOnly' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['talk'],
                        'talk'       => ['token' => 'abc123'],
                        'recipients' => [['kind' => 'users', 'users' => ['admin', 'bob']]],
                        'subject'    => 'hello {{title}}',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $captured = null;
        $client   = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->willReturnCallback(
                    function (string $url, array $opts) use (&$captured) {
                        $captured = ['url' => $url, 'opts' => $opts];
                        return $this->createMock(\OCP\Http\Client\IResponse::class);
                    }
                    );

        // Talk is one-shot per dispatch — even with two recipients, exactly
        // one HTTP client gets created (the chat post itself).
        $this->httpClient->expects($this->once())->method('newClient')->willReturn($client);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');

        $this->assertStringContainsString('/ocs/v2.php/apps/spreed/api/v1/chat/abc123', $captured['url']);
        $this->assertSame('hello demo', $captured['opts']['body']['message']);
        $this->assertSame('bots', $captured['opts']['body']['actorType']);
    }//end testTalkChannelPostsOnceWithToken()

    public function testTalkChannelSilentWhenTokenMissing(): void
    {
        $schema = $this->schemaWithNotification(
                [
                    'talkNoToken' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['talk'],
                // No `talk` block at all.
                        'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                        'subject'    => 'hi',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        // Missing token should fail silent — no HTTP traffic, no exception.
        $this->httpClient->expects($this->never())->method('newClient');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }//end testTalkChannelSilentWhenTokenMissing()

    public function testObjectAclManageReturnsOwnerOnly(): void
    {
        $schema = $this->schemaWithNotification(
                [
                    'aclManage' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['nc-notification'],
                        'recipients' => [['kind' => 'object-acl', 'permission' => 'manage']],
                        'subject'    => 'managers ping',
                    ],
                ]
                );
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
    }//end testObjectAclManageReturnsOwnerOnly()

    public function testObjectAclReadIncludesOwnerAndGroupMembers(): void
    {
        $schema = $this->schemaWithNotification(
                [
                    'aclRead' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['nc-notification'],
                        'recipients' => [['kind' => 'object-acl', 'permission' => 'read']],
                        'subject'    => 'readers ping',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $object = $this->object($schema);
        $object->setOwner('alice');
        $object->setGroups(['readers']);

        $bob = $this->createMock(IUser::class);
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
    }//end testObjectAclReadIncludesOwnerAndGroupMembers()

    public function testExpressionResolverReceivesObjectAndContext(): void
    {
        // Register a resolver in the OC server container so the dispatcher
        // can look it up by tag.
        $resolver = new class implements RecipientResolverInterface {

            public ?ObjectEntity $sawObject = null;

            public array $sawContext = [];

            public function resolve(ObjectEntity $object, array $context): array
            {
                $this->sawObject  = $object;
                $this->sawContext = $context;
                return ['eve', 'frank'];
            }//end resolve()
        };
        $tag      = 'OCA\\Test\\DummyResolver_'.bin2hex(random_bytes(4));
        \OC::$server->registerService($tag, fn() => $resolver);

        $schema = $this->schemaWithNotification(
                [
                    'expr' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['nc-notification'],
                        'recipients' => [['kind' => 'expression', 'resolver' => $tag]],
                        'subject'    => 'expression ping',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $object = $this->object($schema);

        $delivered = [];
        $this->expectNotificationManagerCalls($delivered);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated', ['action' => 'close']);

        $this->assertSame(['eve', 'frank'], $delivered);
        $this->assertSame($object->getUuid(), $resolver->sawObject?->getUuid());
        $this->assertSame('close', $resolver->sawContext['action'] ?? null);
    }//end testExpressionResolverReceivesObjectAndContext()

    public function testExpressionResolverFailsClosedOnInterfaceMismatch(): void
    {
        $tag = 'OCA\\Test\\BadResolver_'.bin2hex(random_bytes(4));
        \OC::$server->registerService($tag, fn() => new \stdClass());

        $schema = $this->schemaWithNotification(
                [
                    'badExpr' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['nc-notification'],
                        'recipients' => [['kind' => 'expression', 'resolver' => $tag]],
                        'subject'    => 'should never deliver',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        // No INotification should be queued because there are no recipients.
        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }//end testExpressionResolverFailsClosedOnInterfaceMismatch()

    public function testExpressionResolverFailsClosedOnMissingService(): void
    {
        $schema = $this->schemaWithNotification(
                [
                    'missingExpr' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['nc-notification'],
                        'recipients' => [['kind' => 'expression', 'resolver' => 'OCA\\Test\\NotRegistered']],
                        'subject'    => 'should never deliver',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }//end testExpressionResolverFailsClosedOnMissingService()

    public function testInlinePostFiresWhenWebhookNotPersistent(): void
    {
        $schema = $this->schemaWithNotification(
                [
                    'inline-hook' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['webhook'],
                        'webhook'    => [
                            'url' => 'https://example.com/hooks/x',
                        ],
                        'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                        'subject'    => 'inline',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('request');
        $this->httpClient->expects($this->once())->method('newClient')->willReturn($client);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');
    }//end testInlinePostFiresWhenWebhookNotPersistent()

    // ====================================================================
    // NL/EN i18n — Open spec item:
    // "Notification messages MUST support i18n in Dutch and English."
    // ====================================================================
    public function testPerLocaleSubjectRendersDutchForDutchUser(): void
    {
        $schema = $this->schemaWithNotification(
            [
                'localized' => [
                    'trigger'    => ['type' => 'updated'],
                    'channels'   => ['nc-notification'],
                    'recipients' => [['kind' => 'users', 'users' => ['nl-user']]],
                    'subject'    => [
                        'nl' => 'Object {{title}} bijgewerkt',
                        'en' => 'Object {{title}} updated',
                    ],
                ],
            ]
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')
            ->willReturnMap(
                [
                    ['nl-user', 'core', 'lang', '', 'nl_NL'],
                ]
            );

        $captured = [];
        $this->captureNotificationSubjects($captured);

        $dispatcher = $this->makeDispatcher(config: $config);
        $dispatcher->dispatch($this->object($schema), 'updated');

        $this->assertCount(1, $captured);
        $this->assertSame('Object demo bijgewerkt', $captured[0]['_text']);
    }//end testPerLocaleSubjectRendersDutchForDutchUser()

    public function testPerLocaleSubjectRendersEnglishForEnglishUser(): void
    {
        $schema = $this->schemaWithNotification(
            [
                'localized' => [
                    'trigger'    => ['type' => 'updated'],
                    'channels'   => ['nc-notification'],
                    'recipients' => [['kind' => 'users', 'users' => ['en-user']]],
                    'subject'    => [
                        'nl' => 'Object {{title}} bijgewerkt',
                        'en' => 'Object {{title}} updated',
                    ],
                ],
            ]
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')
            ->willReturnMap(
                [
                    ['en-user', 'core', 'lang', '', 'en'],
                ]
            );

        $captured = [];
        $this->captureNotificationSubjects($captured);

        $dispatcher = $this->makeDispatcher(config: $config);
        $dispatcher->dispatch($this->object($schema), 'updated');

        $this->assertSame('Object demo updated', $captured[0]['_text']);
    }//end testPerLocaleSubjectRendersEnglishForEnglishUser()

    public function testPerLocaleSubjectFallsBackToDefaultLocaleWhenUserHasNoPreference(): void
    {
        // User has no `core.lang` set → fall back to defaultLocale, not
        // the first declared locale.
        $schema = $this->schemaWithNotification(
            [
                'localized' => [
                    'trigger'    => ['type' => 'updated'],
                    'channels'   => ['nc-notification'],
                    'recipients' => [['kind' => 'users', 'users' => ['unknown']]],
                    'subject'    => [
                        'defaultLocale' => 'en',
                        'nl'            => 'NL fallback',
                        'en'            => 'EN default',
                    ],
                ],
            ]
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');

        $captured = [];
        $this->captureNotificationSubjects($captured);

        $dispatcher = $this->makeDispatcher(config: $config);
        $dispatcher->dispatch($this->object($schema), 'updated');

        $this->assertSame('EN default', $captured[0]['_text']);
    }//end testPerLocaleSubjectFallsBackToDefaultLocaleWhenUserHasNoPreference()

    public function testLegacyStringSubjectStillRenders(): void
    {
        // Backwards compatibility: string subject still works without
        // any IConfig involvement.
        $schema = $this->schemaWithNotification(
            [
                'legacy' => [
                    'trigger'    => ['type' => 'updated'],
                    'channels'   => ['nc-notification'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'subject'    => 'plain {{title}}',
                ],
            ]
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $captured = [];
        $this->captureNotificationSubjects($captured);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');

        $this->assertSame('plain demo', $captured[0]['_text']);
    }//end testLegacyStringSubjectStillRenders()

    public function testWebhookBroadcastUsesDefaultLocaleNotRecipientLocale(): void
    {
        // Webhook is fired once per dispatch — recipients' locales
        // differ but the broadcast uses the spec's default.
        $schema = $this->schemaWithNotification(
            [
                'broadcast' => [
                    'trigger'    => ['type' => 'updated'],
                    'channels'   => ['webhook'],
                    'webhook'    => ['url' => 'https://example.com/h'],
                    'recipients' => [['kind' => 'users', 'users' => ['nl-user', 'en-user']]],
                    'subject'    => [
                        'defaultLocale' => 'en',
                        'nl'            => 'NL broadcast',
                        'en'            => 'EN broadcast',
                    ],
                ],
            ]
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $captured = null;
        $client   = $this->createMock(IClient::class);
        $client->method('request')
            ->willReturnCallback(
                    function (string $method, string $url, array $opts) use (&$captured) {
                        $captured = $opts;
                        return $this->createMock(\OCP\Http\Client\IResponse::class);
                    }
                    );
        $this->httpClient->method('newClient')->willReturn($client);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($this->object($schema), 'updated');

        $this->assertNotNull($captured);
        $payload = $captured['json'] ?? [];
        $this->assertIsArray($payload);
        $this->assertSame('EN broadcast', $payload['subject'] ?? null);
    }//end testWebhookBroadcastUsesDefaultLocaleNotRecipientLocale()

    public function testOrganisationGateBlocksWhenOrgsDoNotMatch(): void
    {
        // Rule pinned to org-A — object lives in org-B → no dispatch.
        $schema = $this->schemaWithNotification(
                [
                    'pinnedToA' => [
                        'trigger'      => ['type' => 'updated'],
                        'channels'     => ['nc-notification'],
                        'recipients'   => [['kind' => 'users', 'users' => ['admin']]],
                        'subject'      => 'pinned',
                        'organisation' => 'org-A',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        // No notify() call when the gate blocks.
        $this->notificationManager->expects($this->never())->method('createNotification');

        $object = $this->object($schema);
        $object->setOrganisation('org-B');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated');
    }//end testOrganisationGateBlocksWhenOrgsDoNotMatch()

    public function testOrganisationGateAllowsWhenOrgsMatch(): void
    {
        $schema = $this->schemaWithNotification(
                [
                    'pinnedToA' => [
                        'trigger'      => ['type' => 'updated'],
                        'channels'     => ['nc-notification'],
                        'recipients'   => [['kind' => 'users', 'users' => ['admin']]],
                        'subject'      => 'pinned',
                        'organisation' => 'org-A',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $delivered = [];
        $this->expectNotificationManagerCalls($delivered);
        $this->notificationManager->expects($this->once())->method('notify');

        $object = $this->object($schema);
        $object->setOrganisation('org-A');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated');

        $this->assertSame(['admin'], $delivered);
    }//end testOrganisationGateAllowsWhenOrgsMatch()

    public function testOrganisationGateAllowsWhenObjectOrgInDeclaredArray(): void
    {
        $schema = $this->schemaWithNotification(
                [
                    'pinnedToAB' => [
                        'trigger'      => ['type' => 'updated'],
                        'channels'     => ['nc-notification'],
                        'recipients'   => [['kind' => 'users', 'users' => ['admin']]],
                        'subject'      => 'pinned-multi',
                        'organisation' => ['org-A', 'org-B'],
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $delivered = [];
        $this->expectNotificationManagerCalls($delivered);
        $this->notificationManager->expects($this->once())->method('notify');

        $object = $this->object($schema);
        $object->setOrganisation('org-B');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated');

        $this->assertSame(['admin'], $delivered);
    }//end testOrganisationGateAllowsWhenObjectOrgInDeclaredArray()

    public function testOrganisationGateBlocksUntenantedObjectsByDefault(): void
    {
        // Object has no organisation set → org-pinned rules MUST NOT
        // fire (would otherwise leak cross-tenant for legacy un-tenanted
        // data). Closes the spec's "fail closed" intent.
        $schema = $this->schemaWithNotification(
                [
                    'pinnedToA' => [
                        'trigger'      => ['type' => 'updated'],
                        'channels'     => ['nc-notification'],
                        'recipients'   => [['kind' => 'users', 'users' => ['admin']]],
                        'subject'      => 'pinned',
                        'organisation' => 'org-A',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->notificationManager->expects($this->never())->method('createNotification');

        $object = $this->object($schema);
        // Note: no setOrganisation() call → null/empty.
        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated');
    }//end testOrganisationGateBlocksUntenantedObjectsByDefault()

    public function testNoOrganisationGateLeavesDispatchUnchanged(): void
    {
        // Without a `organisation` field on the rule, dispatch happens
        // for any object regardless of organisation — guarantees the
        // new field is fully opt-in and back-compat is preserved.
        $schema = $this->schemaWithNotification(
                [
                    'unpinned' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['nc-notification'],
                        'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                        'subject'    => 'global',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        $delivered = [];
        $this->expectNotificationManagerCalls($delivered);
        $this->notificationManager->expects($this->once())->method('notify');

        $object = $this->object($schema);
        $object->setOrganisation('any-org');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated');

        $this->assertSame(['admin'], $delivered);
    }//end testNoOrganisationGateLeavesDispatchUnchanged()

    // ====================================================================
    // Idempotency-key deduplication
    // "The notification engine MUST deduplicate dispatches by
    // (notification_slug, resolved_idempotency_key) over a configurable
    // window (default 24 h)."
    // ====================================================================

    public function testFirstDispatchWithIdempotencyKeySendsAndLogs(): void
    {
        // First dispatch: the log mapper reports no duplicate → notify()
        // fires and record() is called once.
        $schema = $this->schemaWithNotification(
            [
                'reminderT30' => [
                    'trigger'        => ['type' => 'updated'],
                    'channels'       => ['nc-notification'],
                    'recipients'     => [['kind' => 'users', 'users' => ['learner1']]],
                    'subject'        => 'Reminder T-30',
                    'idempotencyKey' => '${@self.uuid}-T30-${@self.dueDate}',
                ],
            ]
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $logMapper = $this->createMock(NotificationDispatchLogMapper::class);
        // No duplicate exists — first dispatch.
        $logMapper->method('isDuplicate')->willReturn(false);
        $logMapper->expects($this->once())->method('record');

        $this->notificationManager->expects($this->once())->method('notify');

        $object = $this->object($schema);
        $object->setObject(['title' => 'demo', 'dueDate' => '2026-06-01']);

        $dispatcher = $this->makeDispatcher(dispatchLogMapper: $logMapper);
        $dispatcher->dispatch($object, 'updated');
    }//end testFirstDispatchWithIdempotencyKeySendsAndLogs()

    public function testSecondDispatchWithSameKeyIsSkipped(): void
    {
        // Second dispatch: the log mapper reports a duplicate within the
        // window → notify() must never fire and record() is NOT called again.
        $schema = $this->schemaWithNotification(
            [
                'reminderT30' => [
                    'trigger'        => ['type' => 'updated'],
                    'channels'       => ['nc-notification'],
                    'recipients'     => [['kind' => 'users', 'users' => ['learner1']]],
                    'subject'        => 'Reminder T-30',
                    'idempotencyKey' => '${@self.uuid}-T30-${@self.dueDate}',
                ],
            ]
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $logMapper = $this->createMock(NotificationDispatchLogMapper::class);
        // Duplicate exists — second dispatch should be a no-op.
        $logMapper->method('isDuplicate')->willReturn(true);
        $logMapper->expects($this->never())->method('record');

        $this->notificationManager->expects($this->never())->method('notify');
        $this->logger->expects($this->atLeastOnce())->method('info');

        $object = $this->object($schema);
        $object->setObject(['title' => 'demo', 'dueDate' => '2026-06-01']);

        $dispatcher = $this->makeDispatcher(dispatchLogMapper: $logMapper);
        $dispatcher->dispatch($object, 'updated');
    }//end testSecondDispatchWithSameKeyIsSkipped()

    public function testDispatchWithoutIdempotencyKeyNeverConsultsLog(): void
    {
        // Rules without an idempotencyKey must never touch the dispatch log.
        $schema = $this->schemaWithNotification(
            [
                'noKey' => [
                    'trigger'    => ['type' => 'updated'],
                    'channels'   => ['nc-notification'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'subject'    => 'no key rule',
                ],
            ]
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $logMapper = $this->createMock(NotificationDispatchLogMapper::class);
        $logMapper->expects($this->never())->method('isDuplicate');
        $logMapper->expects($this->never())->method('record');

        $this->notificationManager->expects($this->once())->method('notify');

        $dispatcher = $this->makeDispatcher(dispatchLogMapper: $logMapper);
        $dispatcher->dispatch($this->object($schema), 'updated');
    }//end testDispatchWithoutIdempotencyKeyNeverConsultsLog()

    public function testFieldRecipientDroppedWhenUidDoesNotExist(): void
    {
        // F05 contract: when a `field` recipient resolves to a uid that
        // IUserManager::userExists() rejects (typo, deleted user,
        // attacker-supplied admin uid via writable object data), the
        // recipient is dropped *before* any emit fires. This pins the
        // F05 guard against accidental regressions and gives the F05
        // change explicit negative-path coverage.
        $schema = $this->schemaWithNotification(
                [
                    'fieldRule' => [
                        'trigger'    => ['type' => 'updated'],
                        'channels'   => ['nc-notification'],
                        'recipients' => [['kind' => 'field', 'field' => 'assignee']],
                        'subject'    => 'ping {{title}}',
                    ],
                ]
                );
        $this->schemaMapper->method('find')->willReturn($schema);

        // The object's `assignee` field carries the sentinel uid that
        // setUp() stubs to NOT exist. Without F05, the dispatcher would
        // emit to that uid; with F05, the recipient list ends up empty
        // and notify() never fires.
        $object = $this->object($schema);
        $object->setObject(['title' => 'demo', 'assignee' => 'ghost-uid']);

        $this->notificationManager->expects($this->never())->method('notify');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($object, 'updated');
    }//end testFieldRecipientDroppedWhenUidDoesNotExist()

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
    }//end schemaWithNotification()

    private function object(Schema $schema): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('uuid-1');
        $object->setSchema((string) $schema->getSlug());
        $object->setRegister('r');
        $object->setObject(['title' => 'demo']);
        return $object;
    }//end object()

    private function makeDispatcher(
        ?IConfig $config=null,
        ?NotificationDispatchLogMapper $dispatchLogMapper=null
    ): AnnotationNotificationDispatcher {
        return new AnnotationNotificationDispatcher(
            $this->schemaMapper,
            $this->notificationManager,
            $this->logger,
            $this->groupManager,
            $this->userManager,
            $this->mailer,
            $this->activityManager,
            $this->httpClient,
            $this->serverContainer,
            null,
            $config,
            null,
            null,
            null,
            $dispatchLogMapper
        );
    }//end makeDispatcher()

    /**
     * Capture the `setSubject` `_text` parameter for each notification
     * fired through INotificationManager. Lets tests assert the
     * recipient-specific subject without traversing the verbose
     * INotification builder.
     *
     * @param array<int, array<string, mixed>> $captured Out-param.
     */
    private function captureNotificationSubjects(array &$captured): void
    {
        $this->notificationManager->method('createNotification')
            ->willReturnCallback(
                    function () use (&$captured) {
                        $notif = $this->createMock(INotification::class);
                        $notif->method('setApp')->willReturnSelf();
                        $notif->method('setUser')->willReturnSelf();
                        $notif->method('setDateTime')->willReturnSelf();
                        $notif->method('setObject')->willReturnSelf();
                        $notif->method('setSubject')->willReturnCallback(
                        function (string $name, array $params) use ($notif, &$captured) {
                            $captured[] = $params;
                            return $notif;
                        }
                        );
                        $notif->method('setMessage')->willReturnSelf();
                        return $notif;
                    }
                    );
    }//end captureNotificationSubjects()

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
            ->willReturnCallback(
                    function () use (&$delivered) {
                        $notif = $this->createMock(INotification::class);
                        $notif->method('setApp')->willReturnSelf();
                        $notif->method('setUser')->willReturnCallback(
                        function (string $uid) use ($notif, &$delivered) {
                            $delivered[] = $uid;
                            return $notif;
                        }
                        );
                        $notif->method('setDateTime')->willReturnSelf();
                        $notif->method('setObject')->willReturnSelf();
                        $notif->method('setSubject')->willReturnSelf();
                        $notif->method('setMessage')->willReturnSelf();
                        return $notif;
                    }
                    );
    }//end expectNotificationManagerCalls()
}//end class
