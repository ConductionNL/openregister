<?php
/**
 * AppHost Bootstrap — alias registration + lazy (disabled-OR-safe) tests.
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
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/RecordingRegistrationContext.php';

/**
 * Bootstrap::register() must alias the leaf controller class names to the
 * generics and must NOT autoload any generic class while doing so (lazy).
 */
class BootstrapTest extends TestCase
{
    public function testRegistersLeafControllerServiceNames(): void
    {
        $context = new RecordingRegistrationContext();

        Bootstrap::register($context, 'petstore', [
            'namespace'        => 'OCA\\PetStore',
            'dashboardWidgets' => ['OCA\\PetStore\\Dashboard\\ExampleWidget'],
            'mcpProvider'      => 'OCA\\PetStore\\Mcp\\ExampleToolProvider',
        ]);
        $services = $context->services;

        // Leaf conventional controller class names are aliased.
        $this->assertContains('OCA\\PetStore\\Controller\\DashboardController', $services);
        $this->assertContains('OCA\\PetStore\\Controller\\PreferencesController', $services);
        $this->assertContains('OCA\\PetStore\\Controller\\SettingsController', $services);
        $this->assertContains('OCA\\PetStore\\Controller\\HealthController', $services);
        $this->assertContains('OCA\\PetStore\\Controller\\MetricsController', $services);

        // Repair steps, admin settings, section, service shims.
        $this->assertContains('OCA\\PetStore\\Repair\\InitializeSettings', $services);
        $this->assertContains('OCA\\PetStore\\Repair\\InitializeActions', $services);
        $this->assertContains('OCA\\PetStore\\Settings\\AdminSettings', $services);
        $this->assertContains('OCA\\PetStore\\Sections\\SettingsSection', $services);
        $this->assertContains('OCA\\PetStore\\Service\\SettingsService', $services);
        $this->assertContains('OCA\\PetStore\\Service\\ActionAuthService', $services);

        // Deep-link listener wired against OR's event by string name.
        $this->assertSame('OCA\\OpenRegister\\Event\\DeepLinkRegistrationEvent', $context->listeners[0]['event']);
        $this->assertSame('OCA\\PetStore\\Listener\\DeepLinkRegistrationListener', $context->listeners[0]['listener']);

        // Widget + MCP passthrough.
        $this->assertSame(['OCA\\PetStore\\Dashboard\\ExampleWidget'], $context->widgets);
        $this->assertSame('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::petstore', $context->aliases[0]['alias']);
    }//end testRegistersLeafControllerServiceNames()

    public function testStudlyCaseFallbackFromMultiWordAppId(): void
    {
        // With no explicit namespace, a StudlyCase guess from the id is used.
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'software_catalog');
        $this->assertContains('OCA\\SoftwareCatalog\\Controller\\SettingsController', $context->services);
    }//end testStudlyCaseFallbackFromMultiWordAppId()

    public function testExplicitNamespaceOptionDrivesAllSubNamespaces(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'opencatalogi', ['namespace' => 'OCA\\OpenCatalogi']);

        $this->assertContains('OCA\\OpenCatalogi\\Controller\\SettingsController', $context->services);
        $this->assertContains('OCA\\OpenCatalogi\\Repair\\InitializeSettings', $context->services);
        $this->assertContains('OCA\\OpenCatalogi\\Settings\\AdminSettings', $context->services);
    }//end testExplicitNamespaceOptionDrivesAllSubNamespaces()

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRegistrationIsLazyAndDoesNotAutoloadGenerics(): void
    {
        $context = new RecordingRegistrationContext();

        // The whole point: bootstrap a leaf with OpenRegister "disabled" — the
        // generic classes must not be loaded merely by registering, so NC
        // bootstrap survives a missing/disabled OR.
        Bootstrap::register($context, 'petstore');

        $this->assertFalse(
            class_exists('OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController', false),
            'registering must not autoload the generic controller (lazy alias)'
        );
        $this->assertFalse(
            class_exists('OCA\\OpenRegister\\AppHost\\Service\\AppHostSettingsService', false),
            'registering must not autoload the generic settings service (lazy alias)'
        );
    }//end testRegistrationIsLazyAndDoesNotAutoloadGenerics()

    public function testRegistersSettingsPlaneConsumables(): void
    {
        // ADR-066 settings plane: the generic settings service + register
        // config resolver are registered (appended AFTER the pre-existing
        // registrations — load-order contract) under both the generic name
        // and, for the resolver, the leaf's conventional service name.
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStore']);
        $services = $context->services;

        $this->assertContains('OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService', $services);
        $this->assertContains('OCA\\OpenRegister\\AppHost\\Service\\RegisterConfigResolver', $services);
        $this->assertContains('OCA\\PetStore\\Service\\RegisterConfigResolver', $services);

        // Pre-existing registrations must still be present and precede the new
        // ones (append-only — the Bootstrap load-order incident contract).
        $settingsShim = array_search('OCA\\PetStore\\Service\\SettingsService', $services, true);
        $plane        = array_search('OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService', $services, true);
        $this->assertNotFalse($settingsShim);
        $this->assertNotFalse($plane);
        $this->assertLessThan($plane, $settingsShim, 'new settings-plane registrations must be appended, never reordered before existing ones');
    }//end testRegistersSettingsPlaneConsumables()

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSettingsPlaneRegistrationIsLazy(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore');

        $this->assertFalse(
            class_exists('OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService', false),
            'registering must not autoload the generic settings-plane service (lazy alias)'
        );
        $this->assertFalse(
            class_exists('OCA\\OpenRegister\\AppHost\\Service\\RegisterConfigResolver', false),
            'registering must not autoload the register config resolver (lazy alias)'
        );
    }//end testSettingsPlaneRegistrationIsLazy()

    public function testObservabilityOptOutSkipsHealthMetrics(): void
    {
        $context = new RecordingRegistrationContext();
        Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStore', 'observability' => false]);
        $services = $context->services;

        $this->assertNotContains('OCA\\PetStore\\Controller\\HealthController', $services);
        $this->assertNotContains('OCA\\PetStore\\Controller\\MetricsController', $services);
        $this->assertContains('OCA\\PetStore\\Controller\\SettingsController', $services);
    }//end testObservabilityOptOutSkipsHealthMetrics()
}//end class
