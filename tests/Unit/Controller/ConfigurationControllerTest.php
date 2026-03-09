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
}
