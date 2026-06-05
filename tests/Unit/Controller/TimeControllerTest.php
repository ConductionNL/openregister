<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\TimeController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimeLink;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TimeEntryService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TimeController.
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
 */
class TimeControllerTest extends TestCase
{

    private TimeController $controller;
    private IRequest&MockObject $request;
    private TimeEntryService&MockObject $timeEntryService;
    private ObjectService&MockObject $objectService;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request          = $this->createMock(IRequest::class);
        $this->timeEntryService = $this->createMock(TimeEntryService::class);
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->controller = new TimeController(
            appName: 'openregister',
            request: $this->request,
            timeEntryService: $this->timeEntryService,
            objectService: $this->objectService,
            userSession: $this->userSession,
            logger: $this->logger
        );
    }//end setUp()

    private function makeObject(): ObjectEntity
    {
        $obj = new ObjectEntity();
        $ref = new \ReflectionClass($obj);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($obj, 1);
        $obj->setUuid('obj-uuid');
        return $obj;
    }//end makeObject()

    public function testIndexReturns501WhenBackendUnavailable(): void
    {
        $this->timeEntryService->method('isBackendAvailable')->willReturn(false);
        $this->timeEntryService->method('getBackendName')->willReturn('timemanager');

        $result = $this->controller->index('reg', 'schema', 'obj-1');

        $this->assertSame(501, $result->getStatus());
        $this->assertSame('APP_NOT_AVAILABLE', $result->getData()['code']);
    }//end testIndexReturns501WhenBackendUnavailable()

    public function testIndexReturns404WhenObjectNotFound(): void
    {
        $this->timeEntryService->method('isBackendAvailable')->willReturn(true);
        $this->objectService->method('getObject')->willReturn(null);

        $result = $this->controller->index('reg', 'schema', 'missing');

        $this->assertSame(404, $result->getStatus());
    }//end testIndexReturns404WhenObjectNotFound()

    public function testIndexReturnsEntries(): void
    {
        $this->timeEntryService->method('isBackendAvailable')->willReturn(true);
        $this->objectService->method('getObject')->willReturn($this->makeObject());
        $this->timeEntryService->method('getEntriesForObject')
            ->with('obj-uuid')
            ->willReturn(['results' => [], 'total' => 0, 'totalMinutes' => 0]);

        $result = $this->controller->index('reg', 'schema', 'obj-uuid');

        $this->assertSame(200, $result->getStatus());
        $this->assertArrayHasKey('totalMinutes', $result->getData());
    }//end testIndexReturnsEntries()

    public function testCreateReturns501WhenBackendUnavailable(): void
    {
        $this->timeEntryService->method('isBackendAvailable')->willReturn(false);
        $this->timeEntryService->method('getBackendName')->willReturn('timemanager');

        $result = $this->controller->create('reg', 'schema', 'obj-1');

        $this->assertSame(501, $result->getStatus());
    }//end testCreateReturns501WhenBackendUnavailable()

    public function testCreateReturns201OnSuccess(): void
    {
        $this->timeEntryService->method('isBackendAvailable')->willReturn(true);
        $this->objectService->method('getObject')->willReturn($this->makeObject());

        $this->request->method('getParams')->willReturn([
            'durationMinutes' => '60',
            'description'     => 'Planning',
        ]);

        $link = new TimeLink();
        $link->setObjectUuid('obj-uuid');
        $link->setDurationMinutes(60);

        $this->timeEntryService->method('logTime')->willReturn($link);

        $result = $this->controller->create('reg', 'schema', 'obj-uuid');

        $this->assertSame(201, $result->getStatus());
    }//end testCreateReturns201OnSuccess()

    public function testCreateReturns400OnInvalidDuration(): void
    {
        $this->timeEntryService->method('isBackendAvailable')->willReturn(true);
        $this->objectService->method('getObject')->willReturn($this->makeObject());

        $this->request->method('getParams')->willReturn(['durationMinutes' => '0']);

        $this->timeEntryService->method('logTime')
            ->willThrowException(new \InvalidArgumentException('Duration must be at least 1 minute.'));

        $result = $this->controller->create('reg', 'schema', 'obj-uuid');

        $this->assertSame(400, $result->getStatus());
    }//end testCreateReturns400OnInvalidDuration()

    public function testDestroyReturns404WhenEntryNotFound(): void
    {
        $this->timeEntryService->method('isBackendAvailable')->willReturn(true);
        $this->objectService->method('getObject')->willReturn($this->makeObject());
        $this->timeEntryService->method('deleteEntry')
            ->willThrowException(new Exception('Time entry not found.'));

        $result = $this->controller->destroy('reg', 'schema', 'obj-uuid', '99');

        $this->assertSame(404, $result->getStatus());
    }//end testDestroyReturns404WhenEntryNotFound()

    public function testDestroyReturnsSuccessOnDeletion(): void
    {
        $this->timeEntryService->method('isBackendAvailable')->willReturn(true);
        $this->objectService->method('getObject')->willReturn($this->makeObject());
        $this->timeEntryService->method('deleteEntry');

        $result = $this->controller->destroy('reg', 'schema', 'obj-uuid', '5');

        $this->assertSame(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }//end testDestroyReturnsSuccessOnDeletion()
}//end class
