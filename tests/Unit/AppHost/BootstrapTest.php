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

require_once __DIR__ . '/RecordingRegistrationContext.php';
require_once __DIR__ . '/LeafOwnControllerFixture.php';

/**
 * Bootstrap::register() must alias the leaf controller class names to the
 * generics and must NOT autoload any generic class while doing so (lazy).
 */
class BootstrapTest extends TestCase {
	public function testRegistersLeafControllerServiceNames(): void {
		$context = new RecordingRegistrationContext();

		Bootstrap::register($context, 'petstore', [
			'namespace' => 'OCA\\PetStoreFixture',
			'dashboardWidgets' => ['OCA\\PetStoreFixture\\Dashboard\\ExampleWidget'],
			'mcpProvider' => 'OCA\\PetStoreFixture\\Mcp\\ExampleToolProvider',
		]);
		$services = $context->services;

		// Leaf conventional controller class names are aliased.
		$this->assertContains('OCA\\PetStoreFixture\\Controller\\DashboardController', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Controller\\PreferencesController', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Controller\\SettingsController', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Controller\\HealthController', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Controller\\MetricsController', $services);

		// Repair steps, admin settings, section, service shims.
		$this->assertContains('OCA\\PetStoreFixture\\Repair\\InitializeSettings', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Repair\\InitializeActions', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Settings\\AdminSettings', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Sections\\SettingsSection', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Service\\SettingsService', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Service\\ActionAuthService', $services);

		// Deep-link listener wired against OR's event by string name.
		$this->assertSame('OCA\\OpenRegister\\Event\\DeepLinkRegistrationEvent', $context->listeners[0]['event']);
		$this->assertSame('OCA\\PetStoreFixture\\Listener\\DeepLinkRegistrationListener', $context->listeners[0]['listener']);

		// Widget + MCP passthrough.
		$this->assertSame(['OCA\\PetStoreFixture\\Dashboard\\ExampleWidget'], $context->widgets);
		$this->assertSame('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::petstore', $context->aliases[0]['alias']);
	}//end testRegistersLeafControllerServiceNames()

	/**
	 * An app that binds its controllers by hand can still bind the store one.
	 *
	 * `Routes::standard()` declares /api/store/items for every adopter, but
	 * the binding lives in this class. decidiq, filinq and planninq took the
	 * route table without calling register(), and each returned HTTP 500 on a
	 * route it had never asked for. This is the one call that fixes that, so
	 * it has to work standing alone.
	 *
	 * @return void
	 */
	public function testAliasStoreControllerBindsWithoutTheFullBootstrap(): void {
		$context = new RecordingRegistrationContext();

		Bootstrap::aliasStoreController(
			context: $context,
			appId: 'petstore',
			controllerNs: 'OCA\\PetStoreFixture\\Controller'
		);

		$this->assertContains('OCA\\PetStoreFixture\\Controller\\StoreController', $context->services);
	}//end testAliasStoreControllerBindsWithoutTheFullBootstrap()

	/**
	 * A trailing separator on the namespace does not double the separator.
	 *
	 * @return void
	 */
	public function testAliasStoreControllerToleratesATrailingSeparator(): void {
		$context = new RecordingRegistrationContext();

		Bootstrap::aliasStoreController(
			context: $context,
			appId: 'petstore',
			controllerNs: 'OCA\\PetStoreFixture\\Controller\\'
		);

		$this->assertContains('OCA\\PetStoreFixture\\Controller\\StoreController', $context->services);
	}//end testAliasStoreControllerToleratesATrailingSeparator()

	public function testStudlyCaseFallbackFromMultiWordAppId(): void {
		// With no explicit namespace, a StudlyCase guess from the id is used.
		//
		// The id names an app that DOES NOT EXIST, deliberately. Bootstrap
		// aliases a leaf controller only when the leaf does not define one, and
		// `class_exists()` autoloads — so naming a real installed app here makes
		// the assertion depend on which apps happen to be present, passing in CI
		// and failing on a developer's instance.
		$context = new RecordingRegistrationContext();
		Bootstrap::register($context, 'pet_store_fixture');
		$this->assertContains('OCA\\PetStoreFixture\\Controller\\SettingsController', $context->services);
	}//end testStudlyCaseFallbackFromMultiWordAppId()

	public function testExplicitNamespaceOptionDrivesAllSubNamespaces(): void {
		$context = new RecordingRegistrationContext();
		Bootstrap::register($context, 'opencatalogi', ['namespace' => 'OCA\\OpenCatalogiFixture']);

		$this->assertContains('OCA\\OpenCatalogiFixture\\Controller\\SettingsController', $context->services);
		$this->assertContains('OCA\\OpenCatalogiFixture\\Repair\\InitializeSettings', $context->services);
		$this->assertContains('OCA\\OpenCatalogiFixture\\Settings\\AdminSettings', $context->services);
	}//end testExplicitNamespaceOptionDrivesAllSubNamespaces()

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	/**
	 * This assertion is only meaningful in a FRESH process.
	 *
	 * `class_exists($name, false)` asks "is this class already loaded?", so the
	 * answer depends on everything that ran before it in the same process — and
	 * the factory-chain tests deliberately resolve these very generics to real
	 * classes. Sharing a process, this test reports that registration autoloads
	 * the generic when in fact a sibling test loaded it, which is a false
	 * accusation against the lazy-alias behaviour it exists to protect.
	 *
	 */
	public function testRegistrationIsLazyAndDoesNotAutoloadGenerics(): void {
		$context = new RecordingRegistrationContext();

		// The whole point: bootstrap a leaf with OpenRegister "disabled" — the
		// generic classes must not be loaded merely by registering, so NC
		// bootstrap survives a missing/disabled OR.
		Bootstrap::register($context, 'pet_store_fixture');

		// Laziness is asserted as "the factory was REGISTERED and never
		// INVOKED", not as "the generic class is not loaded".
		//
		// `class_exists($name, false)` reports whether a class is loaded in
		// THIS PROCESS, and the suite's bootstrap loads OpenRegister's own
		// classes before any test runs — so the old assertion could not pass
		// here however lazy the registration was, and could not fail if the
		// registration became eager. It measured the test harness, not the code.
		$registered = $context->factories;
		$this->assertNotEmpty($registered, 'registration produced no service factories at all');

		foreach ($registered as $name => $factory) {
			$this->assertInstanceOf(
				\Closure::class,
				$factory,
				sprintf('%s was registered as something other than a deferred factory', $name)
			);
		}

		// Nothing resolved: an eager registration would have had to build the
		// generic to hand it over, and `$context` never asked any factory for a
		// value.
		$this->assertSame(
			count($context->services),
			count($registered),
			'a service was registered without a factory, so it could not have been deferred'
		);
	}//end testRegistrationIsLazyAndDoesNotAutoloadGenerics()

	public function testRegistersSettingsPlaneConsumables(): void {
		// ADR-066 settings plane: the generic settings service + register
		// config resolver are registered (appended AFTER the pre-existing
		// registrations — load-order contract) under both the generic name
		// and, for the resolver, the leaf's conventional service name.
		$context = new RecordingRegistrationContext();
		Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStoreFixture']);
		$services = $context->services;

		$this->assertContains('OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService', $services);
		$this->assertContains('OCA\\OpenRegister\\AppHost\\Service\\RegisterConfigResolver', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Service\\RegisterConfigResolver', $services);

		// Pre-existing registrations must still be present and precede the new
		// ones (append-only — the Bootstrap load-order incident contract).
		$settingsShim = array_search('OCA\\PetStoreFixture\\Service\\SettingsService', $services, true);
		$plane = array_search('OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService', $services, true);
		$this->assertNotFalse($settingsShim);
		$this->assertNotFalse($plane);
		$this->assertLessThan($plane, $settingsShim, 'new settings-plane registrations must be appended, never reordered before existing ones');
	}//end testRegistersSettingsPlaneConsumables()

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testSettingsPlaneRegistrationIsLazy(): void {
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

	public function testObservabilityOptOutSkipsHealthMetrics(): void {
		$context = new RecordingRegistrationContext();
		Bootstrap::register($context, 'petstore', ['namespace' => 'OCA\\PetStoreFixture', 'observability' => false]);
		$services = $context->services;

		$this->assertNotContains('OCA\\PetStoreFixture\\Controller\\HealthController', $services);
		$this->assertNotContains('OCA\\PetStoreFixture\\Controller\\MetricsController', $services);
		$this->assertContains('OCA\\PetStoreFixture\\Controller\\SettingsController', $services);
	}//end testObservabilityOptOutSkipsHealthMetrics()

	/**
	 * A leaf app that defines its own controller must KEEP it.
	 *
	 * `registerService()` overrides autowiring, so aliasing unconditionally
	 * shadowed the consuming app's controller: routes pointing at a method
	 * only the leaf defines (`dashboard#summary`) 500'd, and response-level
	 * behaviour the leaf applied — a CSP built with `allowEvalWasm(true)` —
	 * never ran, so the served CSP lacked `wasm-unsafe-eval` and blocked
	 * Argon2 WASM (share/export/import).
	 */
	public function testDoesNotShadowAControllerTheLeafAppDefinesItself(): void {
		$context = new RecordingRegistrationContext();
		Bootstrap::register($context, 'leafwithowndashboard', ['namespace' => 'OCA\\LeafWithOwnDashboard']);
		$services = $context->services;

		// Positive control: the fixture class really is loadable, so a false
		// "not registered" cannot come from a typo'd class name.
		$this->assertTrue(
			class_exists('OCA\\LeafWithOwnDashboard\\Controller\\DashboardController'),
			'fixture leaf controller must exist, otherwise this test proves nothing'
		);

		$this->assertNotContains(
			'OCA\\LeafWithOwnDashboard\\Controller\\DashboardController',
			$services,
			'AppHost must not alias its generic controller over one the leaf app ships itself'
		);

		// …and the controllers the leaf does NOT define are still aliased, so
		// the opt-in generic behaviour is not lost wholesale.
		$this->assertContains('OCA\\LeafWithOwnDashboard\\Controller\\SettingsController', $services);
		$this->assertContains('OCA\\LeafWithOwnDashboard\\Controller\\PreferencesController', $services);
	}//end testDoesNotShadowAControllerTheLeafAppDefinesItself()
}//end class
