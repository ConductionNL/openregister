<?php

declare(strict_types=1);

/**
 * ContextChatSubmissionListener unit tests (context-chat-provider).
 *
 * Proves the submission listener only calls `IContentManager::submitContent()`
 * for objects whose schema opted in via `x-openregister-contextchat` AND that
 * satisfy the publication predicate (implemented as "the `public` group would
 * be granted `read`" — the living equivalent of the removed
 * `@self.published`/`@self.depublished` metadata, see
 * `openspec/specs/deprecate-published-metadata/spec.md`), and that deletion
 * always issues a removal call once the schema is opted in.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\ContextChatSubmissionListener
 */
class ContextChatSubmissionListenerTest extends TestCase
{
    private SchemaMapper $schemaMapper;

    private PermissionHandler $permissionHandler;

    private IContentManager $contentManager;

    private IUserManager $userManager;

    private ContextChatSubmissionListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper      = $this->createMock(SchemaMapper::class);
        $this->permissionHandler = $this->createMock(PermissionHandler::class);
        $this->contentManager    = $this->createMock(IContentManager::class);
        $this->userManager       = $this->createMock(IUserManager::class);

        $this->listener = new ContextChatSubmissionListener(
            $this->contentManager,
            $this->schemaMapper,
            $this->permissionHandler,
            $this->userManager,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Build an object fixture belonging to the given schema id.
     */
    private function makeObject(int $schemaId, array $data=[]): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('obj-uuid-1');
        $object->setRegister('1');
        $object->setSchema((string) $schemaId);
        $object->setOwner('alice');
        $object->setObject($data + ['name' => 'Test Object', 'summary' => 'A test object']);

        return $object;
    }

    private function optedInSchema(int $id): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setConfiguration(['x-openregister-contextchat' => true]);

