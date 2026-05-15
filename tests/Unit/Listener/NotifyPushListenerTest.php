<?php

/**
 * NotifyPushListenerTest
 *
 * Unit tests for the NotifyPushListener class.
 *
 * @category Test
 * @package  Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-7
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\NotifyPushListener;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for NotifyPushListener.
 *
 * @coversDefaultClass \OCA\OpenRegister\Listener\NotifyPushListener
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)         Nine test scenarios required by spec
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Test coverage requires many mocks
 */
class NotifyPushListenerTest extends TestCase
{

    /**
     * @var IAppManager&MockObject
     */
    private IAppManager $appManager;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * @var PermissionHandler&MockObject
     */
    private PermissionHandler $permissionHandler;

    /**
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper $registerMapper;

    /**
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var object&MockObject A mock for the IQueue interface
     */
    private object $queue;

    private NotifyPushListener $listener;

    /**
     * Set up mocks and listener before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Reset ALL static state between tests (including $seen and $queueUnavailable).
        NotifyPushListener::resetStaticState();

        $this->appManager        = $this->createMock(IAppManager::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->container         = $this->createMock(ContainerInterface::class);
        $this->permissionHandler = $this->createMock(PermissionHandler::class);
        $this->appConfig         = $this->createMock(IAppConfig::class);
        $this->registerMapper    = $this->createMock(RegisterMapper::class);
        $this->schemaMapper      = $this->createMock(SchemaMapper::class);

        // Create a mock queue with a push() method.
        $this->queue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['push'])
            ->getMock();

        $this->listener = new NotifyPushListener(
            appManager: $this->appManager,
            logger: $this->logger,
            container: $this->container,
            permissionHandler: $this->permissionHandler,
            appConfig: $this->appConfig,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
        );
    }//end setUp()

    /**
     * Reset static state after each test to avoid cross-test pollution.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        NotifyPushListener::resetStaticState();
        parent::tearDown();
    }//end tearDown()

    /**
     * Build an ObjectEntity with the required identifiers set.
     *
     * @param string $uuid         Object UUID
     * @param string $registerUuid Register UUID
     * @param string $schemaUuid   Schema UUID
     *
     * @return ObjectEntity
     */
    private function buildObject(
        string $uuid='test-uuid',
        string $registerUuid='reg-uuid',
        string $schemaUuid='schema-uuid'
    ): ObjectEntity {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setRegister($registerUuid);
        $object->setSchema($schemaUuid);
        $object->setVersion('1');
        return $object;
    }//end buildObject()

    /**
     * Configure the container to return the mock queue.
     *
     * @return void
     */
    private function expectQueueResolvable(): void
    {
        $this->container
            ->method('get')
            ->with('OCA\NotifyPush\Queue\IQueue')
            ->willReturn($this->queue);
    }//end expectQueueResolvable()

