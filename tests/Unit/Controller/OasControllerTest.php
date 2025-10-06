<?php

declare(strict_types=1);

/**
 * OasControllerTest
 * 
 * Unit tests for the OasController
 *
 * @category   Test
 * @package    OCA\OpenRegister\Tests\Unit\Controller
 * @author     Conduction.nl <info@conduction.nl>
 * @copyright  Conduction.nl 2024
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version    1.0.0
 * @link       https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OasController;
use OCA\OpenRegister\Service\OasService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the OasController
 *
 * This test class covers all functionality of the OasController
 * including OpenAPI specification generation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class OasControllerTest extends TestCase
{
    /**
     * The OasController instance being tested
     *
     * @var OasController
     */
    private OasController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock OAS service
     *
     * @var MockObject|OasService
     */
    private MockObject $oasService;

    /**
     * Set up test environment before each test
     *
     * This method initializes all mocks and the controller instance
     * for testing purposes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects for all dependencies
        $this->request = $this->createMock(IRequest::class);
        $this->oasService = $this->createMock(OasService::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new OasController(
            'openregister',
            $this->request,
            $this->oasService
        );
    }

    /**
     * Test generateAll method with successful OAS generation for all registers
     *
     * @return void
     */
    public function testGenerateAllSuccessful(): void
    {
        $oasSpec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'OpenRegister API',
                'version' => '1.0.0'
            ],
            'paths' => []
        ];

        $this->oasService
            ->expects($this->once())
            ->method('createOas')
            ->with(null)
            ->willReturn($oasSpec);

        $response = $this->controller->generateAll();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($oasSpec, $response->getData());
    }

    /**
     * Test generateAll method with service error
     *
     * @return void
     */
    public function testGenerateAllWithError(): void
    {
        $this->oasService
            ->expects($this->once())
            ->method('createOas')
            ->with(null)
            ->willThrowException(new \Exception('Service error'));

        $response = $this->controller->generateAll();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Service error', $data['error']);
    }

    /**
     * Test generate method with successful OAS generation for specific register
     *
     * @return void
     */
    public function testGenerateSuccessful(): void
    {
        $registerId = 'test-register-123';
        $oasSpec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Test Register API',
                'version' => '1.0.0'
            ],
            'paths' => [
                '/objects' => [
                    'get' => [
                        'summary' => 'List objects'
                    ]
                ]
            ]
        ];

        $this->oasService
            ->expects($this->once())
            ->method('createOas')
            ->with($registerId)
            ->willReturn($oasSpec);

        $response = $this->controller->generate($registerId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($oasSpec, $response->getData());
    }

    /**
     * Test generate method when register not found
     *
     * @return void
     */
    public function testGenerateNotFound(): void
    {
        $registerId = 'non-existent-register';

        $this->oasService
            ->expects($this->once())
            ->method('createOas')
            ->with($registerId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Register not found'));

        $response = $this->controller->generate($registerId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Register not found', $data['error']);
    }

    /**
     * Test generate method with service error
     *
     * @return void
     */
    public function testGenerateWithError(): void
    {
        $registerId = 'test-register-123';

        $this->oasService
            ->expects($this->once())
            ->method('createOas')
            ->with($registerId)
            ->willThrowException(new \Exception('Service error'));

        $response = $this->controller->generate($registerId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Service error', $data['error']);
    }
}