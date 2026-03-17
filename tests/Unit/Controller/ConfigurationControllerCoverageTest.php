<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ConfigurationController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\NotificationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage tests for ConfigurationController — targets uncovered branches.
 */
class ConfigurationControllerCoverageTest extends TestCase
{
    private ConfigurationController $controller;
    private IRequest&MockObject $request;
    private ConfigurationMapper&MockObject $configurationMapper;
    private ConfigurationService&MockObject $configurationService;
    private GitHubHandler&MockObject $githubHandler;
    private GitLabHandler&MockObject $gitlabHandler;
    private NotificationService&MockObject $notificationService;
    private LoggerInterface&MockObject $logger;
    private IAppManager&MockObject $appManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->githubHandler = $this->createMock(GitHubHandler::class);
        $this->gitlabHandler = $this->createMock(GitLabHandler::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ConfigurationController(
            'openregister',
            $this->request,
            $this->configurationMapper,
            $this->configurationService,
            $this->notificationService,
            $this->githubHandler,
            $this->gitlabHandler,
            $this->appManager,
            $this->logger
        );
    }

    // =========================================================================
    // checkVersion — remote version null path
    // =========================================================================

    public function testCheckVersionRemoteVersionNull(): void
    {
        $config = $this->createMock(Configuration::class);
        $this->configurationMapper->method('find')->with(1)->willReturn($config);
        $this->configurationService->method('checkRemoteVersion')->willReturn(null);

        $result = $this->controller->checkVersion(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Could not check remote version', $data['error']);
    }

    public function testCheckVersionGenericException(): void
    {
        $config = $this->createMock(Configuration::class);
        $this->configurationMapper->method('find')->with(1)->willReturn($config);
        $this->configurationService->method('checkRemoteVersion')
            ->willThrowException(new \Exception('Unknown error'));

        $result = $this->controller->checkVersion(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to check version', $data['error']);
    }

    // =========================================================================
    // preview — returns JSONResponse directly vs array
    // =========================================================================

    public function testPreviewReturnsJsonResponseDirectly(): void
    {
        $config = $this->createMock(Configuration::class);
        $this->configurationMapper->method('find')->with(1)->willReturn($config);

        $jsonResponse = new JSONResponse(['preview' => 'data'], 200);
        $this->configurationService->method('previewConfigurationChanges')
            ->willReturn($jsonResponse);

        $result = $this->controller->preview(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(['preview' => 'data'], $result->getData());
    }

    public function testPreviewReturnsArray(): void
    {
        $config = $this->createMock(Configuration::class);
        $this->configurationMapper->method('find')->with(1)->willReturn($config);

        $this->configurationService->method('previewConfigurationChanges')
            ->willReturn(['changes' => ['register1']]);

        $result = $this->controller->preview(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('changes', $data);
    }

    // =========================================================================
    // export — includeObjects variations
    // =========================================================================

    public function testExportWithIncludeObjectsTrue(): void
    {
        $config = $this->createMock(Configuration::class);
        $this->configurationMapper->method('find')->with(1)->willReturn($config);

        $this->request->method('getParams')->willReturn(['includeObjects' => true]);
        $this->configurationService->expects($this->once())
            ->method('exportConfig')
            ->with($config, true)
            ->willReturn(['registers' => [], 'schemas' => [], 'objects' => [['id' => 1]]]);

        $result = $this->controller->export(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testExportWithIncludeObjectsFalse(): void
    {
        $config = $this->createMock(Configuration::class);
        $this->configurationMapper->method('find')->with(1)->willReturn($config);

        $this->request->method('getParams')->willReturn(['includeObjects' => false]);
        $this->configurationService->expects($this->once())
            ->method('exportConfig')
            ->with($config, false)
            ->willReturn(['registers' => [], 'schemas' => []]);

        $result = $this->controller->export(1);

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // discover — gitlab path
    // =========================================================================

    public function testDiscoverGitLabPath(): void
    {
        $this->request->method('getParams')->willReturn([
            'source' => 'gitlab',
            '_search' => 'openregister',
            'page' => 1,
        ]);
        $this->gitlabHandler->method('searchConfigurations')
            ->willReturn(['results' => [['name' => 'config1']]]);

        $result = $this->controller->discover();

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // getGitHubRepositories — with page/per_page params
    // =========================================================================

    public function testGetGitHubRepositoriesWithPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            'page' => 2,
            'per_page' => 50,
        ]);
        $this->githubHandler->expects($this->once())
            ->method('getRepositories')
            ->with(2, 50)
            ->willReturn([['name' => 'repo1']]);

        $result = $this->controller->getGitHubRepositories();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['repositories']);
    }

    public function testGetGitHubRepositoriesDefaultPagination(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->githubHandler->expects($this->once())
            ->method('getRepositories')
            ->with(1, 100)
            ->willReturn([]);

        $result = $this->controller->getGitHubRepositories();

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // getGitHubConfigurations — with branch param
    // =========================================================================

    public function testGetGitHubConfigurationsMissingOwnerAndRepo(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->getGitHubConfigurations();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testGetGitHubConfigurationsWithBranch(): void
    {
        $this->request->method('getParams')->willReturn([
            'owner' => 'conduction',
            'repo' => 'openregister',
            'branch' => 'develop',
        ]);
        $this->githubHandler->expects($this->once())
            ->method('listConfigurationFiles')
            ->with('conduction', 'openregister', 'develop')
            ->willReturn([['path' => '.openregister/config.json']]);

        $result = $this->controller->getGitHubConfigurations();

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // getGitLabBranches / getGitLabConfigurations — missing params
    // =========================================================================

    public function testGetGitLabBranchesMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->getGitLabBranches();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testGetGitLabConfigurationsMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->getGitLabConfigurations();

        $this->assertEquals(400, $result->getStatus());
    }
}