    /**
     * Configure register and schema mappers to return slugs.
     *
     * @param string $registerSlug Register slug to return
     * @param string $schemaSlug   Schema slug to return
     *
     * @return void
     */
    private function expectSlugLookups(string $registerSlug='my-register', string $schemaSlug='my-schema'): void
    {
        // Register::getSlug() is a magic @method — use getMockBuilder with addMethods().
        $register = $this->getMockBuilder(Register::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $register->method('getSlug')->willReturn($registerSlug);
        $this->registerMapper->method('find')->willReturn($register);

        // Schema::getSlug() is a magic @method — use getMockBuilder with addMethods().
        $schema = $this->getMockBuilder(Schema::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $schema->method('getSlug')->willReturn($schemaSlug);
        $this->schemaMapper->method('find')->willReturn($schema);
    }//end expectSlugLookups()

    /**
     * Test that when the container cannot resolve IQueue, no exception is propagated
     * and no WARNING or ERROR is logged.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testSoftFailWhenQueueNotResolvable(): void
    {
        $this->container
            ->method('get')
            ->with('OCA\NotifyPush\Queue\IQueue')
            ->willThrowException(new \RuntimeException('notify_push not installed'));

        $this->logger->expects($this->once())
            ->method('debug');

        $this->logger->expects($this->never())
            ->method('warning');

        $this->logger->expects($this->never())
            ->method('error');

        $object = $this->buildObject();
        $event  = new ObjectCreatedEvent($object);

        // Must not throw.
        $this->listener->handle($event);
    }//end testSoftFailWhenQueueNotResolvable()

    /**
     * Test that on ObjectUpdatedEvent, or-object-{uuid} is pushed per user
     * but no collection event is emitted.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testEmitsObjectEventOnUpdate(): void
    {
        $this->expectQueueResolvable();
        $this->expectSlugLookups();

        $this->permissionHandler
            ->method('getReadableByUsers')
            ->willReturn(['user1', 'user2']);

        $this->appConfig
            ->method('getValueString')
            ->willReturn('');
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'push_available', '1');

        // Expect push called twice (once per user, object event only).
        $this->queue->expects($this->exactly(2))
            ->method('push');

        $object = $this->buildObject();
        $event  = new ObjectUpdatedEvent($object);
        $this->listener->handle($event);
    }//end testEmitsObjectEventOnUpdate()

    /**
     * Test that on ObjectCreatedEvent, both or-object-* and or-collection-* are pushed per user.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testEmitsCollectionEventOnCreate(): void
    {
        $this->expectQueueResolvable();
        $this->expectSlugLookups();

        $this->permissionHandler
            ->method('getReadableByUsers')
            ->willReturn(['user1']);

        $this->appConfig
            ->method('getValueString')
            ->willReturn('');
        $this->appConfig->method('setValueString');

        // Expect push called twice: once for object, once for collection.
        $this->queue->expects($this->exactly(2))
            ->method('push');

        $object = $this->buildObject();
        $event  = new ObjectCreatedEvent($object);
        $this->listener->handle($event);
    }//end testEmitsCollectionEventOnCreate()

    /**
     * Test that on ObjectDeletedEvent, both or-object-* and or-collection-* are pushed per user.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testEmitsCollectionEventOnDelete(): void
    {
        $this->expectQueueResolvable();
        $this->expectSlugLookups();

        $this->permissionHandler
            ->method('getReadableByUsers')
            ->willReturn(['user1']);

        $this->appConfig
            ->method('getValueString')
            ->willReturn('');
        $this->appConfig->method('setValueString');

        // Expect push called twice: once for object, once for collection.
        $this->queue->expects($this->exactly(2))
            ->method('push');

        $object = $this->buildObject();
        $event  = new ObjectDeletedEvent($object);
        $this->listener->handle($event);
    }//end testEmitsCollectionEventOnDelete()

    /**
     * Test that the collection event string contains register slug and schema slug (not IDs).
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testCollectionEventUsesSlugsNotIds(): void
    {
        $this->expectQueueResolvable();
        $this->expectSlugLookups(registerSlug: 'my-register', schemaSlug: 'my-schema');

        $this->permissionHandler
            ->method('getReadableByUsers')
            ->willReturn(['user1']);

        $this->appConfig
            ->method('getValueString')
            ->willReturn('');
        $this->appConfig->method('setValueString');

        $pushCalls = [];
        $this->queue
            ->method('push')
            ->willReturnCallback(
                    function (string $type, array $payload) use (&$pushCalls): void {
                        $pushCalls[] = $payload;
                    }
                    );

        $object = $this->buildObject(registerUuid: 'reg-uuid', schemaUuid: 'schema-uuid');
        $event  = new ObjectCreatedEvent($object);
        $this->listener->handle($event);

        $this->assertGreaterThanOrEqual(2, count($pushCalls));

        // Find collection push: the one whose body contains register/schema slugs
        // and whose message channel matches the OR_COLLECTION pattern.
        $collectionPayloads = array_filter(
                $pushCalls,
                function (array $p): bool {
                    $body = $p['body'] ?? [];
                    if (is_string($body) === true) {
                        $body = json_decode($body, true);
                    }
                    return isset($body['register']) && $body['register'] === 'my-register'
                    && isset($body['schema']) && $body['schema'] === 'my-schema';
                }
                );

        $this->assertNotEmpty($collectionPayloads, 'Collection push payload must contain register/schema slugs');
    }//end testCollectionEventUsesSlugsNotIds()

    /**
     * Test that the same (uuid, action) pair fired twice only results in one push.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testDedupPreventsDoubleEmit(): void
    {
        $this->expectQueueResolvable();
        $this->expectSlugLookups();

        $this->permissionHandler
            ->method('getReadableByUsers')
            ->willReturn(['user1']);

        $this->appConfig
            ->method('getValueString')
            ->willReturn('');
        $this->appConfig->method('setValueString');

        // Two handle() calls for the same object+action should emit once.
        // create fires: object (1) + collection (1) = 2 pushes total.
        $this->queue->expects($this->exactly(2))
            ->method('push');

        $object = $this->buildObject();
        $event  = new ObjectCreatedEvent($object);
        $this->listener->handle($event);
        $this->listener->handle($event);
        // second call must be ignored.
    }//end testDedupPreventsDoubleEmit()

    /**
     * Test that when batch mode is active, handle() accumulates but does not push.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testBatchModeSuppressesPerObjectPush(): void
    {
        $this->expectQueueResolvable();
        $this->expectSlugLookups();

        $this->permissionHandler
            ->method('getReadableByUsers')
            ->willReturn(['user1']);

        // No pushes should happen during batch mode.
        $this->queue->expects($this->never())
            ->method('push');

        NotifyPushListener::setBatchMode(true);

        for ($i = 0; $i < 10; $i++) {
            $object = $this->buildObject(uuid: 'uuid-'.$i);
            $event  = new ObjectCreatedEvent($object);
            $this->listener->handle($event);
        }
    }//end testBatchModeSuppressesPerObjectPush()

    /**
     * Test that flushBatch() emits one push per accumulated (register, schema) pair.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testFlushBatchEmitsOneCollectionEvent(): void
    {
        $this->expectQueueResolvable();
        $this->expectSlugLookups(registerSlug: 'reg', schemaSlug: 'schema');

        $this->permissionHandler
            ->method('getReadableByUsers')
            ->willReturn(['user1']);

        NotifyPushListener::setBatchMode(true);

        // 10 events all for the same register+schema pair → one collection event on flush.
        for ($i = 0; $i < 10; $i++) {
            $object = $this->buildObject(uuid: 'uuid-'.$i);
            $event  = new ObjectCreatedEvent($object);
            $this->listener->handle($event);
        }

        // flushBatch emits exactly 1 push (one per unique register/schema pair).
        $this->queue->expects($this->once())
            ->method('push');

        NotifyPushListener::flushBatch($this->queue, $this->permissionHandler);
        NotifyPushListener::setBatchMode(false);
    }//end testFlushBatchEmitsOneCollectionEvent()

    /**
     * Test that AppConfig::setValueString('openregister', 'push_available', '1') is called
     * exactly once on the first successful push.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-7
     */
    public function testPushAvailableFlagSetOnFirstSuccess(): void
    {
        $this->expectQueueResolvable();
        $this->expectSlugLookups();

        $this->permissionHandler
            ->method('getReadableByUsers')
            ->willReturn(['user1']);

        $this->appConfig
            ->method('getValueString')
            ->with('openregister', 'push_available', '')
            ->willReturn('');

        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'push_available', '1');

        $this->queue->method('push');

        $object = $this->buildObject();
        $event  = new ObjectUpdatedEvent($object);
        $this->listener->handle($event);
    }//end testPushAvailableFlagSetOnFirstSuccess()
}//end class
