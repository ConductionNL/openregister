<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\ObjectChangeListener;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Gap tests for ObjectChangeListener covering uncovered branches.
 */
class ObjectChangeListenerGapTest extends TestCase
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

    /**
     * Test unknown extraction mode falls back to background (covers default switch case).
     */
    public function testUnknownExtractionModeFallsBackToBackground(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'unknown_mode']);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ObjectTextExtractionJob::class, ['object_id' => 42]);

        $this->listener->handle($event);
    }

    /**
     * Test immediate extraction mode when extraction throws exception (covers inner catch).
     */
    public function testImmediateExtractionFailureLogs(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'immediate']);

        $this->textExtractSvc->expects($this->once())
            ->method('extractObject')
            ->willThrowException(new \Exception('Extraction failed'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $this->listener->handle($event);
    }

    /**
     * Test background mode when jobList->add throws exception (covers inner catch).
     */
    public function testBackgroundJobQueueFailureLogs(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'background']);

        $this->jobList->expects($this->once())
            ->method('add')
            ->willThrowException(new \Exception('Job queue error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $this->listener->handle($event);
    }

    /**
     * Test that default extractionMode is 'background' when not set in settings.
     */
    public function testDefaultExtractionModeIsBackground(): void
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid('test-uuid');
        $event = new ObjectCreatedEvent($object);

        // Return settings without extractionMode key
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn([]);

        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ObjectTextExtractionJob::class, ['object_id' => 42]);

        $this->listener->handle($event);
    }

    /**
     * Test ObjectUpdatedEvent with immediate mode.
     */
    public function testObjectUpdatedEventWithImmediateMode(): void
    {
        $object = new ObjectEntity();
        $object->setId(99);
        $object->setUuid('updated-uuid');
        $event = new ObjectUpdatedEvent($object);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'immediate']);

        $this->textExtractSvc->expects($this->once())
            ->method('extractObject')
            ->with(99, false);

        $this->listener->handle($event);
    }
}
