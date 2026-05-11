<?php

/**
 * NotifyPushEndToEndTest
 *
 * Integration test exercising the full notify_push delivery path:
 * create an object, assert that a notify_custom event was queued
 * with the expected payload.
 *
 * This test is automatically skipped when notify_push is not installed
 * in the test environment (i.e. when OCA\NotifyPush\Queue\IQueue cannot
 * be resolved from the container).
 *
 * @category Test
 * @package  Unit\Integration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-9
 */

declare(strict_types=1);

namespace Unit\Integration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Listener\NotifyPushListener;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * End-to-end integration test for notify_push delivery.
 *
 * Skipped automatically when notify_push is not installed.
 *
 * @coversNothing This is an integration test, not a unit test
 */
class NotifyPushEndToEndTest extends TestCase
{
    /**
     * Test that creating an object results in a queued notify_custom event
     * with the expected payload structure.
     *
     * Skipped when notify_push is not installed in the test environment.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-9
     */
    public function testCreateObjectQueuesNotifyCustomEvent(): void
    {
        // Check whether notify_push is available.
        if (class_exists('OCA\\NotifyPush\\Queue\\IQueue') === false) {
            $this->markTestSkipped('notify_push is not installed; skipping end-to-end push test.');
        }

        /*
         * @var IAppManager&MockObject $appManager
         */
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);

        /*
         * @var LoggerInterface&MockObject $logger
         */
        $logger = $this->createMock(LoggerInterface::class);

        // Build a recording queue mock that stores pushed payloads.
        $pushedPayloads = [];
        $queue          = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['push'])
            ->getMock();
        $queue->method('push')
            ->willReturnCallback(
                function (string $type, array $payload) use (&$pushedPayloads): void {
                    $pushedPayloads[] = ['type' => $type, 'payload' => $payload];
                }
            );

        /*
         * @var ContainerInterface&MockObject $container
         */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('OCA\NotifyPush\Queue\IQueue')
            ->willReturn($queue);

        /*
         * @var PermissionHandler&MockObject $permissionHandler
         */
        $permissionHandler = $this->createMock(PermissionHandler::class);
        $permissionHandler->method('getReadableByUsers')->willReturn(['user1']);

        /*
         * @var IAppConfig&MockObject $appConfig
         */
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $appConfig->method('setValueString');

        /*
         * @var RegisterMapper&MockObject $registerMapper
         */
        $registerMapper = $this->createMock(RegisterMapper::class);
        // Register::getSlug() is a magic @method — use getMockBuilder with addMethods().
        $register = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $register->method('getSlug')->willReturn('e2e-register');
        $registerMapper->method('find')->willReturn($register);

        /*
         * @var SchemaMapper&MockObject $schemaMapper
         */
        $schemaMapper = $this->createMock(SchemaMapper::class);
        // Schema::getSlug() is a magic @method — use getMockBuilder with addMethods().
        $schema = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $schema->method('getSlug')->willReturn('e2e-schema');
        $schemaMapper->method('find')->willReturn($schema);

        NotifyPushListener::resetStaticState();

        $listener = new NotifyPushListener(
            appManager: $appManager,
            logger: $logger,
            container: $container,
            permissionHandler: $permissionHandler,
            appConfig: $appConfig,
            registerMapper: $registerMapper,
            schemaMapper: $schemaMapper,
        );

        $object = new ObjectEntity();
        $object->setUuid('e2e-test-uuid');
        $object->setRegister('reg-uuid');
        $object->setSchema('schema-uuid');
        $object->setVersion('1');

        $event = new ObjectCreatedEvent($object);
        $listener->handle($event);

        // Assert at least one event was queued.
        $this->assertNotEmpty($pushedPayloads, 'Expected at least one notify_custom event to be queued');

        // Assert the first push is of type notify_custom.
        $this->assertSame('notify_custom', $pushedPayloads[0]['type']);

        // Assert the payload data contains expected fields.
        $data = json_decode($pushedPayloads[0]['payload']['data'] ?? '{}', true);
        $this->assertSame('create', $data['action'] ?? null);
        $this->assertSame('e2e-test-uuid', $data['uuid'] ?? null);
        $this->assertSame('e2e-register', $data['register'] ?? null);
        $this->assertSame('e2e-schema', $data['schema'] ?? null);
    }//end testCreateObjectQueuesNotifyCustomEvent()
}//end class
