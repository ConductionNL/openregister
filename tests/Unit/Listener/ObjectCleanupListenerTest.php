<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\BackgroundJob\ObjectCleanupJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\ObjectCleanupListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the deferred deleted-object cleanup path.
 *
 * The listener no longer calls the six cleanup services itself: it buffers the
 * deleted object's identity onto ListenerDeferralService and the real work runs
 * in ObjectCleanupJob, with an inline fallback through
 * ObjectRelationCleanupService when deferral is switched off. This test was
 * still written against the pre-deferral seven-service constructor and errored
 * on every run.
 */
class ObjectCleanupListenerTest extends TestCase
{
    private ListenerDeferralService&MockObject $deferral;
    private ObjectRelationCleanupService&MockObject $cleanup;
    private ObjectCleanupListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deferral = $this->createMock(ListenerDeferralService::class);
        $this->cleanup = $this->createMock(ObjectRelationCleanupService::class);

        $this->listener = new ObjectCleanupListener(
            $this->deferral,
            $this->cleanup
        );
    }

    private function createDeleteEvent(
        string $uuid = 'abc-123',
        string $register = '7',
        string $schema = '228'
    ): ObjectDeletedEvent {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setRegister($register);
        $object->setSchema($schema);

        return new ObjectDeletedEvent($object);
    }

    public function testHandleDefersTheDeletedObject(): void
    {
        $this->deferral->method('isDeferralEnabled')->willReturn(true);

        $this->deferral->expects($this->once())
            ->method('defer')
            ->with(
                ObjectCleanupJob::class,
                [
                    'uuid' => 'abc-123',
                    'register' => '7',
                    'schema' => '228',
                ],
                $this->anything(),
                'abc-123'
            );

        $this->cleanup->expects($this->never())->method('cleanup');

        $this->listener->handle($this->createDeleteEvent());
    }

    public function testHandleCleansInlineWhenDeferralIsDisabled(): void
    {
        $this->deferral->method('isDeferralEnabled')->willReturn(false);

        $this->cleanup->expects($this->once())
            ->method('cleanup')
            ->with('abc-123');

        $this->deferral->expects($this->never())->method('defer');

        $this->listener->handle($this->createDeleteEvent());
    }

    public function testHandleIgnoresNonObjectDeletedEvents(): void
    {
        $event = $this->createMock(Event::class);

        $this->deferral->expects($this->never())->method('defer');
        $this->cleanup->expects($this->never())->method('cleanup');

        $this->listener->handle($event);
    }

    public function testHandleIgnoresAnObjectWithNoUuid(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('');

        $this->deferral->expects($this->never())->method('defer');
        $this->cleanup->expects($this->never())->method('cleanup');

        $this->listener->handle(new ObjectDeletedEvent($object));
    }
}
