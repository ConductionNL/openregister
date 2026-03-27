<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\ValidationSettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ValidationSettingsControllerTest extends TestCase
{
    private ValidationSettingsController $controller;
    private IRequest&MockObject $request;
    private SettingsService&MockObject $settingsService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ValidationSettingsController(
            'openregister',
            $this->request,
            $this->settingsService,
            $this->logger
        );
    }

    public function testValidateAllObjectsSuccess(): void
    {
        $validationResults = [
            'total_objects' => 10,
            'valid_objects' => 8,
            'invalid_objects' => 2,
        ];
        $this->settingsService->method('validateAllObjects')
            ->willReturn($validationResults);

        $result = $this->controller->validateAllObjects();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($validationResults, $result->getData());
    }

    public function testValidateAllObjectsException(): void
    {
        $this->settingsService->method('validateAllObjects')
            ->willThrowException(new \Exception('Validation failed'));

        $result = $this->controller->validateAllObjects();

        $this->assertEquals(500, $result->getStatus());
        $this->assertEquals(0, $result->getData()['total_objects']);
    }

    public function testMassValidateObjectsSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 50],
                ['mode', 'serial', 'serial'],
                ['collectErrors', false, false],
            ]);
        $results = ['total_objects' => 100, 'processed_objects' => 100];
        $this->settingsService->method('massValidateObjects')
            ->willReturn($results);

        $result = $this->controller->massValidateObjects();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testMassValidateObjectsInvalidArgument(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 50],
                ['mode', 'serial', 'invalid_mode'],
                ['collectErrors', false, false],
            ]);
        $this->settingsService->method('massValidateObjects')
            ->willThrowException(new \InvalidArgumentException('Invalid mode'));

        $result = $this->controller->massValidateObjects();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testMassValidateObjectsException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 1000],
                ['mode', 'serial', 'serial'],
                ['collectErrors', false, false],
            ]);
        $this->settingsService->method('massValidateObjects')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->massValidateObjects();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testPredictMassValidationMemorySuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 1000],
            ]);
        $this->settingsService->method('convertToBytes')->willReturn(536870912);
        $this->settingsService->method('formatBytes')
            ->willReturn('50 MB');

        $result = $this->controller->predictMassValidationMemory();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('prediction_safe', $data);
    }

    public function testPredictMassValidationMemoryException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
            ]);
        $this->settingsService->method('convertToBytes')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->predictMassValidationMemory();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }
}
