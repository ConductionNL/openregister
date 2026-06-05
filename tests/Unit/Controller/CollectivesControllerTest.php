<?php

/**
 * Unit tests for CollectivesController.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\CollectivesController;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CollectivesPageService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CollectivesControllerTest extends TestCase
{

    private IRequest&MockObject $request;

    private CollectivesPageService&MockObject $collectivesService;

    private ObjectService&MockObject $objectService;

    private LoggerInterface&MockObject $logger;

    private CollectivesController $controller;

    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->collectivesService = $this->createMock(CollectivesPageService::class);
        $this->objectService      = $this->createMock(ObjectService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new CollectivesController(
            appName: 'openregister',
            request: $this->request,
            collectivesPageService: $this->collectivesService,
            objectService: $this->objectService,
            logger: $this->logger,
        );
    }//end setUp()

    private function mockObject(string $uuid='obj-uuid'): ObjectEntity
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getUuid')->willReturn($uuid);
        return $obj;
    }//end mockObject()

    public function testListCollectivesReturns501WhenAppNotAvailable(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(false);

        $response = $this->controller->listCollectives();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(501, $response->getStatus());
    }//end testListCollectivesReturns501WhenAppNotAvailable()

    public function testListCollectivesReturnsResultsWhenAvailable(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(true);
        $this->collectivesService
            ->method('listCollectives')
            ->willReturn([['id' => 1, 'name' => 'gemeentehandboek']]);

        $response = $this->controller->listCollectives();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(1, $data['total']);
        $this->assertCount(1, $data['results']);
    }//end testListCollectivesReturnsResultsWhenAvailable()

    public function testListPagesReturns501WhenAppNotAvailable(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(false);

        $response = $this->controller->listPages('somecollective');

        $this->assertSame(501, $response->getStatus());
    }//end testListPagesReturns501WhenAppNotAvailable()

    public function testIndexReturns501WhenAppNotAvailable(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(false);

        $response = $this->controller->index('reg', 'schema', 'obj-id');

        $this->assertSame(501, $response->getStatus());
    }//end testIndexReturns501WhenAppNotAvailable()

    public function testIndexReturns404WhenObjectNotFound(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(true);
        $this->objectService->method('getObject')->willReturn(null);

        $response = $this->controller->index('reg', 'schema', 'unknown-id');

        $this->assertSame(404, $response->getStatus());
    }//end testIndexReturns404WhenObjectNotFound()

    public function testIndexReturnsLinks(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(true);
        $obj = $this->mockObject();
        $this->objectService->method('getObject')->willReturn($obj);
        $this->collectivesService
            ->method('getLinksForObject')
            ->with('obj-uuid')
            ->willReturn(['results' => [], 'total' => 0]);

        $response = $this->controller->index('reg', 'schema', 'obj-uuid');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(0, $data['total']);
    }//end testIndexReturnsLinks()

    public function testCreateReturns400WhenCollectiveNameMissing(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(true);
        $obj = $this->mockObject();
        $this->objectService->method('getObject')->willReturn($obj);
        $this->request->method('getParams')->willReturn(['pageId' => 1]);

        $response = $this->controller->create('reg', 'schema', 'obj-uuid');

        $this->assertSame(400, $response->getStatus());
    }//end testCreateReturns400WhenCollectiveNameMissing()

    public function testCreateReturns201OnSuccess(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(true);
        $obj = $this->mockObject();
        $this->objectService->method('getObject')->willReturn($obj);
        $this->request->method('getParams')->willReturn(
                [
                    'collectiveName' => 'mywiki',
                    'pageId'         => 7,
                    'pageTitle'      => 'Intro',
                ]
                );

        $link = new CollectiveLink();
        $link->setCollectiveName('mywiki');
        $link->setPageId(7);

        $this->collectivesService
            ->method('linkPage')
            ->willReturn($link);

        $response = $this->controller->create('reg', 'schema', 'obj-uuid');

        $this->assertSame(201, $response->getStatus());
    }//end testCreateReturns201OnSuccess()

    public function testCreateReturns409OnDuplicate(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(true);
        $obj = $this->mockObject();
        $this->objectService->method('getObject')->willReturn($obj);
        $this->request->method('getParams')->willReturn(
                [
                    'collectiveName' => 'mywiki',
                    'pageId'         => 7,
                ]
                );

        $this->collectivesService
            ->method('linkPage')
            ->willThrowException(new Exception('Page already linked', 409));

        $response = $this->controller->create('reg', 'schema', 'obj-uuid');

        $this->assertSame(409, $response->getStatus());
    }//end testCreateReturns409OnDuplicate()

    public function testDestroyReturns404WhenLinkNotFound(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(true);
        $obj = $this->mockObject();
        $this->objectService->method('getObject')->willReturn($obj);
        $this->collectivesService
            ->method('unlinkPage')
            ->willThrowException(new Exception('Not found', 404));

        $response = $this->controller->destroy('reg', 'schema', 'obj-uuid', '99');

        $this->assertSame(404, $response->getStatus());
    }//end testDestroyReturns404WhenLinkNotFound()

    public function testDestroyReturnsSuccessOnValidLink(): void
    {
        $this->collectivesService->method('isCollectivesAvailable')->willReturn(true);
        $obj = $this->mockObject();
        $this->objectService->method('getObject')->willReturn($obj);
        $this->collectivesService
            ->expects($this->once())
            ->method('unlinkPage')
            ->with(5);

        $response = $this->controller->destroy('reg', 'schema', 'obj-uuid', '5');

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }//end testDestroyReturnsSuccessOnValidLink()
}//end class
