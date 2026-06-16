<?php
/**
 * AppHost AppHostSettingsService — settings resolution + OR availability tests.
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

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the settings map shape, OR-availability and disabled-OR degrade.
 */
class AppHostSettingsServiceTest extends TestCase
{
    private function service(bool $orInstalled, bool $isAdmin, string $registerValue = 'reg-uuid'): AppHostSettingsService
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn($registerValue);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn($orInstalled);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        return new AppHostSettingsService(
            'myapp',
            $appConfig,
            $appManager,
            $this->createMock(ContainerInterface::class),
            $groupManager,
            $userSession,
            $this->createMock(LoggerInterface::class)
        );
    }//end service()

    public function testGetSettingsIncludesRegisterAndMetadata(): void
    {
        $settings = $this->service(orInstalled: true, isAdmin: true)->getSettings();
        $this->assertSame('reg-uuid', $settings['register']);
        $this->assertTrue($settings['openregisters']);
        $this->assertTrue($settings['isAdmin']);
    }//end testGetSettingsIncludesRegisterAndMetadata()

    public function testNonAdminFlagReflected(): void
    {
        $settings = $this->service(orInstalled: true, isAdmin: false)->getSettings();
        $this->assertFalse($settings['isAdmin']);
    }//end testNonAdminFlagReflected()

    public function testIsOpenRegisterAvailableReflectsAppManager(): void
    {
        $this->assertTrue($this->service(orInstalled: true, isAdmin: false)->isOpenRegisterAvailable());
        $this->assertFalse($this->service(orInstalled: false, isAdmin: false)->isOpenRegisterAvailable());
    }//end testIsOpenRegisterAvailableReflectsAppManager()

    public function testLoadConfigurationDegradesWhenOrAbsent(): void
    {
        $result = $this->service(orInstalled: false, isAdmin: true)->loadConfiguration(force: true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('OpenRegister', $result['message']);
    }//end testLoadConfigurationDegradesWhenOrAbsent()
}//end class
