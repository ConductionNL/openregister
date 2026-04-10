<?php

declare(strict_types=1);

namespace Unit\Cron;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Cron\SyncConfigurationsJob;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

class SyncConfigurationsJobTest extends TestCase
{
    private SyncConfigurationsJob $job;
    private ConfigurationMapper&MockObject $configurationMapper;
    private ConfigurationService&MockObject $configurationService;
    private GitHubHandler&MockObject $githubService;
    private GitLabHandler&MockObject $gitlabService;
    private Client&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->githubService = $this->createMock(GitHubHandler::class);
        $this->gitlabService = $this->createMock(GitLabHandler::class);
        $this->httpClient = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->job = new SyncConfigurationsJob(
            $timeFactory,
            $this->configurationMapper,
            $this->configurationService,
            $this->githubService,
            $this->gitlabService,
            $this->httpClient,
            $this->logger,
        );
    }

    private function runJob($argument = null): void
    {
        $reflection = new \ReflectionClass($this->job);
        $method = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    /**
     * Create a Configuration entity with properties set via setters.
     *
     * Uses real Configuration entity instances since Nextcloud Entity __call magic
     * prevents PHPUnit from mocking getters.
     */
    private function createConfigEntity(array $overrides = []): Configuration
    {
        $config = new Configuration();

        $config->setId($overrides['id'] ?? 1);

        $config->setTitle($overrides['title'] ?? 'Test Config');
        $config->setSourceType($overrides['sourceType'] ?? 'github');
        $config->setGithubRepo($overrides['githubRepo'] ?? 'owner/repo');
        $config->setGithubBranch($overrides['githubBranch'] ?? 'main');
        $config->setGithubPath($overrides['githubPath'] ?? 'config.json');
        $config->setSourceUrl($overrides['sourceUrl'] ?? 'https://example.com/config.json');
        $config->setApp(array_key_exists('app', $overrides) ? $overrides['app'] : 'testapp');
        $config->setVersion(array_key_exists('version', $overrides) ? $overrides['version'] : '2.0.0');
        $config->setSyncInterval($overrides['syncInterval'] ?? 1);

        if (array_key_exists('lastSyncDate', $overrides)) {
            $config->setLastSyncDate($overrides['lastSyncDate']);
        }

        return $config;
    }

    public function testConstructorSetsInterval(): void
    {
        $reflection = new \ReflectionClass($this->job);
        $property = $reflection->getProperty('interval');
        $property->setAccessible(true);

        $this->assertEquals(3600, $property->getValue($this->job));
    }

    public function testRunWithNoConfigurations(): void
    {
        $this->configurationMapper->expects($this->once())
            ->method('findBySyncEnabled')
            ->willReturn([]);

        $this->configurationService->expects($this->never())->method('importFromApp');

        $this->runJob(null);
    }

    public function testRunWithNeverSyncedConfigurationSyncsFromGitHub(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'lastSyncDate' => null,
            'sourceType' => 'github',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $configData = [
            'x-openregister' => ['app' => 'myapp'],
            'info' => ['version' => '1.2.0'],
        ];

        $this->githubService->expects($this->once())
            ->method('getFileContent')
            ->with('owner', 'repo', 'config.json', 'main')
            ->willReturn($configData);

        $this->configurationService->expects($this->once())
            ->method('importFromApp')
            ->with('myapp', $configData, '1.2.0', true);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->runJob(null);
    }

    public function testRunSkipsConfigurationNotDueForSync(): void
    {
        $config = $this->createConfigEntity([
            'lastSyncDate' => new DateTime(),
            'syncInterval' => 24, // 24 hours
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->githubService->expects($this->never())->method('getFileContent');
        $this->configurationService->expects($this->never())->method('importFromApp');

        $this->runJob(null);
    }

    public function testRunSyncsDueConfiguration(): void
    {
        $oldDate = new DateTime();
        $oldDate->modify('-25 hours');

        $config = $this->createConfigEntity([
            'lastSyncDate' => $oldDate,
            'syncInterval' => 24,
            'sourceType' => 'github',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->githubService->expects($this->once())
            ->method('getFileContent')
            ->willReturn(['x-openregister' => ['app' => 'myapp'], 'info' => ['version' => '1.0.0']]);

        $this->configurationService->expects($this->once())->method('importFromApp');

        $this->runJob(null);
    }

    public function testRunSyncsFromGitLab(): void
    {
        $config = $this->createConfigEntity([
            'sourceType' => 'gitlab',
            'sourceUrl' => 'https://gitlab.com/myns/myproj/-/blob/main/path/to/config.json',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->gitlabService->expects($this->once())
            ->method('getProjectByPath')
            ->with('myns', 'myproj')
            ->willReturn(['id' => 42]);

        $configData = [
            'x-openregister' => ['app' => 'glapp'],
            'info' => ['version' => '3.0.0'],
        ];
        $this->gitlabService->expects($this->once())
            ->method('getFileContent')
            ->with(42, 'path/to/config.json', 'main')
            ->willReturn($configData);

        $this->configurationService->expects($this->once())
            ->method('importFromApp')
            ->with('glapp', $configData, '3.0.0', true);

        $this->runJob(null);
    }

    public function testRunGitLabInvalidUrlThrows(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'gitlab',
            'sourceUrl' => 'https://not-gitlab.com/something',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(null);
    }

    public function testRunGitLabEmptySourceUrlThrows(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'gitlab',
            'sourceUrl' => '',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(null);
    }

    public function testRunSyncsFromUrl(): void
    {
        $config = $this->createConfigEntity([
            'sourceType' => 'url',
            'sourceUrl' => 'https://example.com/config.json',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $body = $this->createMock(StreamInterface::class);
        $body->method('getContents')->willReturn(json_encode([
            'x-openregister' => ['app' => 'urlapp'],
            'info' => ['version' => '2.0.0'],
        ]));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($body);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.com/config.json')
            ->willReturn($response);

        $this->configurationService->expects($this->once())
            ->method('importFromApp')
            ->with('urlapp', $this->isType('array'), '2.0.0', true);

        $this->runJob(null);
    }

    public function testRunUrlEmptySourceUrlThrows(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'url',
            'sourceUrl' => '',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(null);
    }

    public function testRunUrlInvalidJsonThrows(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'url',
            'sourceUrl' => 'https://example.com/bad.json',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $body = $this->createMock(StreamInterface::class);
        $body->method('getContents')->willReturn('not valid json{{{');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($body);

        $this->httpClient->method('request')->willReturn($response);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(null);
    }

    public function testRunSyncsFromLocal(): void
    {
        $config = $this->createConfigEntity([
            'sourceType' => 'local',
            'sourceUrl' => '/path/to/local/config.json',
            'app' => 'localapp',
            'version' => '5.0.0',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationService->expects($this->once())
            ->method('importFromFilePath')
            ->with('localapp', '/path/to/local/config.json', '5.0.0', true);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->runJob(null);
    }

    public function testRunLocalEmptySourceUrlThrows(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'local',
            'sourceUrl' => '',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(null);
    }

    public function testRunUnsupportedSourceTypeThrows(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'ftp',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(null);
    }

    public function testRunGitHubEmptyRepoThrows(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'github',
            'githubRepo' => '',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(null);
    }

    public function testRunGitHubEmptyPathThrows(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'github',
            'githubRepo' => 'owner/repo',
            'githubPath' => '',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationMapper->expects($this->once())
            ->method('updateSyncStatus');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(null);
    }

    public function testRunGitHubNullBranchDefaultsToMain(): void
    {
        $config = $this->createConfigEntity([
            'sourceType' => 'github',
            'githubBranch' => null,
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->githubService->expects($this->once())
            ->method('getFileContent')
            ->with('owner', 'repo', 'config.json', 'main')
            ->willReturn(['info' => ['version' => '1.0.0']]);

        $this->configurationService->expects($this->once())->method('importFromApp');

        $this->runJob(null);
    }

    public function testRunGitHubFallbackAppId(): void
    {
        $config = $this->createConfigEntity([
            'sourceType' => 'github',
            'app' => 'fallbackapp',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        // No x-openregister.app in response, falls back to config->getApp()
        $this->githubService->method('getFileContent')
            ->willReturn(['info' => ['version' => '1.0.0']]);

        $this->configurationService->expects($this->once())
            ->method('importFromApp')
            ->with('fallbackapp', $this->anything(), '1.0.0', true);

        $this->runJob(null);
    }

    public function testRunGitHubFallbackVersion(): void
    {
        $config = $this->createConfigEntity([
            'sourceType' => 'github',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        // No info.version, but has x-openregister.version
        $this->githubService->method('getFileContent')
            ->willReturn(['x-openregister' => ['app' => 'a', 'version' => '9.9.9']]);

        $this->configurationService->expects($this->once())
            ->method('importFromApp')
            ->with('a', $this->anything(), '9.9.9', true);

        $this->runJob(null);
    }

    public function testRunGitHubDefaultVersionAndApp(): void
    {
        $config = $this->createConfigEntity([
            'sourceType' => 'github',
            'app' => null,
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        // No version info at all, no app
        $this->githubService->method('getFileContent')
            ->willReturn([]);

        $this->configurationService->expects($this->once())
            ->method('importFromApp')
            ->with('unknown', $this->anything(), '1.0.0', true);

        $this->runJob(null);
    }

    public function testRunOuterExceptionHandled(): void
    {
        $this->configurationMapper->method('findBySyncEnabled')
            ->willThrowException(new Exception('DB down'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('Configuration sync job failed: DB down'),
                $this->anything()
            );

        $this->runJob(null);
    }

    public function testRunSyncStatusUpdateFailureHandled(): void
    {
        $config = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'ftp', // will throw unsupported
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        // The updateSyncStatus call itself also throws
        $this->configurationMapper->method('updateSyncStatus')
            ->willThrowException(new Exception('Status update failed'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('Failed to update sync status'),
                $this->anything()
            );

        $this->runJob(null);
    }

    public function testRunLocalFallbackAppUnknown(): void
    {
        $config = $this->createConfigEntity([
            'sourceType' => 'local',
            'sourceUrl' => '/path/to/file.json',
            'app' => null,
            'version' => null,
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config]);

        $this->configurationService->expects($this->once())
            ->method('importFromFilePath')
            ->with('unknown', '/path/to/file.json', '1.0.0', true);

        $this->runJob(null);
    }

    public function testRunMultipleConfigurationsWithMixedResults(): void
    {
        $config1 = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'github',
        ]);
        $config2 = $this->createConfigEntity([
            'id' => 2,
            'sourceType' => 'local',
            'sourceUrl' => '/path/file.json',
            'app' => 'app2',
            'version' => '1.0.0',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config1, $config2]);

        $this->githubService->method('getFileContent')
            ->willReturn(['info' => ['version' => '1.0.0']]);

        $this->configurationService->expects($this->once())->method('importFromApp');
        $this->configurationService->expects($this->once())->method('importFromFilePath');

        $this->runJob(null);
    }

    public function testRunContinuesAfterSingleConfigFailure(): void
    {
        $config1 = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'ftp', // unsupported, will fail
        ]);
        $config2 = $this->createConfigEntity([
            'id' => 2,
            'sourceType' => 'local',
            'sourceUrl' => '/path/file.json',
            'app' => 'app2',
            'version' => '1.0.0',
        ]);

        $this->configurationMapper->method('findBySyncEnabled')->willReturn([$config1, $config2]);

        // config2 should still be processed even though config1 fails
        $this->configurationService->expects($this->once())->method('importFromFilePath');

        $this->runJob(null);
    }
}
