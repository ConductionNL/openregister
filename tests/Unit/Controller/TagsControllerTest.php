<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TagsController;
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TagsControllerTest extends TestCase
{
    private TagsController $controller;
    private IRequest&MockObject $request;
    private ObjectService&MockObject $objectService;
    private FileService&MockObject $fileService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->fileService = $this->createMock(FileService::class);

        $this->controller = new TagsController(
            'openregister',
            $this->request,
            $this->objectService,
            $this->fileService,
            $this->createMock(TaggingHandler::class)
        );
    }

    public function testGetAllTagsReturnsJsonResponse(): void
    {
        $tags = ['tag1', 'tag2', 'tag3'];

        $this->fileService
            ->expects($this->once())
            ->method('getAllTags')
            ->willReturn($tags);

        $result = $this->controller->getAllTags();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals($tags, $result->getData());
        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetAllTagsReturnsEmptyArray(): void
    {
        $this->fileService
            ->expects($this->once())
            ->method('getAllTags')
            ->willReturn([]);

        $result = $this->controller->getAllTags();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals([], $result->getData());
        $this->assertEquals(200, $result->getStatus());
    }
}
