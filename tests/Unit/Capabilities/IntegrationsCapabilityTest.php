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
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * In-memory provider stub for capability descriptor assertions.
 */
class _CapStubProvider extends AbstractIntegrationProvider
{

    public function __construct(
        private string $id = 'stub',
        private ?string $requiredApp = null,
        private string $storage = 'magic-column',
        private bool $enabled = true,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return $this->id;
    }//end getId()

    public function getLabel(): string
    {
        return ucfirst($this->id);
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Cube';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return $this->requiredApp;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return $this->storage;
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->enabled;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return [];
    }//end list()
}//end class

/**
 * Unit tests for IntegrationsCapability.
 */
class IntegrationsCapabilityTest extends TestCase
{

    /**
     * Build a registry seeded with the given providers.
     *
     * @param array<int, AbstractIntegrationProvider> $providers Providers.
     *
     * @return IntegrationRegistry
     */
    private function registryWith(array $providers): IntegrationRegistry
    {
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
    private function userSession(?string $uid): IUserSession
    {
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
    private function groupManager(bool $isAdmin): IGroupManager
    {
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
    private function appManager(array $enabledApps): IAppManager
    {
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
    private function leafRegistry(): LeafRegistry
    {
        return $this->createMock(LeafRegistry::class);
    }//end leafRegistry()

    /**
     * The capability publishes the ADR-019 discovery shape.
     *
     * @return void
     */
    public function testPayloadShape(): void
    {
        $cap = new IntegrationsCapability(
            registry: $this->registryWith([new _CapStubProvider(id: 'files')]),
            userSession: $this->userSession(uid: 'alice'),
            groupManager: $this->groupManager(isAdmin: false),
            appManager: $this->appManager(enabledApps: []),
            leafRegistry: $this->leafRegistry()
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
    public function testBuiltinProviderAlwaysAvailable(): void
    {
        $cap = new IntegrationsCapability(
            registry: $this->registryWith([new _CapStubProvider(id: 'tags', requiredApp: null)]),
            userSession: $this->userSession(uid: 'alice'),
            groupManager: $this->groupManager(isAdmin: false),
            appManager: $this->appManager(enabledApps: []),
            leafRegistry: $this->leafRegistry()
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
    public function testAvailableReflectsInstalledApp(): void
    {
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
            appManager: $this->appManager(enabledApps: ['calendar']),
            leafRegistry: $this->leafRegistry()
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
    public function testNonAdminRedaction(): void
    {
        $cap = new IntegrationsCapability(
            registry: $this->registryWith([new _CapStubProvider(id: 'files')]),
            userSession: $this->userSession(uid: 'bob'),
            groupManager: $this->groupManager(isAdmin: false),
            appManager: $this->appManager(enabledApps: []),
            leafRegistry: $this->leafRegistry()
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
    public function testAdminGetsOperationalFields(): void
    {
        $cap = new IntegrationsCapability(
            registry: $this->registryWith([new _CapStubProvider(id: 'files')]),
            userSession: $this->userSession(uid: 'admin'),
            groupManager: $this->groupManager(isAdmin: true),
            appManager: $this->appManager(enabledApps: []),
            leafRegistry: $this->leafRegistry()
        );

        $row = $cap->getCapabilities()['openregister']['integrations']['providers'][0];

        $this->assertArrayHasKey('requiresPermission', $row);
        $this->assertArrayHasKey('authStatus', $row);
        $this->assertArrayHasKey('openConnectorSource', $row);
    }//end testAdminGetsOperationalFields()
}//end class
