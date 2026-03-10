<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ConfigurationController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigurationControllerTest extends TestCase
{
    private ConfigurationController $controller;
    private IRequest&MockObject $request;
    private ConfigurationMapper&MockObject $configurationMapper;
    private ConfigurationService&MockObject $configurationService;
    private NotificationService&MockObject $notificationService;
    private GitHubHandler&MockObject $githubHandler;
    private GitLabHandler&MockObject $gitlabHandler;
    private IAppManager&MockObject $appManager;
    private LoggerInterface&MockObject $logger;

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

    private function createRealConfiguration(): Configuration
    {
        $config = new Configuration();
        $ref = new \ReflectionClass($config);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($config, 1);
        $config->setTitle('Test Config');
        $config->setIsLocal(true);
        return $config;
    }

    public function testIndexSuccess(): void
    {
        $configs = [$this->createRealConfiguration()];
        $this->configurationMapper->method('findAll')->willReturn($configs);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testIndexException(): void
    {
        $this->configurationMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testShowSuccess(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'title' => 'New Config',
        ]);
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('insert')->willReturn($config);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configurationMapper->method('insert')
            ->willThrowException(new \Exception('Insert failed'));

        $result = $this->controller->create();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateSuccess(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->configurationMapper->method('update')->willReturn($config);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->update(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);

        $result = $this->controller->destroy(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testDestroyNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testEnrichDetailsMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->enrichDetails();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testEnrichDetailsGithubSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'source' => 'github',
            'owner' => 'org',
            'repo' => 'repo',
            'path' => 'path/to/file',
        ]);
        $details = ['content' => 'data'];
        $this->githubHandler->method('enrichConfigurationDetails')->willReturn($details);

        $result = $this->controller->enrichDetails();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCheckVersionNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->checkVersion(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCheckVersionSuccess(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->configurationService->method('checkRemoteVersion')->willReturn('1.0.1');
        $this->configurationService->method('compareVersions')->willReturn([
            'local' => '1.0.0',
            'remote' => '1.0.1',
        ]);

        $result = $this->controller->checkVersion(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDiscoverInvalidSource(): void
    {
        $this->request->method('getParams')->willReturn(['source' => 'bitbucket']);

        $result = $this->controller->discover();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testDiscoverGithubSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['source' => 'github']);
        $searchResults = ['results' => [], 'total_count' => 0];
        $this->githubHandler->method('searchConfigurations')->willReturn($searchResults);

        $result = $this->controller->discover();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetGitHubBranchesMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->getGitHubBranches();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testGetGitHubBranchesSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
        ]);
        $this->githubHandler->method('getBranches')->willReturn(['main', 'dev']);

        $result = $this->controller->getGitHubBranches();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(['main', 'dev'], $result->getData()['branches']);
    }

    public function testExportSuccess(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $exportData = ['info' => ['title' => 'Test']];
        $this->configurationService->method('exportConfig')->willReturn($exportData);

        $result = $this->controller->export(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testImportSuccess(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn(['selection' => []]);
        $this->configurationService->method('importConfigurationWithSelection')
            ->willReturn(['registers' => [], 'schemas' => [], 'objects' => []]);

        $result = $this->controller->import(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testPreviewNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->preview(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testPreviewSuccess(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->configurationService->method('previewConfigurationChanges')
            ->willReturn(['changes' => []]);

        $result = $this->controller->preview(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testPreviewException(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->configurationService->method('previewConfigurationChanges')
            ->willThrowException(new \Exception('Preview failed'));

        $result = $this->controller->preview(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testShowException(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->show(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateException(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->configurationMapper->method('update')
            ->willThrowException(new \Exception('Update failed'));

        $result = $this->controller->update(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDestroyException(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->configurationMapper->method('delete')
            ->willThrowException(new \Exception('Delete failed'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testEnrichDetailsGitlabReturnsNull(): void
    {
        $this->request->method('getParams')->willReturn([
            'source' => 'gitlab',
            'owner' => 'org',
            'repo' => 'repo',
            'path' => 'path/to/file',
        ]);

        $result = $this->controller->enrichDetails();

        // GitLab enrichment is not yet implemented, so details will be null => 404
        $this->assertEquals(404, $result->getStatus());
    }

    public function testEnrichDetailsException(): void
    {
        $this->request->method('getParams')->willReturn([
            'source' => 'github',
            'owner' => 'org',
            'repo' => 'repo',
            'path' => 'path/to/file',
        ]);
        $this->githubHandler->method('enrichConfigurationDetails')
            ->willThrowException(new \Exception('API error'));

        $result = $this->controller->enrichDetails();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testCheckVersionReturnsNullRemoteVersion(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->configurationService->method('checkRemoteVersion')->willReturn(null);

        $result = $this->controller->checkVersion(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testCheckVersionException(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->configurationService->method('checkRemoteVersion')
            ->willThrowException(new \Exception('Version check failed'));

        $result = $this->controller->checkVersion(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDiscoverGitlabSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['source' => 'gitlab']);
        $this->gitlabHandler->method('searchConfigurations')
            ->willReturn(['results' => [], 'total_count' => 0]);

        $result = $this->controller->discover();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDiscoverException(): void
    {
        $this->request->method('getParams')->willReturn(['source' => 'github']);
        $this->githubHandler->method('searchConfigurations')
            ->willThrowException(new \Exception('Search failed'));

        $result = $this->controller->discover();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetGitHubBranchesException(): void
    {
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
        ]);
        $this->githubHandler->method('getBranches')
            ->willThrowException(new \Exception('API error'));

        $result = $this->controller->getGitHubBranches();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetGitHubRepositoriesSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->githubHandler->method('getRepositories')
            ->willReturn([['name' => 'repo1']]);

        $result = $this->controller->getGitHubRepositories();

        $this->assertEquals(200, $result->getStatus());
        $this->assertArrayHasKey('repositories', $result->getData());
    }

    public function testGetGitHubRepositoriesException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->githubHandler->method('getRepositories')
            ->willThrowException(new \Exception('API error'));

        $result = $this->controller->getGitHubRepositories();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetGitHubConfigurationsMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->getGitHubConfigurations();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testGetGitHubConfigurationsSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
        ]);
        $this->githubHandler->method('listConfigurationFiles')
            ->willReturn([['name' => 'config.json']]);

        $result = $this->controller->getGitHubConfigurations();

        $this->assertEquals(200, $result->getStatus());
        $this->assertArrayHasKey('files', $result->getData());
    }

    public function testGetGitHubConfigurationsException(): void
    {
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
        ]);
        $this->githubHandler->method('listConfigurationFiles')
            ->willThrowException(new \Exception('API error'));

        $result = $this->controller->getGitHubConfigurations();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetGitLabBranchesMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->getGitLabBranches();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testGetGitLabBranchesSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'namespace' => 'org',
            'project' => 'proj',
        ]);
        $this->gitlabHandler->method('getProjectByPath')
            ->willReturn(['id' => 123]);
        $this->gitlabHandler->method('getBranches')
            ->willReturn(['main', 'dev']);

        $result = $this->controller->getGitLabBranches();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(['main', 'dev'], $result->getData()['branches']);
    }

    public function testGetGitLabBranchesException(): void
    {
        $this->request->method('getParams')->willReturn([
            'namespace' => 'org',
            'project' => 'proj',
        ]);
        $this->gitlabHandler->method('getProjectByPath')
            ->willThrowException(new \Exception('API error'));

        $result = $this->controller->getGitLabBranches();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetGitLabConfigurationsMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->getGitLabConfigurations();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testGetGitLabConfigurationsSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'namespace' => 'org',
            'project' => 'proj',
        ]);
        $this->gitlabHandler->method('getProjectByPath')
            ->willReturn(['id' => 123]);
        $this->gitlabHandler->method('listConfigurationFiles')
            ->willReturn([['name' => 'config.json']]);

        $result = $this->controller->getGitLabConfigurations();

        $this->assertEquals(200, $result->getStatus());
        $this->assertArrayHasKey('files', $result->getData());
    }

    public function testGetGitLabConfigurationsException(): void
    {
        $this->request->method('getParams')->willReturn([
            'namespace' => 'org',
            'project' => 'proj',
        ]);
        $this->gitlabHandler->method('getProjectByPath')
            ->willThrowException(new \Exception('API error'));

        $result = $this->controller->getGitLabConfigurations();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testExportNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->export(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testExportException(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn([]);
        $this->configurationService->method('exportConfig')
            ->willThrowException(new \Exception('Export failed'));

        $result = $this->controller->export(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testImportNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->import(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testImportException(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn(['selection' => []]);
        $this->configurationService->method('importConfigurationWithSelection')
            ->willThrowException(new \Exception('Import failed'));

        $result = $this->controller->import(1);

        $this->assertEquals(500, $result->getStatus());
    }

    // ── publishToGitHub tests ──────────────────────────────────────────

    public function testPublishToGitHubNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->publishToGitHub(999);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testPublishToGitHubNonLocalConfigReturns400(): void
    {
        $config = new Configuration();
        $ref = new \ReflectionClass($config);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($config, 2);
        $config->setTitle('Remote Config');
        $config->setIsLocal(false);

        $this->configurationMapper->method('find')->willReturn($config);

        $result = $this->controller->publishToGitHub(2);

        $this->assertEquals(400, $result->getStatus());
        $this->assertStringContainsString('local', $result->getData()['error']);
    }

    public function testPublishToGitHubMissingOwnerRepo(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn([
            'owner' => '',
            'repo' => '',
        ]);

        $result = $this->controller->publishToGitHub(1);

        $this->assertEquals(400, $result->getStatus());
        $this->assertStringContainsString('required', $result->getData()['error']);
    }

    public function testPublishToGitHubSuccess(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn([
            'owner' => 'testorg',
            'repo' => 'testrepo',
            'path' => 'configs/test.json',
            'branch' => 'main',
            'commitMessage' => 'Publish config',
        ]);
        $this->configurationService->method('exportConfig')->willReturn([
            'info' => ['title' => 'Test'],
        ]);
        $this->appManager->method('getAppVersion')->willReturn('0.2.10');
        $this->githubHandler->method('getFileSha')->willReturn('abc123sha');
        $this->githubHandler->method('publishConfiguration')->willReturn([
            'commit_sha' => 'newsha',
            'commit_url' => 'https://github.com/testorg/testrepo/commit/newsha',
            'file_url' => 'https://github.com/testorg/testrepo/blob/main/configs/test.json',
        ]);
        $this->githubHandler->method('getRepositoryInfo')->willReturn([
            'default_branch' => 'main',
        ]);

        $result = $this->controller->publishToGitHub(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('newsha', $data['commit_sha']);
    }

    public function testPublishToGitHubException(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn([
            'owner' => 'testorg',
            'repo' => 'testrepo',
            'path' => 'test.json',
        ]);
        $this->configurationService->method('exportConfig')
            ->willThrowException(new \Exception('Export failed'));

        $result = $this->controller->publishToGitHub(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testPublishToGitHubNonDefaultBranch(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn([
            'owner' => 'testorg',
            'repo' => 'testrepo',
            'path' => 'configs/test.json',
            'branch' => 'feature-branch',
        ]);
        $this->configurationService->method('exportConfig')->willReturn([
            'info' => ['title' => 'Test'],
        ]);
        $this->appManager->method('getAppVersion')->willReturn('0.2.10');
        $this->githubHandler->method('getFileSha')
            ->willThrowException(new \Exception('Not found'));
        $this->githubHandler->method('publishConfiguration')->willReturn([
            'commit_sha' => 'sha456',
            'commit_url' => 'https://github.com/testorg/testrepo/commit/sha456',
            'file_url' => 'https://github.com/testorg/testrepo/blob/feature-branch/configs/test.json',
        ]);
        $this->githubHandler->method('getRepositoryInfo')->willReturn([
            'default_branch' => 'main',
        ]);

        $result = $this->controller->publishToGitHub(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('main', $data['default_branch']);
        $this->assertStringContainsString('non-default', $data['indexing_note']);
    }

    public function testPublishToGitHubAutoGeneratedPath(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn([
            'owner' => 'testorg',
            'repo' => 'testrepo',
        ]);
        $this->configurationService->method('exportConfig')->willReturn([
            'info' => ['title' => 'Test'],
        ]);
        $this->appManager->method('getAppVersion')->willReturn('0.2.10');
        $this->githubHandler->method('getFileSha')
            ->willThrowException(new \Exception('Not found'));
        $this->githubHandler->method('publishConfiguration')->willReturn([
            'commit_sha' => 'sha789',
            'commit_url' => 'https://github.com/testorg/testrepo/commit/sha789',
            'file_url' => 'https://github.com/testorg/testrepo/blob/main/test_config_openregister.json',
        ]);
        $this->githubHandler->method('getRepositoryInfo')->willReturn([
            'default_branch' => 'main',
        ]);

        $result = $this->controller->publishToGitHub(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    // ── importFromGitHub tests ─────────────────────────────────────────

    public function testImportFromGitHubMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([
            'owner' => '',
            'repo' => '',
            'path' => '',
        ]);

        $result = $this->controller->importFromGitHub();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testImportFromGitHubSuccess(): void
    {
        $configData = [
            'info' => ['title' => 'Test Config', 'version' => '1.0.0'],
            'x-openregister' => ['app' => 'testapp'],
        ];
        $this->request->method('getParams')->willReturn([
            'owner' => 'testorg',
            'repo' => 'testrepo',
            'path' => 'config.json',
            'branch' => 'main',
        ]);
        $this->githubHandler->method('getFileContent')->willReturn($configData);
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $insertedConfig = $this->createRealConfiguration();
        $this->configurationMapper->method('insert')->willReturn($insertedConfig);
        $this->configurationService->method('importFromJson')->willReturn([
            'registers' => [1],
            'schemas' => [2, 3],
            'objects' => [],
        ]);

        $result = $this->controller->importFromGitHub();

        $this->assertEquals(201, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['result']['registersCount']);
        $this->assertEquals(2, $data['result']['schemasCount']);
    }

    public function testImportFromGitHubConflict(): void
    {
        $configData = [
            'info' => ['title' => 'Test Config'],
            'x-openregister' => ['app' => 'existingapp'],
        ];
        $this->request->method('getParams')->willReturn([
            'owner' => 'testorg',
            'repo' => 'testrepo',
            'path' => 'config.json',
            'branch' => 'main',
        ]);
        $this->githubHandler->method('getFileContent')->willReturn($configData);

        $existingConfig = $this->createRealConfiguration();
        $this->configurationMapper->method('findByApp')->willReturn([$existingConfig]);

        $result = $this->controller->importFromGitHub();

        $this->assertEquals(409, $result->getStatus());
        $this->assertArrayHasKey('existingConfigurationId', $result->getData());
    }

    public function testImportFromGitHubException(): void
    {
        $this->request->method('getParams')->willReturn([
            'owner' => 'testorg',
            'repo' => 'testrepo',
            'path' => 'config.json',
        ]);
        $this->githubHandler->method('getFileContent')
            ->willThrowException(new \Exception('API error'));

        $result = $this->controller->importFromGitHub();

        $this->assertEquals(500, $result->getStatus());
    }

    // ── importFromGitLab tests ─────────────────────────────────────────

    public function testImportFromGitLabMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([
            'namespace' => '',
            'project' => '',
            'path' => '',
        ]);

        $result = $this->controller->importFromGitLab();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testImportFromGitLabSuccess(): void
    {
        $configData = [
            'info' => ['title' => 'GL Config', 'version' => '2.0.0'],
            'x-openregister' => ['app' => 'glapp'],
        ];
        $this->request->method('getParams')->willReturn([
            'namespace' => 'myns',
            'project' => 'myproj',
            'path' => 'config.json',
            'ref' => 'main',
        ]);
        $this->gitlabHandler->method('getProjectByPath')->willReturn(['id' => 42]);
        $this->gitlabHandler->method('getFileContent')->willReturn($configData);
        $this->gitlabHandler->method('getApiBase')->willReturn('https://gitlab.com/api/v4');
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $insertedConfig = $this->createRealConfiguration();
        $this->configurationMapper->method('insert')->willReturn($insertedConfig);
        $this->configurationService->method('importFromJson')->willReturn([
            'registers' => [],
            'schemas' => [1],
            'objects' => [2],
        ]);

        $result = $this->controller->importFromGitLab();

        $this->assertEquals(201, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testImportFromGitLabException(): void
    {
        $this->request->method('getParams')->willReturn([
            'namespace' => 'myns',
            'project' => 'myproj',
            'path' => 'config.json',
        ]);
        $this->gitlabHandler->method('getProjectByPath')
            ->willThrowException(new \Exception('GitLab error'));

        $result = $this->controller->importFromGitLab();

        $this->assertEquals(500, $result->getStatus());
    }

    // ── importFromUrl tests ────────────────────────────────────────────

    public function testImportFromUrlMissingUrl(): void
    {
        $this->request->method('getParams')->willReturn(['url' => '']);

        $result = $this->controller->importFromUrl();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testImportFromUrlInvalidUrl(): void
    {
        $this->request->method('getParams')->willReturn(['url' => 'not-a-url']);

        $result = $this->controller->importFromUrl();

        $this->assertEquals(400, $result->getStatus());
    }

    // ── Additional edge-case tests for existing methods ────────────────

    public function testEnrichDetailsUnsupportedSource(): void
    {
        $this->request->method('getParams')->willReturn([
            'source' => 'unsupported',
            'owner' => 'org',
            'repo' => 'repo',
            'path' => 'file.json',
        ]);

        $result = $this->controller->enrichDetails();

        $this->assertEquals(404, $result->getStatus());
    }

    public function testEnrichDetailsMissingOwner(): void
    {
        $this->request->method('getParams')->willReturn([
            'source' => 'github',
            'owner' => '',
            'repo' => 'repo',
            'path' => 'file.json',
        ]);

        $result = $this->controller->enrichDetails();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testDiscoverMissingSource(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->githubHandler->method('searchConfigurations')
            ->willReturn(['results' => [], 'total_count' => 0]);

        $result = $this->controller->discover();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testExportWithIncludeObjectsParam(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn(['includeObjects' => true]);
        $this->configurationService->method('exportConfig')->willReturn([
            'info' => ['title' => 'Test'],
            'objects' => [['id' => 1]],
        ]);

        $result = $this->controller->export(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testImportWithSelectionParams(): void
    {
        $config = $this->createRealConfiguration();
        $this->configurationMapper->method('find')->willReturn($config);
        $this->request->method('getParams')->willReturn([
            'selection' => ['registers' => [1], 'schemas' => [2]],
        ]);
        $this->configurationService->method('importConfigurationWithSelection')
            ->willReturn(['registers' => [1], 'schemas' => [2], 'objects' => []]);

        $result = $this->controller->import(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

}