        return $schema;
    }

    private function optedOutSchema(int $id): Schema
    {
        $schema = new Schema();
        $schema->setId($id);

        return $schema;
    }

    /**
     * 6.3: a schema without the opt-in flag never submits, regardless of
     * publication state.
     */
    public function testOptedOutSchemaNeverSubmits(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedOutSchema(1));
        $this->permissionHandler->method('hasGroupPermission')->willReturn(true);

        $this->contentManager->expects($this->never())->method('submitContent');

        $result = $this->listener->submitIfEligible($this->makeObject(1));
        $this->assertFalse($result);
    }

    /**
     * 6.3/6.4: an opted-in schema with a publicly-readable object submits.
     */
    public function testOptedInAndPublicSchemaSubmits(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedInSchema(2));
        $this->permissionHandler->method('resolveAuthorization')->willReturn(['read' => ['public']]);
        $this->permissionHandler->method('hasGroupPermission')->willReturn(true);
        $this->permissionHandler->method('getReadableByUsers')->willReturn(['alice', 'bob']);

        $captured = null;
        $this->contentManager->expects($this->once())
            ->method('submitContent')
            ->with(
                $this->equalTo('openregister'),
                $this->callback(function (array $items) use (&$captured) {
                    $captured = $items[0];
                    return true;
                })
            );

        $result = $this->listener->submitIfEligible($this->makeObject(2));

        $this->assertTrue($result);
        $this->assertInstanceOf(ContentItem::class, $captured);
        $this->assertSame('obj-uuid-1', $captured->itemId);
        $this->assertSame('openregister_objects', $captured->providerId);
        $this->assertSame('Test Object', $captured->title);
        $this->assertStringContainsString('name: Test Object', $captured->content);
        $this->assertSame(['alice', 'bob'], $captured->users);
    }

    /**
     * 6.4: an opted-in schema whose object is NOT publicly readable never submits.
     */
    public function testOptedInButUnpublishedNeverSubmits(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedInSchema(3));
        $this->permissionHandler->method('resolveAuthorization')->willReturn(['read' => ['admin']]);
        $this->permissionHandler->method('hasGroupPermission')->willReturn(false);

        $this->contentManager->expects($this->never())->method('submitContent');

        $result = $this->listener->submitIfEligible($this->makeObject(3));
        $this->assertFalse($result);
    }

    /**
     * 6.4: an update that makes a previously-published object no longer
     * published REMOVES it rather than resubmitting.
     */
    public function testDepublishedUpdateRemovesRatherThanResubmits(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedInSchema(4));
        $this->permissionHandler->method('resolveAuthorization')->willReturn(['read' => ['admin']]);
        $this->permissionHandler->method('hasGroupPermission')->willReturn(false);

        $this->contentManager->expects($this->never())->method('submitContent');
        $this->contentManager->expects($this->once())
            ->method('deleteContent')
            ->with('openregister', 'openregister_objects', ['obj-uuid-1']);

        $result = $this->listener->handle(
            new ObjectUpdatedEvent(newObject: $this->makeObject(4), oldObject: $this->makeObject(4))
        );

        $this->assertNull($result);
    }

    /**
     * 6.2: `ObjectCreatedEvent` on an eligible object submits via `handle()`.
     */
    public function testObjectCreatedEventSubmits(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedInSchema(5));
        $this->permissionHandler->method('resolveAuthorization')->willReturn(null);
        $this->permissionHandler->method('hasGroupPermission')->willReturn(true);
        $this->permissionHandler->method('getReadableByUsers')->willReturn(['alice']);

        $this->contentManager->expects($this->once())->method('submitContent');

        $this->listener->handle(new ObjectCreatedEvent(object: $this->makeObject(5)));
    }

    /**
     * 6.5: object deletion issues a content-removal call once the schema is
     * (or was) opted in, regardless of publication state at delete time.
     */
    public function testObjectDeletedEventRemovesContent(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedInSchema(6));

        $this->contentManager->expects($this->once())
            ->method('deleteContent')
            ->with('openregister', 'openregister_objects', ['obj-uuid-1']);
        $this->contentManager->expects($this->never())->method('submitContent');

        $this->listener->handle(new ObjectDeletedEvent(object: $this->makeObject(6)));
    }

    /**
     * Deletion of an object on an opted-OUT schema is a no-op — nothing was
     * ever submitted, so there is nothing to remove.
     */
    public function testObjectDeletedEventOnOptedOutSchemaIsNoop(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedOutSchema(7));

        $this->contentManager->expects($this->never())->method('deleteContent');
        $this->contentManager->expects($this->never())->method('submitContent');

        $this->listener->handle(new ObjectDeletedEvent(object: $this->makeObject(7)));
    }

    /**
     * Broadcast audience fallback: when the schema grants `public` read with
     * no specific group list (getReadableByUsers() returns []), the listener
     * falls back to every "seen" user rather than submitting an empty
     * (access-revoking) users array.
     */
    public function testBroadcastAudienceFallsBackToSeenUsers(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedInSchema(8));
        $this->permissionHandler->method('resolveAuthorization')->willReturn([]);
        $this->permissionHandler->method('hasGroupPermission')->willReturn(true);
        $this->permissionHandler->method('getReadableByUsers')->willReturn([]);

        $this->userManager->method('callForSeenUsers')->willReturnCallback(function (\Closure $cb) {
            $user = $this->createMock(\OCP\IUser::class);
            $user->method('getUID')->willReturn('charlie');
            $cb($user);
        });

        $captured = null;
        $this->contentManager->expects($this->once())
            ->method('submitContent')
            ->with(
                $this->anything(),
                $this->callback(function (array $items) use (&$captured) {
                    $captured = $items[0];
                    return true;
                })
            );

        $this->listener->submitIfEligible($this->makeObject(8));

        $this->assertSame(['charlie'], $captured->users);
    }

    /**
     * Content submission must never break the object write it observes:
     * even a `ContentManager::submitContent()` failure is swallowed, not
     * propagated to the create/update that triggered it.
     */
    public function testSubmissionFailureNeverBreaksTheObjectWrite(): void
    {
        $this->schemaMapper->method('find')->willReturn($this->optedInSchema(9));
        $this->permissionHandler->method('resolveAuthorization')->willReturn(['read' => ['public']]);
        $this->permissionHandler->method('hasGroupPermission')->willReturn(true);
        $this->permissionHandler->method('getReadableByUsers')->willReturn(['alice']);
        $this->contentManager->method('submitContent')->willThrowException(new \RuntimeException('context_chat down'));

        $this->listener->handle(new ObjectCreatedEvent(object: $this->makeObject(9)));

        $this->addToAssertionCount(1);
    }
}
