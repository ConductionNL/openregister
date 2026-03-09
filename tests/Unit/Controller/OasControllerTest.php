<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\OasController;
use OCA\OpenRegister\Service\OasService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OasController
 *
 * @package Unit\Controller
 */
class OasControllerTest extends TestCase
{
    private OasController $controller;
    private IRequest&MockObject $request;
    private OasService&MockObject $oasService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->oasService = $this->createMock(OasService::class);

        $this->controller = new OasController(
            'openregister',
            $this->request,
            $this->oasService
        );
    }

    public function testGenerateAllReturnsOasData(): void
    {
        $oasData = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'OpenRegister API'],
            'paths' => [],
        ];

        $this->oasService->method('createOas')->willReturn($oasData);

        $result = $this->controller->generateAll();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $this->assertSame($oasData, $result->getData());
    }

    public function testGenerateAllReturns500OnException(): void
    {
        $this->oasService->method('createOas')
            ->willThrowException(new Exception('OAS generation failed'));

        $result = $this->controller->generateAll();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('OAS generation failed', $data['error']);
    }

    public function testGenerateReturnsOasForRegister(): void
    {
        $oasData = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Register API'],
        ];

        $this->oasService->method('createOas')
            ->with($this->equalTo('my-register'))
            ->willReturn($oasData);

        $result = $this->controller->generate('my-register');

        $this->assertSame(200, $result->getStatus());
        $this->assertSame($oasData, $result->getData());
    }

    public function testGenerateReturns500OnException(): void
    {
        $this->oasService->method('createOas')
            ->willThrowException(new Exception('Register not found'));

        $result = $this->controller->generate('nonexistent');

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Register not found', $data['error']);
    }
}
