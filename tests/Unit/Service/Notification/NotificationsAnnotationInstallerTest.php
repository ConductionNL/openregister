<?php

/**
 * NotificationsAnnotationInstallerTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for NotificationsAnnotationInstaller.
 */
class NotificationsAnnotationInstallerTest extends TestCase
{

    private WebhookMapper&MockObject $webhookMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->webhookMapper = $this->createMock(WebhookMapper::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
    }

    private function makeInstaller(): NotificationsAnnotationInstaller
    {
        return new NotificationsAnnotationInstaller(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger
        );
    }

    /**
     * Empty configuration returns empty array.
     */
    public function testEmptyConfigurationReturnsEmpty(): void
    {
        $installer = $this->makeInstaller();
        self::assertEmpty($installer->install(schemaId: 'schema-1', configuration: []));
    }

    /**
     * Rule without webhook channel is skipped.
     */
    public function testRuleWithoutWebhookChannelSkipped(): void
    {
        $config = [
            'x-openregister-notifications' => [
                ['trigger' => 'created', 'channels' => ['nc-notification'], 'subject' => 'Created'],
            ],
        ];
        $this->webhookMapper->expects(self::never())->method('insert');

        $installer = $this->makeInstaller();
        self::assertEmpty($installer->install(schemaId: 'schema-1', configuration: $config));
    }

    /**
     * Rule with webhook channel but persistent = false is skipped.
     */
    public function testRuleWithNonPersistentWebhookSkipped(): void
    {
        $config = [
            'x-openregister-notifications' => [
                [
                    'trigger'  => 'created',
                    'channels' => ['webhook'],
                    'subject'  => 'Created',
                    'webhook'  => ['url' => 'https://example.com/hook', 'persistent' => false],
                ],
            ],
        ];
        $this->webhookMapper->expects(self::never())->method('insert');

        $installer = $this->makeInstaller();
        self::assertEmpty($installer->install(schemaId: 'schema-1', configuration: $config));
    }

    /**
     * Persistent webhook rule without URL logs warning and returns empty.
     */
    public function testPersistentRuleWithoutUrlLogsWarning(): void
    {
        $config = [
            'x-openregister-notifications' => [
                [
                    'trigger'  => 'created',
                    'channels' => ['webhook'],
                    'subject'  => 'Created',
                    'webhook'  => ['persistent' => true],
                ],
            ],
        ];
        $this->logger->expects(self::once())->method('warning');
        $this->webhookMapper->expects(self::never())->method('insert');

        $installer = $this->makeInstaller();
        self::assertEmpty($installer->install(schemaId: 'schema-1', configuration: $config));
    }

    /**
     * Existing webhook is returned when already installed.
     */
    public function testExistingWebhookReturned(): void
    {
        $config = [
            'x-openregister-notifications' => [
                [
                    'trigger'  => 'created',
                    'channels' => ['webhook'],
                    'subject'  => 'Created',
                    'webhook'  => ['url' => 'https://example.com/hook', 'persistent' => true],
                ],
            ],
        ];

        $existing = $this->createMock(Webhook::class);
        $this->webhookMapper->method('findAll')->willReturn([$existing]);
        $this->webhookMapper->expects(self::never())->method('insert');

        $installer = $this->makeInstaller();
        $result    = $installer->install(schemaId: 'schema-1', configuration: $config);

        self::assertCount(1, $result);
        self::assertSame($existing, $result[0]);
    }

    /**
     * New webhook is created when none found.
     */
    public function testNewWebhookCreated(): void
    {
        $config = [
            'x-openregister-notifications' => [
                [
                    'trigger'  => 'created',
                    'channels' => ['webhook'],
                    'subject'  => 'Created',
                    'webhook'  => ['url' => 'https://example.com/hook', 'persistent' => true],
                ],
            ],
        ];

        $this->webhookMapper->method('findAll')->willReturn([]);
        $newWebhook = $this->createMock(Webhook::class);
        $this->webhookMapper->expects(self::once())->method('insert')->willReturn($newWebhook);

        $installer = $this->makeInstaller();
        $result    = $installer->install(schemaId: 'schema-1', configuration: $config);

        self::assertCount(1, $result);
    }

    /**
     * Insert failure is logged and empty array returned.
     */
    public function testInsertFailureIsLogged(): void
    {
        $config = [
            'x-openregister-notifications' => [
                [
                    'trigger'  => 'created',
                    'channels' => ['webhook'],
                    'subject'  => 'Created',
                    'webhook'  => ['url' => 'https://example.com/hook', 'persistent' => true],
                ],
            ],
        ];

        $this->webhookMapper->method('findAll')->willReturn([]);
        $this->webhookMapper->method('insert')->willThrowException(new \RuntimeException('DB error'));
        $this->logger->expects(self::once())->method('error');

        $installer = $this->makeInstaller();
        self::assertEmpty($installer->install(schemaId: 'schema-1', configuration: $config));
    }
}
