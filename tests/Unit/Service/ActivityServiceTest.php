<?php

/**
 * ActivityService Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ActivityService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ActivityService.
 */
class ActivityServiceTest extends TestCase
{
    /** @var IManager&MockObject */
    private IManager $activityManager;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    /** @var IURLGenerator&MockObject */
    private IURLGenerator $urlGenerator;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private ActivityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activityManager = $this->createMock(IManager::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->urlGenerator    = $this->createMock(IURLGenerator::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new ActivityService(
            $this->activityManager,
            $this->userSession,
            $this->urlGenerator,
            $this->logger,
        );
    }

    /**
     * Create a mock user that returns the given UID.
     */
    private function mockUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    /**
     * Create a mock IEvent that records setters via fluent interface.
     */
    private function mockEvent(): IEvent
    {
        $event = $this->createMock(IEvent::class);
        $event->method('setApp')->willReturnSelf();
        $event->method('setType')->willReturnSelf();
        $event->method('setAuthor')->willReturnSelf();
        $event->method('setTimestamp')->willReturnSelf();
        $event->method('setSubject')->willReturnSelf();
        $event->method('setObject')->willReturnSelf();
        $event->method('setAffectedUser')->willReturnSelf();
        $event->method('setLink')->willReturnSelf();
        return $event;
    }

    /**
     * Create a real ObjectEntity with given properties.
     * Uses Entity __call magic which maps to protected properties.
     */
    private function createObjectEntity(
        ?string $name = 'Test Object',
        ?string $uuid = 'abc-123',
        ?string $register = '5',
        ?string $schema = '12',
        ?string $owner = null
    ): ObjectEntity {
        $obj = new ObjectEntity();
        $obj->setName($name);
        $obj->setUuid($uuid);
        $obj->setRegister($register);
        $obj->setSchema($schema);
        if ($owner !== null) {
            $obj->setOwner($owner);
        }
        return $obj;
    }

    /**
     * Create a real Register entity.
     */
    private function createRegister(
        ?string $title = 'Test Register',
        ?string $uuid = 'reg-123',
        ?string $owner = null
    ): Register {
        $reg = new Register();
        $reg->setTitle($title);
        $reg->setUuid($uuid);
        if ($owner !== null) {
            $reg->setOwner($owner);
        }
        return $reg;
    }

    /**
     * Create a real Schema entity.
     */
    private function createSchema(
        ?string $title = 'Test Schema',
        ?string $uuid = 'sch-123',
        ?string $owner = null
    ): Schema {
        $sch = new Schema();
        $sch->setTitle($title);
        $sch->setUuid($uuid);
        if ($owner !== null) {
            $sch->setOwner($owner);
        }
        return $sch;
    }

    /**
     * Test: publishObjectCreated publishes an event with correct subject and type.
     */
    public function testPublishObjectCreatedPublishesEvent(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('admin'));
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister/');

        $event = $this->mockEvent();
        $event->expects($this->once())->method('setApp')->with('openregister');
        $event->expects($this->once())->method('setType')->with('openregister_objects');
        $event->expects($this->once())->method('setSubject')->with('object_created', ['title' => 'Test Object']);
        $event->expects($this->once())->method('setAuthor')->with('admin');
        $event->expects($this->once())->method('setAffectedUser')->with('admin');

        $this->activityManager->method('generateEvent')->willReturn($event);
        $this->activityManager->expects($this->once())->method('publish')->with($event);

        $this->service->publishObjectCreated($this->createObjectEntity());
    }

    /**
     * Test: publishRegisterCreated publishes event with register type.
     */
    public function testPublishRegisterCreatedPublishesEvent(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('admin'));
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister/');

        $event = $this->mockEvent();
        $event->expects($this->once())->method('setType')->with('openregister_registers');
        $event->expects($this->once())->method('setSubject')->with('register_created', ['title' => 'Test Register']);

        $this->activityManager->method('generateEvent')->willReturn($event);
        $this->activityManager->expects($this->once())->method('publish');

        $this->service->publishRegisterCreated($this->createRegister());
    }

    /**
     * Test: publishSchemaDeleted publishes event with empty link.
     */
    public function testPublishSchemaDeletedPublishesEventWithEmptyLink(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('admin'));

        $event = $this->mockEvent();
        $event->expects($this->once())->method('setSubject')->with('schema_deleted', ['title' => 'Test Schema']);
        $event->expects($this->never())->method('setLink');

        $this->activityManager->method('generateEvent')->willReturn($event);
        $this->activityManager->expects($this->once())->method('publish');

        $this->service->publishSchemaDeleted($this->createSchema());
    }

    /**
     * Test: When object owner differs from author, two events are published (dual-notification).
     */
    public function testDualNotificationWhenOwnerDiffersFromAuthor(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('editor'));
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister/');

        $event = $this->mockEvent();
        $this->activityManager->method('generateEvent')->willReturn($event);
        // Expect exactly 2 publishes: one for editor, one for owner1.
        $this->activityManager->expects($this->exactly(2))->method('publish');

        $object = $this->createObjectEntity(owner: 'owner1');
        $this->service->publishObjectUpdated($object);
    }

    /**
     * Test: When IManager::publish() throws, the exception is caught and logged.
     */
    public function testExceptionIsCaughtAndLogged(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('admin'));
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister/');

        $event = $this->mockEvent();
        $this->activityManager->method('generateEvent')->willReturn($event);
        $this->activityManager->method('publish')->willThrowException(new \RuntimeException('Activity DB error'));

        $this->logger->expects($this->once())->method('error');

        // Should NOT throw.
        $this->service->publishObjectCreated($this->createObjectEntity());
    }

    /**
     * Test: System context (no user session) with owner falls back to owner as affected user.
     */
    public function testSystemContextFallsBackToOwner(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister/');

        $event = $this->mockEvent();
        $event->expects($this->once())->method('setAuthor')->with('');
        $event->expects($this->once())->method('setAffectedUser')->with('system-owner');

        $this->activityManager->method('generateEvent')->willReturn($event);
        $this->activityManager->expects($this->once())->method('publish');

        $object = $this->createObjectEntity(owner: 'system-owner');
        $this->service->publishObjectCreated($object);
    }

    /**
     * Test: System context with no owner skips publishing entirely.
     */
    public function testSystemContextNoOwnerSkipsPublishing(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->activityManager->expects($this->never())->method('publish');

        $object = $this->createObjectEntity(owner: null);
        $this->service->publishObjectCreated($object);
    }

    /**
     * Test: All 9 publish methods exist and are callable.
     */
    public function testAllNinePublishMethodsExist(): void
    {
        $methods = [
            'publishObjectCreated', 'publishObjectUpdated', 'publishObjectDeleted',
            'publishRegisterCreated', 'publishRegisterUpdated', 'publishRegisterDeleted',
            'publishSchemaCreated', 'publishSchemaUpdated', 'publishSchemaDeleted',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists($this->service, $method),
                "Method $method should exist on ActivityService"
            );
        }
    }
}
