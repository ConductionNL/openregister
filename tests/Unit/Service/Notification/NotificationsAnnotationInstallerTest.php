<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies that schema-save events flow into Webhook entity creation
 * for `webhook.persistent: true` notifications, and that re-saving a
 * schema with the same notification name updates the existing entity
 * rather than creating duplicates.
 *
 * If this fails, persistent webhooks would either never reach the
 * standard delivery pipeline (notification silently lost) or duplicate
 * on every schema save (delivery storm).
 */
class NotificationsAnnotationInstallerTest extends TestCase
{
    private WebhookMapper&MockObject $webhookMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookMapper = $this->createMock(WebhookMapper::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
    }

    public function testCreatesWebhookForPersistentNotification(): void
    {
        $schema = $this->schema('meeting', [
            'meetingClosed' => [
                'channels' => ['webhook'],
                'webhook'  => [
                    'persistent' => true,
                    'url'        => 'https://example.com/hooks/x',
                    'method'     => 'POST',
                    'events'     => ['ObjectTransitionedEvent'],
                    'headers'    => ['X-Source' => 'or'],
                    'secret'     => 'topsecret',
                ],
            ],
        ]);

        // No existing webhook → installer goes through the create path.
        $this->webhookMapper->method('findAll')->willReturn([]);

        $captured = null;
        $this->webhookMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $payload) use (&$captured) {
                $captured = $payload;
                return new Webhook();
            });
        $this->webhookMapper->expects($this->never())->method('updateFromArray');

        (new NotificationsAnnotationInstaller($this->webhookMapper, $this->logger))
            ->installSchema($schema);

        $this->assertSame('or-notif-meeting-meetingClosed', $captured['name']);
        $this->assertSame('https://example.com/hooks/x', $captured['url']);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('topsecret', $captured['secret']);
        $this->assertTrue($captured['enabled']);
        $this->assertSame('exponential', $captured['retryPolicy']);
        $this->assertSame(5, $captured['maxRetries']);
        $this->assertSame(json_encode(['ObjectTransitionedEvent']), $captured['events']);
        $this->assertSame(json_encode(['X-Source' => 'or']), $captured['headers']);
        $this->assertNotEmpty($captured['uuid']);
    }

    public function testUpdatesExistingWebhookByName(): void
    {
        $existing = new Webhook();
        $existing->setId(42);
        $existing->setName('or-notif-meeting-meetingClosed');

        $schema = $this->schema('meeting', [
            'meetingClosed' => [
                'channels' => ['webhook'],
                'webhook'  => [
                    'persistent' => true,
                    'url'        => 'https://example.com/hooks/v2',
                    'events'     => ['ObjectTransitionedEvent'],
                ],
            ],
        ]);

        $this->webhookMapper->method('findAll')->willReturn([$existing]);

        $captured = null;
        $this->webhookMapper->expects($this->never())->method('createFromArray');
        $this->webhookMapper->expects($this->once())
            ->method('updateFromArray')
            ->willReturnCallback(function (int $id, array $payload) use (&$captured) {
                $captured = ['id' => $id, 'payload' => $payload];
                return new Webhook();
            });

        (new NotificationsAnnotationInstaller($this->webhookMapper, $this->logger))
            ->installSchema($schema);

        $this->assertSame(42, $captured['id']);
        $this->assertSame('https://example.com/hooks/v2', $captured['payload']['url']);
        // No fresh UUID on update — existing entity keeps its identity.
        $this->assertArrayNotHasKey('uuid', $captured['payload']);
    }

    public function testSkipsWhenWebhookChannelNotDeclared(): void
    {
        $schema = $this->schema('meeting', [
            'inApp' => [
                'channels' => ['nc-notification'],
                // no `webhook` block at all
            ],
        ]);

        $this->webhookMapper->expects($this->never())->method('createFromArray');
        $this->webhookMapper->expects($this->never())->method('updateFromArray');

        (new NotificationsAnnotationInstaller($this->webhookMapper, $this->logger))
            ->installSchema($schema);
    }

    public function testSkipsWhenWebhookNotPersistent(): void
    {
        // Inline-fire webhooks (persistent: false / missing) must NOT be
        // turned into managed Webhook entities — they're delivered by
        // the dispatcher's direct POST path.
        $schema = $this->schema('meeting', [
            'inlineHook' => [
                'channels' => ['webhook'],
                'webhook'  => [
                    'url' => 'https://example.com/hooks/x',
                ],
            ],
        ]);

        $this->webhookMapper->expects($this->never())->method('createFromArray');
        $this->webhookMapper->expects($this->never())->method('updateFromArray');

        (new NotificationsAnnotationInstaller($this->webhookMapper, $this->logger))
            ->installSchema($schema);
    }

    public function testSkipsWhenUrlMissingOrInvalid(): void
    {
        $schema = $this->schema('meeting', [
            'badUrl' => [
                'channels' => ['webhook'],
                'webhook'  => ['persistent' => true, 'url' => 'not-a-url'],
            ],
            'noUrl' => [
                'channels' => ['webhook'],
                'webhook'  => ['persistent' => true],
            ],
        ]);

        $this->webhookMapper->expects($this->never())->method('createFromArray');

        (new NotificationsAnnotationInstaller($this->webhookMapper, $this->logger))
            ->installSchema($schema);
    }

    public function testIdempotentOnRepeatedInstall(): void
    {
        // After the first install, subsequent installSchema() calls must
        // route through update, not create — even though the in-memory
        // mapper mock doesn't actually persist across calls, the second
        // call's findAll would return the entity created in the first.
        $schema = $this->schema('meeting', [
            'meetingClosed' => [
                'channels' => ['webhook'],
                'webhook'  => [
                    'persistent' => true,
                    'url'        => 'https://example.com/hooks/x',
                    'events'     => ['ObjectTransitionedEvent'],
                ],
            ],
        ]);

        $existing = new Webhook();
        $existing->setId(1);
        $existing->setName('or-notif-meeting-meetingClosed');

        $this->webhookMapper->method('findAll')->willReturnOnConsecutiveCalls(
            [],         // first install: nothing
            [$existing] // second install: the entity from the first
        );

        $this->webhookMapper->expects($this->once())->method('createFromArray')->willReturn(new Webhook());
        $this->webhookMapper->expects($this->once())->method('updateFromArray')->willReturn(new Webhook());

        $installer = new NotificationsAnnotationInstaller($this->webhookMapper, $this->logger);
        $installer->installSchema($schema);
        $installer->installSchema($schema);
    }

    public function testFiltersOutFalsePositivesInFindByName(): void
    {
        // findAll's `name` filter is best-effort (substring/sql-fuzzy in
        // some implementations); the installer must require an exact
        // name match before treating an entity as "existing".
        $other = new Webhook();
        $other->setId(99);
        $other->setName('or-notif-meeting-someOtherNotif');

        $schema = $this->schema('meeting', [
            'meetingClosed' => [
                'channels' => ['webhook'],
                'webhook'  => [
                    'persistent' => true,
                    'url'        => 'https://example.com/hooks/x',
                    'events'     => ['ObjectTransitionedEvent'],
                ],
            ],
        ]);

        $this->webhookMapper->method('findAll')->willReturn([$other]);
        // Should treat as not-found and create a new one, not update $other.
        $this->webhookMapper->expects($this->once())->method('createFromArray')->willReturn(new Webhook());
        $this->webhookMapper->expects($this->never())->method('updateFromArray');

        (new NotificationsAnnotationInstaller($this->webhookMapper, $this->logger))
            ->installSchema($schema);
    }

    public function testSwallowsCreateFailure(): void
    {
        $schema = $this->schema('meeting', [
            'meetingClosed' => [
                'channels' => ['webhook'],
                'webhook'  => [
                    'persistent' => true,
                    'url'        => 'https://example.com/hooks/x',
                ],
            ],
        ]);

        $this->webhookMapper->method('findAll')->willReturn([]);
        $this->webhookMapper->method('createFromArray')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('warning');

        // Must not bubble — schema save shouldn't fail because of webhook
        // provisioning errors. The warning log is the audit trail.
        (new NotificationsAnnotationInstaller($this->webhookMapper, $this->logger))
            ->installSchema($schema);
    }

    /**
     * @param array<string, mixed> $notifications
     */
    private function schema(string $slug, array $notifications): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug($slug);
        $schema->setConfiguration(['x-openregister-notifications' => $notifications]);
        return $schema;
    }
}
