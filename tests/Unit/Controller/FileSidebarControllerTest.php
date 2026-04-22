<?php

/**
 * FileSidebarController Test
 *
 * Unit tests for the FileSidebarController.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileSidebarController;
use OCA\OpenRegister\Service\FileSidebarService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for FileSidebarController.
 *
 * @package OCA\OpenRegister\Tests\Unit\Controller
 */
class FileSidebarControllerTest extends TestCase
{
    private FileSidebarController $controller;
    private FileSidebarService&MockObject $fileSidebarService;
    private IRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request             = $this->createMock(IRequest::class);
        $this->fileSidebarService  = $this->createMock(FileSidebarService::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->controller = new FileSidebarController(
            'openregister',
            $this->request,
            $this->fileSidebarService,
            $this->logger
        );
    }//end setUp()

    /**
     * Test getObjectsForFile returns success response with objects.
     *
     * @return void
     */
    public function testGetObjectsForFileReturnsSuccess(): void
    {
        $objects = [
            [
                'uuid'     => 'abc-123',
                'title'    => 'Test Object',
                'register' => ['id' => 1, 'title' => 'My Register'],
                'schema'   => ['id' => 2, 'title' => 'My Schema'],
            ],
        ];

        $this->fileSidebarService->expects($this->once())
            ->method('getObjectsForFile')
            ->with(42)
            ->willReturn($objects);

        $response = $this->controller->getObjectsForFile(42);

        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('abc-123', $data['data'][0]['uuid']);
    }//end testGetObjectsForFileReturnsSuccess()

    /**
     * Test getObjectsForFile returns 500 on exception.
     *
     * @return void
     */
    public function testGetObjectsForFileReturns500OnException(): void
    {
        $this->fileSidebarService->method('getObjectsForFile')
            ->willThrowException(new \Exception('Service failure'));

        $response = $this->controller->getObjectsForFile(42);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }//end testGetObjectsForFileReturns500OnException()

    /**
     * Test getExtractionStatus returns success response.
     *
     * @return void
     */
    public function testGetExtractionStatusReturnsSuccess(): void
    {
        $status = [
            'fileId'           => 99,
            'extractionStatus' => 'completed',
            'chunkCount'       => 5,
            'entityCount'      => 3,
            'riskLevel'        => 'medium',
            'extractedAt'      => '2024-01-01T00:00:00+00:00',
            'entities'         => [['type' => 'PERSON', 'count' => 3]],
            'anonymized'       => false,
            'anonymizedAt'     => null,
            'anonymizedFileId' => null,
        ];

        $this->fileSidebarService->expects($this->once())
            ->method('getExtractionStatus')
            ->with(99)
            ->willReturn($status);

        $response = $this->controller->getExtractionStatus(99);

        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(99, $data['data']['fileId']);
        $this->assertSame('completed', $data['data']['extractionStatus']);
    }//end testGetExtractionStatusReturnsSuccess()

    /**
     * Test getExtractionStatus returns 500 on exception.
     *
     * @return void
     */
    public function testGetExtractionStatusReturns500OnException(): void
    {
        $this->fileSidebarService->method('getExtractionStatus')
            ->willThrowException(new \RuntimeException('DB down'));

        $response = $this->controller->getExtractionStatus(99);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
    }//end testGetExtractionStatusReturns500OnException()
}//end class
