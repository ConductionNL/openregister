<?php

/**
 * Unit tests for IntegrationsCapability — the OCS capabilities surface
 * that advertises the pluggable integration registry (ADR-019).
 *
 * Covers:
 *  - payload shape: openregister.integrations.{contractVersion, registered, providers}
 *  - per-provider descriptor fields (id, label, requiredApp, available,
 *    storageStrategy, surfaces)
 *  - `available` reflects IAppManager::isEnabledForUser() for the backing app
 *  - built-in providers (requiredApp null) are always available
 *  - role redaction: admins get operational fields, non-admins do not
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/proposal.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\Capabilities\IntegrationsCapability;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Capabilities\IntegrationsCapabilityServices;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCA\OpenRegister\Service\SemanticProviderCatalogue;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * In-memory provider stub for capability descriptor assertions.
 */
class _CapStubProvider extends AbstractIntegrationProvider {

	public function __construct(
		private string $id = 'stub',
		private ?string $requiredApp = null,
		private string $storage = 'magic-column',
		private bool $enabled = true,
	) {
	}//end __construct()

	public function getId(): string {
		return $this->id;
	}//end getId()

	public function getLabel(): string {
		return ucfirst($this->id);
	}//end getLabel()

	public function getIcon(): string {
		return 'Cube';
	}//end getIcon()

	public function getRequiredApp(): ?string {
		return $this->requiredApp;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return $this->storage;
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->enabled;
	}//end isEnabled()

	public function list(ObjectRef $ref, array $filters = []): array {
		return [];
	}//end list()
}//end class

/**
 * Unit tests for IntegrationsCapability.
 */
class IntegrationsCapabilityTest extends TestCase {

	/**
	 * Build a registry seeded with the given providers.
	 *
	 * @param array<int, AbstractIntegrationProvider> $providers Providers.
	 *
	 * @return IntegrationRegistry
	 */
	private function registryWith(array $providers): IntegrationRegistry {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->withProviders($providers);
		return $registry;
	}//end registryWith()

