<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\McpServerController;
use OCA\OpenRegister\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class McpServerControllerTest extends TestCase
{
    private McpServerController $controller;
    private IRequest&MockObject $request;
    private McpProtocolService&MockObject $protocolService;
    private McpToolsService&MockObject $toolsService;
    private McpResourcesService&MockObject $resourcesService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->protocolService = $this->createMock(McpProtocolService::class);
        $this->toolsService = $this->createMock(McpToolsService::class);
        $this->resourcesService = $this->createMock(McpResourcesService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new McpServerController(
            'openregister',
            $this->request,
            $this->protocolService,
            $this->toolsService,
            $this->resourcesService,
            $this->logger,
            'admin'
        );
    }

    /**
     * Note: The handle() method reads from php://input which is not mockable in unit tests.
     * We test the controller instantiation and verify it extends Controller.
     * Integration tests would be needed to test handle() fully.
     */
    public function testControllerInstantiation(): void
    {
        $this->assertInstanceOf(McpServerController::class, $this->controller);
    }

    /**
     * The handle method reads php://input directly, so we test edge cases
     * by verifying the controller structure and that dependencies are wired.
     */
    public function testHandleWithInvalidJson(): void
    {
        // handle() reads from php://input which we cannot mock in unit tests
        // This test verifies the controller can be called without error setup
        $result = $this->controller->handle();

        // When php://input is empty, json_decode returns null -> parse error
        $this->assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals(-32700, $data['error']['code']);
    }
}
