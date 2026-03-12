<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ConfigurationController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\NotificationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigurationControllerDeepTest extends TestCase
{
    private ConfigurationController $controller;
    private IRequest|MockObject $request;
    private ConfigurationMapper|MockObject $configurationMapper;
    private ConfigurationService|MockObject $configurationService;
    private NotificationService|MockObject $notificationService;
    private GitHubHandler|MockObject $gitHubHandler;
    private GitLabHandler|MockObject $gitLabHandler;
    private IAppManager|MockObject $appManager;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->gitHubHandler = $this->createMock(GitHubHandler::class);
        $this->gitLabHandler = $this->createMock(GitLabHandler::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ConfigurationController(
            'openregister',
            $this->request,
            $this->configurationMapper,
            $this->configurationService,
            $this->notificationService,
            $this->gitHubHandler,
            $this->gitLabHandler,
            $this->appManager,
            $this->logger
        );
    }

    public function testIndexReturnsConfigurations(): void
    {
        $this->configurationMapper->method('findAll')
            ->willReturn([]);

        $response = $this->controller->index();

        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexException(): void
    {
        $this->configurationMapper->method('findAll')
            ->willThrowException(new Exception('db error'));

        $response = $this->controller->index();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testShowException(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $response = $this->controller->show(999);

        $this->assertEquals(404, $response->getStatus());
    }
}
