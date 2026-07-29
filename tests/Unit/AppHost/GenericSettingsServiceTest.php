<?php
/**
 * AppHost GenericSettingsService — ADR-049 explicit fail-mode tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCA\OpenRegister\AppHost\Service\GenericSettingsService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The settings-plane service must fail CLOSED: typed exceptions on a missing
 * foundation / missing register JSON, never a silent null or empty-success.
 */
class GenericSettingsServiceTest extends TestCase
{

    /**
     * @var array<int, string> Temp directories created by a test, removed in tearDown.
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }

        $this->tempDirs = [];
        parent::tearDown();
    }//end tearDown()

    /**
     * Recursively removes a temp directory tree.
     */
    private function removeDir(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path) === true) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }//end removeDir()

    /**
     * Creates a scratch "app path" with an `{appId}_register.json` so the
     * inherited register-JSON resolution can run against real files.
     *
     * @param string               $appId    The leaf app id.
     * @param array<string, mixed> $register The base `{appId}_register.json` content.
     *
     * @return string The scratch app path.
     */
    private function makeAppPath(string $appId, array $register): string
    {
        $dir = sys_get_temp_dir().'/apphost-generic-settings-test-'.uniqid();
        mkdir($dir.'/lib/Settings', 0777, true);
        file_put_contents($dir.'/lib/Settings/'.$appId.'_register.json', json_encode($register));

        $this->tempDirs[] = $dir;
        return $dir;
    }//end makeAppPath()

    private function service(
        bool $orInstalled,
        ?ContainerInterface $container=null,
        ?IAppManager $appManager=null,
        string $appId='myapp'
    ): GenericSettingsService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('reg-uuid');

        if ($appManager === null) {
            $appManager = $this->createMock(IAppManager::class);
            $appManager->method('isInstalled')->willReturn($orInstalled);
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        return new GenericSettingsService(
            $appId,
            $appConfig,
            $appManager,
            ($container ?? $this->createMock(ContainerInterface::class)),
            $groupManager,
            $userSession,
            $this->createMock(LoggerInterface::class)
        );
    }//end service()

    public function testLoadConfigurationThrowsTypedWhenOrAbsent(): void
    {
        // ADR-049: foundation-missing is explicit — NOT a ['success' => false] degrade.
        $this->expectException(FoundationUnavailableException::class);
        $this->service(orInstalled: false)->loadConfiguration(force: true);
    }//end testLoadConfigurationThrowsTypedWhenOrAbsent()

    public function testLoadConfigurationThrowsTypedWhenConfigurationServiceUnresolvable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('no binding'));

        try {
            $this->service(orInstalled: true, container: $container)->loadConfiguration(force: true);
            $this->fail('Expected FoundationUnavailableException');
        } catch (FoundationUnavailableException $e) {
            $this->assertSame('myapp', $e->getAppId());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }//end testLoadConfigurationThrowsTypedWhenConfigurationServiceUnresolvable()

    public function testLoadConfigurationThrowsTypedWhenRegisterJsonMissing(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);
        $appManager->method('getAppPath')->with('myapp')->willReturn('/nonexistent/path');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->createMock(ConfigurationService::class));

        try {
            $this->service(orInstalled: true, container: $container, appManager: $appManager)->loadConfiguration(force: true);
            $this->fail('Expected ConfigurationMissingException');
        } catch (ConfigurationMissingException $e) {
            $this->assertSame('myapp', $e->getAppId());
            $this->assertStringContainsString('myapp_register.json', $e->getConfigKey());
        }
    }//end testLoadConfigurationThrowsTypedWhenRegisterJsonMissing()

    public function testLoadConfigurationDelegatesToImportFromAppAndStampsConfigVersion(): void
    {
        $appPath = $this->makeAppPath(
                'myapp',
                [
                    'info'       => ['version' => '2.0.0'],
                    'components' => ['schemas' => ['Widget' => ['type' => 'object']]],
                ]
                );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);
        $appManager->method('getAppPath')->with('myapp')->willReturn($appPath);
        $appManager->method('getAppVersion')->with('myapp')->willReturn('9.9.9');

        $configurationService = $this->createMock(ConfigurationService::class);
        $configurationService->expects($this->once())
            ->method('importFromApp')
            ->with(
                'myapp',
                ['info' => ['version' => '2.0.0'], 'components' => ['schemas' => ['Widget' => ['type' => 'object']]]],
                '2.0.0',
                true
            )
            ->willReturn(['version' => '2.0.0']);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($configurationService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects($this->once())
            ->method('setValueString')
            ->with('myapp', 'config_version', '9.9.9');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        $service = new GenericSettingsService(
            'myapp',
            $appConfig,
            $appManager,
            $container,
            $groupManager,
            $userSession,
            $this->createMock(LoggerInterface::class)
        );

        $result = $service->loadConfiguration(force: true);

        $this->assertTrue($result['success']);
        $this->assertSame('2.0.0', $result['version']);
    }//end testLoadConfigurationDelegatesToImportFromAppAndStampsConfigVersion()

    public function testLoadConfigurationEmptyImportResultIsExplicitNonSuccess(): void
    {
        $appPath = $this->makeAppPath('myapp', ['info' => ['version' => '1.0.0']]);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);
        $appManager->method('getAppPath')->with('myapp')->willReturn($appPath);

        $configurationService = $this->createMock(ConfigurationService::class);
        $configurationService->method('importFromApp')->willReturn([]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($configurationService);

        $result = $this->service(orInstalled: true, container: $container, appManager: $appManager)->loadConfiguration(force: false);

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['message'], 'non-success must carry a machine-readable reason');
    }//end testLoadConfigurationEmptyImportResultIsExplicitNonSuccess()

    public function testGetSettingsInheritedShapeUnchanged(): void
    {
        // The canonical read path is inherited from AppHostSettingsService.
        $settings = $this->service(orInstalled: true)->getSettings();
        $this->assertSame('reg-uuid', $settings['register']);
        $this->assertTrue($settings['openregisters']);
        $this->assertTrue($settings['isAdmin']);
    }//end testGetSettingsInheritedShapeUnchanged()
}//end class