	/**
	 * Build a user session resolving to a user with the given uid (or null).
	 *
	 * @param string|null $uid User id, or null for an unauthenticated session.
	 *
	 * @return IUserSession
	 */
	private function userSession(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end userSession()

	/**
	 * Build a group manager with a fixed admin verdict.
	 *
	 * @param bool $isAdmin Whether the user is considered admin.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(bool $isAdmin): IGroupManager {
		$gm = $this->createMock(IGroupManager::class);
		$gm->method('isAdmin')->willReturn($isAdmin);
		return $gm;
	}//end groupManager()

	/**
	 * Build an app manager that reports the given app ids as enabled.
	 *
	 * @param array<int, string> $enabledApps Enabled app ids.
	 *
	 * @return IAppManager
	 */
	private function appManager(array $enabledApps): IAppManager {
		$am = $this->createMock(IAppManager::class);
		$am->method('isEnabledForUser')->willReturnCallback(
			fn ($appId) => in_array($appId, $enabledApps, true)
		);
		return $am;
	}//end appManager()

	/**
	 * Build a leaf registry mock with an empty leaf catalogue.
	 *
	 * @return LeafRegistry
	 */
	private function leafRegistry(): LeafRegistry {
		return $this->createMock(LeafRegistry::class);
	}//end leafRegistry()

	/**
	 * The capability publishes the ADR-019 discovery shape.
	 *
	 * @return void
	 */
	public function testPayloadShape(): void {
		$cap = new IntegrationsCapability(
			registry: $this->registryWith([new _CapStubProvider(id: 'files')]),
			userSession: $this->userSession(uid: 'alice'),
			groupManager: $this->groupManager(isAdmin: false),
			services: new IntegrationsCapabilityServices(
				appManager: $this->appManager(enabledApps: []),
				leafRegistry: $this->leafRegistry()
			)
		);

		$payload = $cap->getCapabilities();

		$this->assertArrayHasKey('openregister', $payload);
		$integrations = $payload['openregister']['integrations'];
		$this->assertSame(IntegrationsCapability::CONTRACT_VERSION, $integrations['contractVersion']);
		$this->assertSame(IntegrationsCapability::CONTRACT_VERSION, $integrations['version']);
		$this->assertSame(['files'], $integrations['registered']);
		$this->assertCount(1, $integrations['providers']);
	}//end testPayloadShape()

	/**
	 * Built-in providers (requiredApp null) are always available.
	 *
	 * @return void
	 */
	public function testBuiltinProviderAlwaysAvailable(): void {
		$cap = new IntegrationsCapability(
			registry: $this->registryWith([new _CapStubProvider(id: 'tags', requiredApp: null)]),
			userSession: $this->userSession(uid: 'alice'),
			groupManager: $this->groupManager(isAdmin: false),
			services: new IntegrationsCapabilityServices(
				appManager: $this->appManager(enabledApps: []),
				leafRegistry: $this->leafRegistry()
			)
		);

		$row = $cap->getCapabilities()['openregister']['integrations']['providers'][0];

		$this->assertNull($row['requiredApp']);
		$this->assertTrue($row['available']);
	}//end testBuiltinProviderAlwaysAvailable()

	/**
	 * `available` reflects whether the backing NC app is installed.
	 *
	 * @return void
	 */
	public function testAvailableReflectsInstalledApp(): void {
		$registry = $this->registryWith(
			[
				new _CapStubProvider(id: 'calendar', requiredApp: 'calendar'),
				new _CapStubProvider(id: 'deck', requiredApp: 'deck'),
			]
		);

		$cap = new IntegrationsCapability(
			registry: $registry,
			userSession: $this->userSession(uid: 'alice'),
			groupManager: $this->groupManager(isAdmin: false),
			// Only `calendar` is installed; `deck` is not.
			services: new IntegrationsCapabilityServices(
				appManager: $this->appManager(enabledApps: ['calendar']),
				leafRegistry: $this->leafRegistry()
			)
		);

		$rows = $cap->getCapabilities()['openregister']['integrations']['providers'];
		$byId = [];
		foreach ($rows as $row) {
			$byId[$row['id']] = $row;
		}

		$this->assertTrue($byId['calendar']['available']);
		$this->assertSame('calendar', $byId['calendar']['requiredApp']);
		$this->assertFalse($byId['deck']['available']);
		$this->assertSame('deck', $byId['deck']['requiredApp']);
	}//end testAvailableReflectsInstalledApp()

	/**
	 * Non-admins get the public surface only (no operational fields).
	 *
	 * @return void
	 */
	public function testNonAdminRedaction(): void {
		$cap = new IntegrationsCapability(
			registry: $this->registryWith([new _CapStubProvider(id: 'files')]),
			userSession: $this->userSession(uid: 'bob'),
			groupManager: $this->groupManager(isAdmin: false),
			services: new IntegrationsCapabilityServices(
				appManager: $this->appManager(enabledApps: []),
				leafRegistry: $this->leafRegistry()
			)
		);

		$row = $cap->getCapabilities()['openregister']['integrations']['providers'][0];

		$this->assertArrayHasKey('id', $row);
		$this->assertArrayHasKey('available', $row);
		$this->assertArrayNotHasKey('requiresPermission', $row);
		$this->assertArrayNotHasKey('authStatus', $row);
		$this->assertArrayNotHasKey('openConnectorSource', $row);
	}//end testNonAdminRedaction()

	/**
	 * Admins additionally receive the operational fields.
	 *
	 * @return void
	 */
	public function testAdminGetsOperationalFields(): void {
		$cap = new IntegrationsCapability(
			registry: $this->registryWith([new _CapStubProvider(id: 'files')]),
			userSession: $this->userSession(uid: 'admin'),
			groupManager: $this->groupManager(isAdmin: true),
			services: new IntegrationsCapabilityServices(
				appManager: $this->appManager(enabledApps: []),
				leafRegistry: $this->leafRegistry()
			)
		);

		$row = $cap->getCapabilities()['openregister']['integrations']['providers'][0];

		$this->assertArrayHasKey('requiresPermission', $row);
		$this->assertArrayHasKey('authStatus', $row);
		$this->assertArrayHasKey('openConnectorSource', $row);
	}//end testAdminGetsOperationalFields()

	/**
	 * Build the capability with the given semantic provider catalogue.
	 *
	 * @param SemanticProviderCatalogue|null $catalogue The catalogue, or null when not wired.
	 *
	 * @return IntegrationsCapability
	 */
	private function capabilityWithProviders(?SemanticProviderCatalogue $catalogue): IntegrationsCapability {
		return new IntegrationsCapability(
			registry: $this->registryWith([]),
			userSession: $this->userSession(uid: 'alice'),
			groupManager: $this->groupManager(isAdmin: false),
			services: new IntegrationsCapabilityServices(
				appManager: $this->appManager(enabledApps: []),
				leafRegistry: $this->leafRegistry(),
				semanticProviders: $catalogue
			)
		);
	}//end capabilityWithProviders()

	/**
	 * The semantic provider rows are advertised as the catalogue lists them.
	 *
	 * @return void
	 */
	public function testSemanticProvidersAreAdvertised(): void {
		$row = [
			'uri' => 'https://schema.org/Organization',
			'register' => 3,
			'registerSlug' => 'finance',
			'schema' => 7,
			'schemaSlug' => 'payee',
			'appId' => 'shillinq',
			'available' => false,
			'reason' => SemanticProviderCatalogue::REASON_APP_NOT_INSTALLED,
		];
		$catalogue = $this->createMock(SemanticProviderCatalogue::class);
		$catalogue->expects($this->once())->method('list')->willReturn([$row]);

		$payload = $this->capabilityWithProviders(catalogue: $catalogue)->getCapabilities();

		$this->assertSame([$row], $payload['openregister']['integrations']['semanticProviders']);
	}//end testSemanticProvidersAreAdvertised()

	/**
	 * Without a catalogue, or when it fails, the list is empty and the capability still builds.
	 *
	 * @return void
	 */
	public function testSemanticProvidersAreEmptyWithoutAWorkingCatalogue(): void {
		$failing = $this->createMock(SemanticProviderCatalogue::class);
		$failing->method('list')->willThrowException(new \RuntimeException('cache down'));

		$this->assertSame([], $this->capabilityWithProviders(catalogue: null)->getCapabilities()['openregister']['integrations']['semanticProviders']);
		$this->assertSame([], $this->capabilityWithProviders(catalogue: $failing)->getCapabilities()['openregister']['integrations']['semanticProviders']);
	}//end testSemanticProvidersAreEmptyWithoutAWorkingCatalogue()
	/**
	 * How often a fresh request's registry built its providers.
	 *
	 * @var int
	 */
	private int $builds = 0;

	/**
	 * An in-memory cache shared by the "requests" of one test.
	 *
	 * @param array<string, mixed> $store The backing store, by reference.
	 *
	 * @return ICache The cache.
	 */
	private function memoryCache(array &$store): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use (&$store): mixed {
				return ($store[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		return $cache;
	}//end memoryCache()

	/**
	 * The capability as one request builds it: a fresh registry whose built-ins load on first read.
	 *
	 * @param ICache      $cache    The cache shared across requests.
	 * @param string      $uid      The reader.
	 * @param string      $language The reader's language.
	 * @param array<string, string> $apps The enabled apps and their versions.
	 *
	 * @return IntegrationsCapability The capability.
	 */
	private function requestCapability(ICache $cache, string $uid='alice', string $language='nl', array $apps=['openregister' => '2.2.0']): IntegrationsCapability {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->setBuiltinLoader(
			function () use ($registry): void {
				$this->builds++;
				$registry->addProvider(new _CapStubProvider(id: 'files'));
				$registry->addProvider(new _CapStubProvider(id: 'deck', requiredApp: 'deck'));
			}
		);

		$appManager = $this->appManager(enabledApps: array_keys($apps));
		$appManager->method('getAppInstalledVersions')->willReturn($apps);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn($language);

		return new IntegrationsCapability(
			registry: $registry,
			userSession: $this->userSession(uid: $uid),
			groupManager: $this->groupManager(isAdmin: false),
			services: new IntegrationsCapabilityServices(
				appManager: $appManager,
				leafRegistry: $this->leafRegistry(),
				cache: $cache,
				l10nFactory: $l10nFactory
			)
		);
	}//end requestCapability()

	/**
	 * A second page render for the same reader takes the rows from the cache and builds no provider.
	 *
	 * @return void
	 */
	public function testASecondRenderForTheSameReaderBuildsNoProvider(): void {
		$store = [];
		$cache = $this->memoryCache(store: $store);

		$first  = $this->requestCapability(cache: $cache)->getCapabilities();
		$second = $this->requestCapability(cache: $cache)->getCapabilities();

		$this->assertSame(1, $this->builds);
		$this->assertSame($first, $second);
		$this->assertSame(['files', 'deck'], $second['openregister']['integrations']['registered']);
		$this->assertFalse($second['openregister']['integrations']['providers'][1]['available']);
	}//end testASecondRenderForTheSameReaderBuildsNoProvider()

	/**
	 * Another reader, language or set of enabled apps gets its own rows.
	 *
	 * @return void
	 */
	public function testAnotherReaderLanguageOrAppSetBuildsItsOwnRows(): void {
		$store = [];
		$cache = $this->memoryCache(store: $store);

		$this->requestCapability(cache: $cache)->getCapabilities();
		$this->requestCapability(cache: $cache, uid: 'bob')->getCapabilities();
		$this->requestCapability(cache: $cache, language: 'en')->getCapabilities();
		$withDeck = $this->requestCapability(cache: $cache, apps: ['openregister' => '2.2.0', 'deck' => '1.16.0'])->getCapabilities();

		$this->assertSame(4, $this->builds);
		$this->assertTrue($withDeck['openregister']['integrations']['providers'][1]['available']);
	}//end testAnotherReaderLanguageOrAppSetBuildsItsOwnRows()

	/**
	 * A cache entry that is not the expected shape is rebuilt, not trusted.
	 *
	 * @return void
	 */
	public function testAMalformedCacheEntryIsRebuilt(): void {
		$store = [];
		$cache = $this->memoryCache(store: $store);
		$this->requestCapability(cache: $cache)->getCapabilities();
		foreach (array_keys($store) as $key) {
			$store[$key] = ['registered' => 'files'];
		}

		$payload = $this->requestCapability(cache: $cache)->getCapabilities();

		$this->assertSame(2, $this->builds);
		$this->assertSame(['files', 'deck'], $payload['openregister']['integrations']['registered']);
	}//end testAMalformedCacheEntryIsRebuilt()
}//end class
