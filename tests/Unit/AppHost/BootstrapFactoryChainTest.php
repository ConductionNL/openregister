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
use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCA\OpenRegister\AppHost\Controller\GenericSettingsController;
use OCA\OpenRegister\AppHost\Listener\GenericDeepLinkRegistrationListener;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Repair\GenericInitializeActions;
use OCA\OpenRegister\AppHost\Repair\GenericInitializeSettings;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;
use OCA\OpenRegister\AppHost\Settings\GenericSettingsSection;
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

        // The observability factories only ask the container for these three
        // already-built engine services (never their dependency trees), so a
        // mock instance of each is enough to resolve health/metrics aliases.
        $map[ManifestLoader::class]      = $this->createMock(ManifestLoader::class);
        $map[HealthCheckExecutor::class] = $this->createMock(HealthCheckExecutor::class);
        $map[MetricsEngine::class]       = $this->createMock(MetricsEngine::class);

        // The InitializeSettings factory injects the credential-broker app
        // registry for the D-G manifest auto-onboarding hook
        // (credential-doriath-leaf); a mock is enough — the repair step only
        // calls it at run() time, never at construction.
        $map['OCA\\OpenRegister\\Service\\Credential\\CredentialAppTokenService']
            = $this->createMock(\OCA\OpenRegister\Service\Credential\CredentialAppTokenService::class);

        // The InitializeActions factory injects the Doriath application
        // registrar for the D-G manifest auto-onboarding hook
        // (credential-doriath-leaf); a mock is enough — the repair step only
        // calls it at run() time, never at construction.
        $map['OCA\\OpenRegister\\Service\\Credential\\DoriathApplicationRegistrar']
            = $this->createMock(\OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar::class);

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

    /**
     * The regression guard for the missing-generic defect: register a leaf with
     * EVERY standard option enabled (observability + deep links), then resolve
     * every aliased factory through a container and assert each produces a real
     * generic instance. A dangling alias (e.g. a Bootstrap constant pointing at
     * a class that was never shipped — as `GenericPreferencesController` was)
     * makes the corresponding factory throw on resolution, failing this test.
     *
     * @return void
     */
    public function testAllFactoriesResolveToRealClassesWithFullOptions(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore', [
            'namespace'    => 'OCA\\PetStore',
            'observability' => true,
            'deepLinks'     => true,
        ]);

        $container = $this->resolvingContainer();

        // Leaf alias name => expected generic class produced by its factory.
        $expected = [
            'OCA\\PetStore\\Controller\\DashboardController'             => GenericDashboardController::class,
            'OCA\\PetStore\\Controller\\PreferencesController'           => GenericPreferencesController::class,
            'OCA\\PetStore\\Controller\\SettingsController'              => GenericSettingsController::class,
            'OCA\\PetStore\\Controller\\HealthController'                => GenericHealthController::class,
            'OCA\\PetStore\\Controller\\MetricsController'               => GenericMetricsController::class,
            'OCA\\PetStore\\Service\\SettingsService'                    => AppHostSettingsService::class,
            'OCA\\PetStore\\Service\\ActionAuthService'                  => GenericActionAuthService::class,
            'OCA\\PetStore\\Repair\\InitializeSettings'                  => GenericInitializeSettings::class,
            'OCA\\PetStore\\Repair\\InitializeActions'                   => GenericInitializeActions::class,
            'OCA\\PetStore\\Settings\\AdminSettings'                     => GenericAdminSettings::class,
            'OCA\\PetStore\\Sections\\SettingsSection'                   => GenericSettingsSection::class,
            'OCA\\PetStore\\Listener\\DeepLinkRegistrationListener'      => GenericDeepLinkRegistrationListener::class,
        ];

        foreach ($expected as $alias => $class) {
            $this->assertArrayHasKey(
                $alias,
                $context->factories,
                'Bootstrap::register did not register a factory for '.$alias
            );

            $instance = $context->factories[$alias]($container);
            $this->assertInstanceOf(
                $class,
                $instance,
                $alias.' must resolve to a real '.$class.' — a dangling/missing generic would throw here'
            );
        }
    }//end testAllFactoriesResolveToRealClassesWithFullOptions()

    /**
     * The resolved GenericPreferencesController behaves like the bespoke leaf
     * PreferencesController: a per-user write then read round-trips the value,
     * scoped to the leaf appId, and an anonymous request is rejected.
     *
     * @return void
     */
    public function testPreferencesControllerRoundTripsPerUserValue(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStore']);

        // Build a controller against a real (in-memory) config + a session user.
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $store  = [];
        $config = $this->createMock(IConfig::class);
        $config->method('setUserValue')->willReturnCallback(
            function ($uid, $app, $key, $value) use (&$store): void {
                $store[$uid.'|'.$app.'|'.$key] = $value;
            }
        );
        $config->method('getUserValue')->willReturnCallback(
            function ($uid, $app, $key, $default = '') use (&$store) {
                return ($store[$uid.'|'.$app.'|'.$key] ?? $default);
            }
        );
        $config->method('deleteUserValue')->willReturnCallback(
            function ($uid, $app, $key) use (&$store): void {
                unset($store[$uid.'|'.$app.'|'.$key]);
            }
        );

        $controller = new GenericPreferencesController(
            'petstore',
            $this->createMock(IRequest::class),
            $config,
            $userSession
        );

        // Write then read round-trips the value.
        $set = $controller->setPreference('support-dialog-seen', '1');
        $this->assertSame(['value' => '1'], $set->getData());

        $get = $controller->getPreference('support-dialog-seen');
        $this->assertSame(['value' => '1'], $get->getData());

        // It is stored under the LEAF app namespace, not OpenRegister's.
        $this->assertArrayHasKey('alice|petstore|pref_support-dialog-seen', $store);

        // Empty value clears it.
        $clear = $controller->setPreference('support-dialog-seen', '');
        $this->assertSame(['value' => null], $clear->getData());
        $this->assertSame(['value' => null], $controller->getPreference('support-dialog-seen')->getData());

        // Anonymous request is rejected (no IDOR / no cross-user reach).
        $anon = $this->createMock(IUserSession::class);
        $anon->method('getUser')->willReturn(null);
        $anonController = new GenericPreferencesController(
            'petstore',
            $this->createMock(IRequest::class),
            $config,
            $anon
        );
        $this->assertSame(401, $anonController->getPreference('x')->getStatus());
    }//end testPreferencesControllerRoundTripsPerUserValue()
}//end class
