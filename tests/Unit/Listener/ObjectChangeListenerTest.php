<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\ObjectChangeListener;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectChangeListenerTest extends TestCase
{
    private ObjectChangeListener $listener;
    private TextExtractionService&MockObject $textExtractSvc;
    private SettingsService&MockObject $settingsService;
    private IJobList&MockObject $jobList;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->textExtractSvc = $this->createMock(TextExtractionService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new ObjectChangeListener(
            $this->textExtractSvc,
            $this->settingsService,
            $this->jobList,
            $this->logger,
        );
    }

    public function testEarlyReturnForUnrelatedEvent(): void
    {
        $event = $this->createMock(Event::class);
        $this->settingsService->expects($this->never())->method('getFileSettingsOnly');
        $this->listener->handle($event);
    }

    public function testHandlesObjectCreatedEvent(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'background']);

        $this->jobList->expects($this->once())->method('add');

        $this->listener->handle($event);
    }

    public function testHandlesObjectUpdatedEvent(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectUpdatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'background']);

        $this->jobList->expects($this->once())->method('add');

        $this->listener->handle($event);
    }

    public function testImmediateExtractionMode(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'immediate']);

        $this->textExtractSvc->expects($this->once())
            ->method('extractObject');

        $this->listener->handle($event);
    }

    public function testCronModeSkipsProcessing(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'cron']);

        $this->textExtractSvc->expects($this->never())->method('extractObject');
        $this->jobList->expects($this->never())->method('add');

        $this->listener->handle($event);
    }

    public function testManualModeSkipsProcessing(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'manual']);

        $this->textExtractSvc->expects($this->never())->method('extractObject');
        $this->jobList->expects($this->never())->method('add');

        $this->listener->handle($event);
    }

    public function testNullObjectIdSkipsExtraction(): void
    {
        $object = new ObjectEntity();
        // id is null by default (unsaved/magic mapper)
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'background']);

        $this->jobList->expects($this->never())->method('add');
        $this->textExtractSvc->expects($this->never())->method('extractObject');

        $this->listener->handle($event);
    }

    public function testExceptionDuringExtractionLogsError(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willThrowException(new \Exception('Settings error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->listener->handle($event);
    }
}
