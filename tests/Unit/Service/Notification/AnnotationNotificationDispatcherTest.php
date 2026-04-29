<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
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
}
