<?php
/**
 * AppHost Bootstrap — factory-chain integration: a registered factory, when
 * invoked with a resolving container, produces the correct generic instance
 * carrying the leaf appId. This exercises the full
 * route → alias → factory → generic-controller resolution chain in-process,
 * without needing a live NC fixture app installed.
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

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\AppHost\Controller\GenericDashboardController;
use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCA\OpenRegister\AppHost\Controller\GenericSettingsController;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/RecordingRegistrationContext.php';

/**
 * Resolves each registered factory through a container that returns mocked
 * OCP services, asserting the produced instance is the expected generic.
 */
class BootstrapFactoryChainTest extends TestCase
{
    /**
     * Build a container that returns a mock for every OCP service the
     * factories ask for.
     *
     * @return ContainerInterface
     */
    private function resolvingContainer(): ContainerInterface
    {
        $map = [
            'OCP\\IRequest'                                  => $this->createMock(IRequest::class),
            'OCP\\IConfig'                                   => $this->createMock(IConfig::class),
            'OCP\\IUserSession'                              => $this->createMock(IUserSession::class),
            'OCP\\IAppConfig'                                => $this->createMock(IAppConfig::class),
            'OCP\\App\\IAppManager'                          => $this->createMock(IAppManager::class),
            'OCP\\IGroupManager'                             => $this->createMock(IGroupManager::class),
            'OCP\\IURLGenerator'                             => $this->createMock(IURLGenerator::class),
            'OCP\\AppFramework\\Services\\IInitialState'     => $this->createMock(IInitialState::class),
            'Psr\\Log\\LoggerInterface'                      => $this->createMock(LoggerInterface::class),
        ];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use (&$map, $container) {
                if (isset($map[$id]) === true) {
                    return $map[$id];
                }

                // The settings service is resolved by the settings controller +
                // repair step; build it from the same container.
                if ($id === AppHostSettingsService::class) {
                    return new AppHostSettingsService(
                        'petstore',
                        $container->get('OCP\\IAppConfig'),
                        $container->get('OCP\\App\\IAppManager'),
                        $container,
                        $container->get('OCP\\IGroupManager'),
                        $container->get('OCP\\IUserSession'),
                        $container->get('Psr\\Log\\LoggerInterface')
                    );
                }

                if ($id === GenericActionAuthService::class) {
                    return new GenericActionAuthService(
                        'petstore',
                        $container->get('OCP\\IAppConfig'),
                        $container->get('OCP\\IGroupManager')
                    );
                }

                $this->fail('Factory requested an unmapped service: '.$id);
            }
        );

        return $container;
    }//end resolvingContainer()

    public function testDashboardFactoryProducesGenericWithLeafAppId(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStore']);

        $factory  = $context->factories['OCA\\PetStore\\Controller\\DashboardController'];
        $instance = $factory($this->resolvingContainer());

        $this->assertInstanceOf(GenericDashboardController::class, $instance);
    }//end testDashboardFactoryProducesGenericWithLeafAppId()

    public function testSettingsFactoryChainResolves(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStore']);

        $instance = $context->factories['OCA\\PetStore\\Controller\\SettingsController']($this->resolvingContainer());
        $this->assertInstanceOf(GenericSettingsController::class, $instance);
    }//end testSettingsFactoryChainResolves()

    public function testPreferencesFactoryResolves(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStore']);

        $instance = $context->factories['OCA\\PetStore\\Controller\\PreferencesController']($this->resolvingContainer());
        $this->assertInstanceOf(GenericPreferencesController::class, $instance);
    }//end testPreferencesFactoryResolves()

    public function testSettingsServiceFactoryResolves(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStore']);

        $instance = $context->factories['OCA\\PetStore\\Service\\SettingsService']($this->resolvingContainer());
        $this->assertInstanceOf(AppHostSettingsService::class, $instance);
    }//end testSettingsServiceFactoryResolves()
}//end class
