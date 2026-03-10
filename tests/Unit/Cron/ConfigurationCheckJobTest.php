<?php

declare(strict_types=1);

namespace Unit\Cron;

use Exception;
use OCA\OpenRegister\Cron\ConfigurationCheckJob;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigurationCheckJobTest extends TestCase
{
    private ConfigurationMapper&MockObject $configurationMapper;
    private ConfigurationService&MockObject $configurationService;
    private NotificationService&MockObject $notificationService;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;

    private function createJob(string $intervalValue = '3600'): ConfigurationCheckJob
    {
        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->appConfig->method('getValueString')
            ->with('openregister', 'configuration_check_interval', '3600')
            ->willReturn($intervalValue);

        return new ConfigurationCheckJob(
            $timeFactory,
            $this->configurationMapper,
            $this->configurationService,
            $this->notificationService,
            $this->appConfig,
            $this->logger,
        );
    }

    private function runJob(ConfigurationCheckJob $job, $argument = null): void
    {
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($job, $argument);
    }

    /**
     * Create a Configuration entity for testing.
     *
     * Uses real Configuration entity with properties set via setters.
     * For isRemoteSource() — set sourceType to 'github', 'gitlab', or 'url'.
     * For hasUpdateAvailable() — set both localVersion and remoteVersion.
     * For getAutoUpdate() — set autoUpdate boolean.
     */
    private function createConfigEntity(array $overrides = []): Configuration
    {
        $config = new Configuration();

        $config->setId($overrides['id'] ?? 1);

        $config->setTitle($overrides['title'] ?? 'Test Config');

        // isRemoteSource() checks if sourceType is in ['github', 'gitlab', 'url']
        $config->setSourceType($overrides['sourceType'] ?? 'github');

        // hasUpdateAvailable() compares remoteVersion > localVersion
        if (isset($overrides['localVersion'])) {
            $config->setLocalVersion($overrides['localVersion']);
        }
        if (isset($overrides['remoteVersion'])) {
            $config->setRemoteVersion($overrides['remoteVersion']);
        }

        $config->setAutoUpdate($overrides['autoUpdate'] ?? false);

        return $config;
    }

    public function testConstructorSetsDefaultInterval(): void
    {
        $job = $this->createJob('3600');

        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('interval');
        $property->setAccessible(true);

        $this->assertEquals(3600, $property->getValue($job));
    }

    public function testConstructorSetsCustomInterval(): void
    {
        $job = $this->createJob('7200');

        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('interval');
        $property->setAccessible(true);

        $this->assertEquals(7200, $property->getValue($job));
    }

    public function testConstructorDisabledIntervalSetsOneYear(): void
    {
        $job = $this->createJob('0');

        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('interval');
        $property->setAccessible(true);

        $this->assertEquals(86400 * 365, $property->getValue($job));
    }

    public function testRunJobDisabledSkipsExecution(): void
    {
        $job = $this->createJob('0');

        $this->configurationMapper->expects($this->never())->method('findAll');

        $this->runJob($job, null);
    }

    public function testRunNoConfigurations(): void
    {
        $job = $this->createJob('3600');

        $this->configurationMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $this->configurationService->expects($this->never())->method('checkRemoteVersion');

        $this->runJob($job, null);
    }

    public function testRunSkipsNonRemoteConfiguration(): void
    {
        $job = $this->createJob('3600');

        // sourceType = 'local' means isRemoteSource() returns false
        $config = $this->createConfigEntity(['sourceType' => 'local']);

        $this->configurationMapper->method('findAll')->willReturn([$config]);

        $this->configurationService->expects($this->never())->method('checkRemoteVersion');

        $this->runJob($job, null);
    }

    public function testRunRemoteVersionNull(): void
    {
        $job = $this->createJob('3600');

        $config = $this->createConfigEntity(['id' => 1]);

        $this->configurationMapper->method('findAll')->willReturn([$config]);

        $this->configurationService->expects($this->once())
            ->method('checkRemoteVersion')
            ->with($config)
            ->willReturn(null);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('No remote version'),
                $this->anything()
            );

        $this->runJob($job, null);
    }

    public function testRunConfigurationUpToDate(): void
    {
        $job = $this->createJob('3600');

        // localVersion == remoteVersion => hasUpdateAvailable() returns false
        $config = $this->createConfigEntity([
            'localVersion' => '1.0.0',
            'remoteVersion' => '1.0.0',
        ]);

        $this->configurationMapper->method('findAll')->willReturn([$config]);
        $this->configurationService->method('checkRemoteVersion')->willReturn('1.0.0');

        $this->configurationService->expects($this->never())->method('importConfigurationWithSelection');
        $this->notificationService->expects($this->never())->method('notifyConfigurationUpdate');

        $this->runJob($job, null);
    }

    public function testRunAutoUpdateEnabled(): void
    {
        $job = $this->createJob('3600');

        $config = $this->createConfigEntity([
            'localVersion' => '1.0.0',
            'remoteVersion' => '2.0.0',
            'autoUpdate' => true,
        ]);

        $this->configurationMapper->method('findAll')->willReturn([$config]);
        $this->configurationService->method('checkRemoteVersion')->willReturn('2.0.0');

        $this->configurationService->expects($this->once())
            ->method('importConfigurationWithSelection')
            ->with($config, []);

        $this->notificationService->expects($this->never())->method('notifyConfigurationUpdate');

        $this->runJob($job, null);
    }

    public function testRunAutoUpdateFails(): void
    {
        $job = $this->createJob('3600');

        $config = $this->createConfigEntity([
            'localVersion' => '1.0.0',
            'remoteVersion' => '2.0.0',
            'autoUpdate' => true,
        ]);

        $this->configurationMapper->method('findAll')->willReturn([$config]);
        $this->configurationService->method('checkRemoteVersion')->willReturn('2.0.0');

        $this->configurationService->expects($this->once())
            ->method('importConfigurationWithSelection')
            ->willThrowException(new Exception('Import failed'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('Auto-update failed'),
                $this->anything()
            );

        $this->runJob($job, null);
    }

    public function testRunAutoUpdateDisabledSendsNotification(): void
    {
        $job = $this->createJob('3600');

        $config = $this->createConfigEntity([
            'localVersion' => '1.0.0',
            'remoteVersion' => '2.0.0',
            'autoUpdate' => false,
        ]);

        $this->configurationMapper->method('findAll')->willReturn([$config]);
        $this->configurationService->method('checkRemoteVersion')->willReturn('2.0.0');

        $this->notificationService->expects($this->once())
            ->method('notifyConfigurationUpdate')
            ->with($config)
            ->willReturn(3);

        $this->configurationService->expects($this->never())->method('importConfigurationWithSelection');

        $this->runJob($job, null);
    }

    public function testRunNotificationFailureHandled(): void
    {
        $job = $this->createJob('3600');

        $config = $this->createConfigEntity([
            'localVersion' => '1.0.0',
            'remoteVersion' => '2.0.0',
            'autoUpdate' => false,
        ]);

        $this->configurationMapper->method('findAll')->willReturn([$config]);
        $this->configurationService->method('checkRemoteVersion')->willReturn('2.0.0');

        $this->notificationService->method('notifyConfigurationUpdate')
            ->willThrowException(new Exception('Notification error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('Failed to send notifications'),
                $this->anything()
            );

        $this->runJob($job, null);
    }

    public function testRunCheckRemoteVersionThrows(): void
    {
        $job = $this->createJob('3600');

        $config = $this->createConfigEntity(['id' => 1]);

        $this->configurationMapper->method('findAll')->willReturn([$config]);
        $this->configurationService->method('checkRemoteVersion')
            ->willThrowException(new Exception('Network error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('Error checking configuration'),
                $this->anything()
            );

        $this->runJob($job, null);
    }

    public function testRunOuterExceptionHandled(): void
    {
        $job = $this->createJob('3600');

        $this->configurationMapper->method('findAll')
            ->willThrowException(new Exception('DB crash'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('Configuration check job failed: DB crash'),
                $this->anything()
            );

        $this->runJob($job, null);
    }

    public function testRunMultipleConfigurationsMixed(): void
    {
        $job = $this->createJob('3600');

        // Remote config with auto-update
        $config1 = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'github',
            'localVersion' => '1.0.0',
            'remoteVersion' => '2.0.0',
            'autoUpdate' => true,
        ]);
        // Local config — skipped
        $config2 = $this->createConfigEntity([
            'id' => 2,
            'sourceType' => 'local',
        ]);
        // Remote config with notification
        $config3 = $this->createConfigEntity([
            'id' => 3,
            'sourceType' => 'url',
            'localVersion' => '1.0.0',
            'remoteVersion' => '2.0.0',
            'autoUpdate' => false,
        ]);

        $this->configurationMapper->method('findAll')
            ->willReturn([$config1, $config2, $config3]);

        $this->configurationService->method('checkRemoteVersion')->willReturn('2.0.0');

        // config1: auto-update
        $this->configurationService->expects($this->once())
            ->method('importConfigurationWithSelection')
            ->with($config1, []);

        // config3: notification
        $this->notificationService->expects($this->once())
            ->method('notifyConfigurationUpdate')
            ->with($config3)
            ->willReturn(1);

        $this->runJob($job, null);
    }

    public function testRunUpdateAvailableWithNullVersions(): void
    {
        $job = $this->createJob('3600');

        // No localVersion or remoteVersion set => hasUpdateAvailable() returns false
        $config = $this->createConfigEntity([
            'sourceType' => 'github',
        ]);

        $this->configurationMapper->method('findAll')->willReturn([$config]);
        $this->configurationService->method('checkRemoteVersion')->willReturn('2.0.0');

        // hasUpdateAvailable() returns false because localVersion is null
        $this->configurationService->expects($this->never())->method('importConfigurationWithSelection');
        $this->notificationService->expects($this->never())->method('notifyConfigurationUpdate');

        $this->runJob($job, null);
    }

    public static function intervalProvider(): array
    {
        return [
            'default one hour'  => ['3600', 3600],
            'half hour'         => ['1800', 1800],
            'one day'           => ['86400', 86400],
            'disabled sets year' => ['0', 86400 * 365],
        ];
    }

    #[DataProvider('intervalProvider')]
    public function testConstructorIntervalValues(string $configValue, int $expectedInterval): void
    {
        $job = $this->createJob($configValue);

        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('interval');
        $property->setAccessible(true);

        $this->assertEquals($expectedInterval, $property->getValue($job));
    }

    public function testRunContinuesAfterFailedCheck(): void
    {
        $job = $this->createJob('3600');

        $config1 = $this->createConfigEntity([
            'id' => 1,
            'sourceType' => 'github',
        ]);
        $config2 = $this->createConfigEntity([
            'id' => 2,
            'sourceType' => 'url',
            'localVersion' => '1.0.0',
            'remoteVersion' => '2.0.0',
            'autoUpdate' => true,
        ]);

        $this->configurationMapper->method('findAll')->willReturn([$config1, $config2]);

        $callCount = 0;
        $this->configurationService->method('checkRemoteVersion')
            ->willReturnCallback(function ($config) use (&$callCount) {
                $callCount++;
                if ($config->getId() === 1) {
                    throw new Exception('Check failed');
                }
                return '2.0.0';
            });

        $this->configurationService->expects($this->once())
            ->method('importConfigurationWithSelection')
            ->with($config2, []);

        $this->runJob($job, null);
        $this->assertEquals(2, $callCount);
    }
}
