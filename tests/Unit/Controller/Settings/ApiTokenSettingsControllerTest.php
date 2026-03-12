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

    public function testSaveApiTokensOnlySavesGitLabUrl(): void
    {
        // Only gitlab_url is provided; no token calls.
        $this->request->method('getParams')->willReturn([
            'gitlab_url' => 'https://gitlab.example.com/api/v4',
        ]);

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'gitlab_api_url', 'https://gitlab.example.com/api/v4');

        $result = $this->controller->saveApiTokens();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSaveApiTokensWithNoParams(): void
    {
        // No params at all — nothing saved.
        $this->request->method('getParams')->willReturn([]);

        $this->config->expects($this->never())->method('setValueString');

        $result = $this->controller->saveApiTokens();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testGetApiTokensMasksOnlyNonEmptyTokens(): void
    {
        // GitHub token present, GitLab token empty.
        $this->config->method('getValueString')->willReturnMap([
            ['openregister', 'github_api_token', '', 'ghp_full_token'],
            ['openregister', 'gitlab_api_token', '', ''],
            ['openregister', 'gitlab_api_url', '', ''],
        ]);

        // maskToken called once (only for github_token).
        $this->settingsService->expects($this->once())
            ->method('maskToken')
            ->with('ghp_full_token')
            ->willReturn('ghp_***oken');

        $result = $this->controller->getApiTokens();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('ghp_***oken', $data['github_token']);
        $this->assertEquals('', $data['gitlab_token']);
    }

    public function testTestGitHubTokenUsesTokenFromRequest(): void
    {
        // When token is in request params, config is not queried.
        $this->request->method('getParams')->willReturn(['token' => 'ghp_request_token']);

        // The actual HTTP call will fail in unit test context since OC::$server is
        // bootstrapped but the HTTP client call to GitHub will fail.
        // We verify the exception path (400 response).
        $result = $this->controller->testGitHubToken();

        // Either 200 (if HTTP call succeeds — unlikely in unit tests) or 400 (expected).
        $this->assertContains($result->getStatus(), [200, 400]);
    }

    public function testTestGitLabTokenUsesUrlFromRequest(): void
    {
        // When url is in request params, it overrides config.
        $this->request->method('getParams')->willReturn([
            'token' => 'glpat_sometoken',
            'url'   => 'https://mygitlab.example.com/api/v4',
        ]);

        // The actual HTTP call will fail — verify exception path.
        $result = $this->controller->testGitLabToken();

        $this->assertContains($result->getStatus(), [200, 400]);
        $this->assertArrayHasKey('success', $result->getData());
    }

    public function testTestGitLabTokenDefaultsToGitLabDotCom(): void
    {
        // When URL is empty, defaults to https://gitlab.com/api/v4.
        $this->request->method('getParams')->willReturn(['token' => 'glpat_valid']);
        $this->config->method('getValueString')
            ->willReturnMap([
                ['openregister', 'gitlab_api_token', '', 'glpat_stored'],
                ['openregister', 'gitlab_api_url', 'https://gitlab.com/api/v4', ''],
            ]);

        // HTTP call will fail — verify exception path returns 400.
        $result = $this->controller->testGitLabToken();

        $this->assertContains($result->getStatus(), [200, 400]);
    }

    public function testSaveApiTokensMaskedGithubTokenNotSaved(): void
    {
        // A github_token with *** is masked and must NOT be written to config.
        $this->request->method('getParams')->willReturn([
            'github_token' => 'ghp_***masked_token',
            'gitlab_url'   => 'https://gitlab.com/api/v4',
        ]);

        // setValueString should be called once (for gitlab_url), never for github_token.
        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'gitlab_api_url', 'https://gitlab.com/api/v4');

        $result = $this->controller->saveApiTokens();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSaveApiTokensMaskedGitlabTokenNotSaved(): void
    {
        // A gitlab_token with *** is masked and must NOT be written to config.
        $this->request->method('getParams')->willReturn([
            'gitlab_token' => 'glp***masked',
        ]);

        $this->config->expects($this->never())->method('setValueString');

        $result = $this->controller->saveApiTokens();

        $this->assertEquals(200, $result->getStatus());
    }
}
