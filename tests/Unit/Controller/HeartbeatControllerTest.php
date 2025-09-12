<?php

declare(strict_types=1);

/**
 * HeartbeatControllerTest
 * 
 * Unit tests for the HeartbeatController
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

use OCA\OpenRegister\Controller\HeartbeatController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the HeartbeatController
 *
 * This test class covers all functionality of the HeartbeatController
 * including heartbeat requests to prevent connection timeouts.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class HeartbeatControllerTest extends TestCase
{
    /**
     * The HeartbeatController instance being tested
     *
     * @var HeartbeatController
     */
    private HeartbeatController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

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

        // Initialize the controller with mocked dependencies
        $this->controller = new HeartbeatController(
            'openregister',
            $this->request
        );
    }

    /**
     * Test heartbeat method returns success response
     *
     * @return void
     */
    public function testHeartbeatReturnsSuccess(): void
    {
        $response = $this->controller->heartbeat();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('alive', $data['status']);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('message', $data);
    }
}