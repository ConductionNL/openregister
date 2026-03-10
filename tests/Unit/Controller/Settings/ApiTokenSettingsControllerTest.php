<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\ApiTokenSettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiTokenSettingsControllerTest extends TestCase
{
    private ApiTokenSettingsController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private SettingsService&MockObject $settingsService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ApiTokenSettingsController(
            'openregister',
            $this->request,
            $this->config,
            $this->settingsService,
            $this->logger
        );
    }

    public function testGetApiTokensSuccess(): void
    {
        $this->config->method('getValueString')->willReturnMap([
            ['openregister', 'github_api_token', '', 'ghp_abc123'],
            ['openregister', 'gitlab_api_token', '', 'glpat_xyz789'],
            ['openregister', 'gitlab_api_url', '', 'https://gitlab.com/api/v4'],
        ]);

        $this->settingsService->method('maskToken')
            ->willReturnOnConsecutiveCalls('ghp_***123', 'glp***789');

        $result = $this->controller->getApiTokens();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('ghp_***123', $data['github_token']);
        $this->assertEquals('glp***789', $data['gitlab_token']);
        $this->assertEquals('https://gitlab.com/api/v4', $data['gitlab_url']);
    }

    public function testGetApiTokensEmptyTokens(): void
    {
        $this->config->method('getValueString')->willReturn('');

        $result = $this->controller->getApiTokens();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('', $data['github_token']);
        $this->assertEquals('', $data['gitlab_token']);
    }

    public function testGetApiTokensException(): void
    {
        $this->config->method('getValueString')
            ->willThrowException(new \Exception('Config error'));

        $result = $this->controller->getApiTokens();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testSaveApiTokensSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'github_token' => 'ghp_newtoken',
            'gitlab_token' => 'glpat_newtoken',
            'gitlab_url' => 'https://custom.gitlab.com/api/v4',
        ]);

        $result = $this->controller->saveApiTokens();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testSaveApiTokensSkipsMaskedTokens(): void
    {
        $this->request->method('getParams')->willReturn([
            'github_token' => 'ghp_***masked',
            'gitlab_token' => 'glp***masked',
        ]);

        // setValueString should NOT be called for masked tokens
        $this->config->expects($this->never())->method('setValueString');

        $result = $this->controller->saveApiTokens();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSaveApiTokensException(): void
    {
        $this->request->method('getParams')->willReturn([
            'github_token' => 'ghp_newtoken',
        ]);
        $this->config->method('setValueString')
            ->willThrowException(new \Exception('Save failed'));

        $result = $this->controller->saveApiTokens();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testTestGitHubTokenEmptyToken(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->config->method('getValueString')->willReturn('');

        $result = $this->controller->testGitHubToken();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testTestGitLabTokenEmptyToken(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->config->method('getValueString')->willReturn('');

        $result = $this->controller->testGitLabToken();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }
}
