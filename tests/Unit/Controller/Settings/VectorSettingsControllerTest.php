<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\VectorSettingsController;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VectorSettingsControllerTest extends TestCase
{
    private VectorSettingsController $controller;
    private IRequest&MockObject $request;
    private VectorizationService&MockObject $vectorizationService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->vectorizationService = $this->createMock(VectorizationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new VectorSettingsController(
            'openregister',
            $this->request,
            $this->vectorizationService,
            $this->logger
        );
    }

    public function testControllerInstantiation(): void
    {
        $this->assertInstanceOf(VectorSettingsController::class, $this->controller);
    }
}
