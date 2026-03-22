<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Listener\FileChangeListener;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileChangeListenerTest extends TestCase
{
    private FileChangeListener $listener;
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

        // Reset static cache between tests.
        $ref = new \ReflectionProperty(FileChangeListener::class, 'cachedExtractScope');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $ref2 = new \ReflectionProperty(FileChangeListener::class, 'cachedExtractionMode');
        $ref2->setAccessible(true);
        $ref2->setValue(null, null);

        $this->listener = new FileChangeListener(
            $this->textExtractSvc,
            $this->settingsService,
            $this->jobList,
            $this->logger,
        );
    }

    public function testHandleIgnoresNonFileEvents(): void
    {
        $event = $this->createMock(Event::class);

        $this->jobList->expects($this->never())
            ->method('add');

        $this->listener->handle($event);
    }

    public function testHandleIgnoresFolderNodes(): void
    {
        $folder = $this->createMock(Folder::class);
        $event = $this->createMock(NodeCreatedEvent::class);
        $event->method('getNode')->willReturn($folder);

        $this->jobList->expects($this->never())
            ->method('add');

        $this->listener->handle($event);
    }

    public function testHandleSkipsWhenScopeIsNone(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/admin/files/Open Registers/test.txt');
        $file->method('getId')->willReturn(42);
        $file->method('getName')->willReturn('test.txt');

        $event = $this->createMock(NodeCreatedEvent::class);
        $event->method('getNode')->willReturn($file);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionScope' => 'none', 'extractionMode' => 'background']);

        $this->jobList->expects($this->never())
            ->method('add');

        $this->listener->handle($event);
    }

    public function testHandleSkipsAnonymizedFiles(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/admin/files/Open Registers/doc_anonymized.pdf');
        $file->method('getId')->willReturn(42);
        $file->method('getName')->willReturn('doc_anonymized.pdf');

        $event = $this->createMock(NodeCreatedEvent::class);
        $event->method('getNode')->willReturn($file);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionScope' => 'all', 'extractionMode' => 'background']);

        $this->jobList->expects($this->never())
            ->method('add');

        $this->listener->handle($event);
    }

    public function testHandleQueuesBackgroundJobForOpenRegisterFile(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/admin/files/Open Registers/test.pdf');
        $file->method('getId')->willReturn(42);
        $file->method('getName')->willReturn('test.pdf');

        $event = $this->createMock(NodeCreatedEvent::class);
        $event->method('getNode')->willReturn($file);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionScope' => 'objects', 'extractionMode' => 'background']);

        $this->jobList->expects($this->once())
            ->method('add')
            ->with(FileTextExtractionJob::class, ['file_id' => 42]);

        $this->listener->handle($event);
    }

    public function testHandleImmediateModeSynchronousExtraction(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/admin/files/Open Registers/test.pdf');
        $file->method('getId')->willReturn(42);
        $file->method('getName')->willReturn('test.pdf');

        $event = $this->createMock(NodeCreatedEvent::class);
        $event->method('getNode')->willReturn($file);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionScope' => 'objects', 'extractionMode' => 'immediate']);

        $this->textExtractSvc->expects($this->once())
            ->method('extractFile')
            ->with(42, false);

        $this->jobList->expects($this->never())
            ->method('add');

        $this->listener->handle($event);
    }

    public function testHandleScopeObjectsSkipsNonOpenRegisterFiles(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/admin/files/Documents/random.pdf');
        $file->method('getId')->willReturn(42);
        $file->method('getName')->willReturn('random.pdf');

        $event = $this->createMock(NodeCreatedEvent::class);
        $event->method('getNode')->willReturn($file);

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['extractionScope' => 'objects', 'extractionMode' => 'background']);

        $this->jobList->expects($this->never())
            ->method('add');

        $this->listener->handle($event);
    }
}
