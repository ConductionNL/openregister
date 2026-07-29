<?php

/**
 * Unit tests for LeafRegistry + the app-local data-provider path + OCS discovery.
 *
 * Covers the spec's PHPUnit tasks:
 *  - an announced leaf reaches the catalogue; built-ins still present;
 *  - a throwing listener is swallowed and other leaves survive;
 *  - empty-kinds / unknown-kind rejected; data-provider requires a provider;
 *  - a `mount` renderMode leaf is accepted; an unknown renderMode is rejected;
 *    discovery reports each leaf's renderMode;
 *  - first-wins on duplicate id (ADR-013); namespaced ids do not collide;
 *  - a data-provider leaf lands on the IntegrationRegistry and is reachable
 *    through the lazy loader hook;
 *  - app-local list() returns sibling-store items and persists nothing;
 *    empty list on no items; create() persists a note; a read-only leaf lets
 *    create() throw while list() still works;
 *  - OCS describeForCapabilities reports leaves + usability; a disabled required
 *    app reports unusable;
 *  - the render-and-read boundary (ADR-066): the provider contract exposes no
 *    business-action verb.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An app-local notes provider backed by an in-memory "sibling app store".
 *
 * Models exactly the canonical minimum: list() reads the notes it holds for an
 * object, create() appends one. OpenRegister persists none of it — the data
 * lives here, in the provider's own memory (standing in for the app's store).
 */
class _AppLocalNotesProvider extends AbstractIntegrationProvider
{

    /**
     * The sibling app's own store, keyed by objectId.
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    public array $store = [];

    public function __construct(
        private string $id='acme-notes',
        private ?string $requiredApp='acme',
        private bool $enabled=true,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return $this->id;
    }//end getId()

    public function getLabel(): string
    {
        return 'Notes';
    }//end getLabel()

    public function getIcon(): string
    {
        return 'NoteText';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return $this->requiredApp;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'app-local';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->enabled;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return ($this->store[$objectId] ?? []);
    }//end list()

    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $note                      = ['id' => (string) (count(($this->store[$objectId] ?? [])) + 1), 'body' => ($payload['body'] ?? '')];
        $this->store[$objectId][]  = $note;
        return $note;
    }//end create()
}//end class

/**
 * A read-only app-local provider: list() works, create() falls through to the
 * AbstractIntegrationProvider default that throws NotImplementedException.
 */
class _ReadOnlyAppLocalProvider extends AbstractIntegrationProvider
{

    public function getId(): string
    {
        return 'acme-readonly';
    }//end getId()

    public function getLabel(): string
    {
        return 'Read only';
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Eye';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return 'acme';
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'app-local';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [['id' => '1', 'body' => 'read-only item']];
    }//end list()
}//end class

/**
 * A trivial built-in-shaped provider used to prove built-ins survive leaf
 * collection.
 */
class _BuiltinShapedProvider extends AbstractIntegrationProvider
{

    public function getId(): string
    {
        return 'files';
    }//end getId()

    public function getLabel(): string
    {
        return 'Files';
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Paperclip';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'magic-column';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [];
    }//end list()
}//end class

/**
 * Unit tests for LeafRegistry.
 */
class LeafRegistryTest extends TestCase
{

    /**
     * Build a LeafRegistry whose dispatcher runs the supplied listeners against
     * the collect-event.
     *
     * @param array<int, callable>     $listeners           Listeners applied to the event.
     * @param IntegrationRegistry|null $integrationRegistry Optional shared provider registry.
     * @param IAppManager|null         $appManager          Optional app-manager mock.
     *
     * @return LeafRegistry
     */
    private function makeRegistry(
        array $listeners,
        ?IntegrationRegistry $integrationRegistry=null,
        ?IAppManager $appManager=null
    ): LeafRegistry {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            function (object $event) use ($listeners) {
                foreach ($listeners as $listener) {
                    $listener($event);
                }

                return $event;
            }
        );

        if ($appManager === null) {
            $appManager = $this->createMock(IAppManager::class);
            $appManager->method('isEnabledForUser')->willReturn(true);
        }

        return new LeafRegistry(
            eventDispatcher: $dispatcher,
            integrationRegistry: ($integrationRegistry ?? new IntegrationRegistry(new NullLogger())),
            appManager: $appManager,
            logger: new NullLogger()
        );
    }//end makeRegistry()

    /**
     * An announced leaf reaches the catalogue.
     *
     * @return void
     */
    public function testAnnouncedLeafReachesCatalogue(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(
                    new LeafDescriptor(id: 'acme-tab', label: 'Tab', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE])
                );
            },
        ]);

        $ids = array_map(fn ($d) => $d->getId(), $registry->getDescriptors());
        $this->assertSame(['acme-tab'], $ids);
    }//end testAnnouncedLeafReachesCatalogue()

    /**
     * A data-provider leaf lands on the shared IntegrationRegistry and is
     * reachable through the lazy loader hook.
     *
     * @return void
     */
    public function testDataProviderLeafLandsOnIntegrationRegistryViaLoaderHook(): void
    {
        $integrationRegistry = new IntegrationRegistry(new NullLogger());
        // A built-in present before leaf collection must survive.
        $integrationRegistry->addProvider(new _BuiltinShapedProvider());

        $provider = new _AppLocalNotesProvider();
        $leafReg  = $this->makeRegistry(
            [
                function (RegisterLeafProvidersEvent $event) use ($provider) {
                    $event->registerLeaf(
                        new LeafDescriptor(
                            id: 'acme-notes',
                            label: 'Notes',
                            icon: 'NoteText',
                            kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
                            requiredApp: 'acme'
                        ),
                        $provider
                    );
                },
            ],
            $integrationRegistry
        );

        // Wire the lazy loader: reading the IntegrationRegistry now triggers
        // leaf collection, exactly as Application::bootLeafRegistry() sets up.
        $integrationRegistry->setLeafLoader(fn () => $leafReg->getDescriptors());

        // Built-in present alongside the announced leaf's provider.
        $ids = $integrationRegistry->listIds();
        $this->assertContains('files', $ids, 'built-in provider survives leaf collection');
        $this->assertContains('acme-notes', $ids, 'announced data-provider is reachable');
        $this->assertSame($provider, $integrationRegistry->get('acme-notes'));
    }//end testDataProviderLeafLandsOnIntegrationRegistryViaLoaderHook()

    /**
     * A throwing listener is swallowed; leaves registered before it survive.
     *
     * @return void
     */
    public function testThrowingListenerDoesNotBreakDiscovery(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(
                    new LeafDescriptor(id: 'good-leaf', label: 'Good', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE])
                );
            },
            function (RegisterLeafProvidersEvent $event) {
                throw new \RuntimeException('listener blew up');
            },
        ]);

        $ids = array_map(fn ($d) => $d->getId(), $registry->getDescriptors());
        $this->assertSame(['good-leaf'], $ids, 'the healthy leaf survives a throwing listener');
    }//end testThrowingListenerDoesNotBreakDiscovery()

    /**
     * A descriptor with an empty kinds set is rejected.
     *
     * @return void
     */
    public function testEmptyKindsIsRejected(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(new LeafDescriptor(id: 'no-kinds', label: 'X', icon: 'Cube', kinds: []));
                $event->registerLeaf(new LeafDescriptor(id: 'ok', label: 'Ok', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]));
            },
        ]);

        $ids = array_map(fn ($d) => $d->getId(), $registry->getDescriptors());
        $this->assertSame(['ok'], $ids, 'empty-kinds rejected, the rest of the catalogue unaffected');
    }//end testEmptyKindsIsRejected()

    /**
     * An unknown kind is rejected.
     *
     * @return void
     */
    public function testUnknownKindIsRejected(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(new LeafDescriptor(id: 'weird', label: 'X', icon: 'Cube', kinds: ['teleporter']));
            },
        ]);

        $this->assertSame([], $registry->getDescriptors());
    }//end testUnknownKindIsRejected()

    /**
     * A render-surface leaf declaring renderMode `mount` is accepted with no
     * bespoke tab/widget expectation (the mount/unmount pair lives on the JS
     * layer, so the server only records the mode).
     *
     * @return void
     */
    public function testMountRenderModeLeafIsAccepted(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(
                    new LeafDescriptor(
                        id: 'hermiq-agent',
                        label: 'Agent',
                        icon: 'Robot',
                        kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
                        surfaces: ['single-entity'],
                        renderMode: LeafDescriptor::RENDER_MODE_MOUNT
                    )
                );
            },
        ]);

        $descriptors = $registry->getDescriptors();
        $this->assertCount(1, $descriptors);
        $this->assertSame('mount', $descriptors[0]->getRenderMode());
    }//end testMountRenderModeLeafIsAccepted()

    /**
     * An unknown renderMode is rejected and skipped, leaving the rest of the
     * catalogue unaffected — mirroring the unknown-kind rule.
     *
     * @return void
     */
    public function testUnknownRenderModeIsRejected(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(
                    new LeafDescriptor(
                        id: 'bad-mode',
                        label: 'X',
                        icon: 'Cube',
                        kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
                        renderMode: 'teleport'
                    )
                );
                $event->registerLeaf(
                    new LeafDescriptor(id: 'ok', label: 'Ok', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE])
                );
            },
        ]);

        $ids = array_map(fn ($d) => $d->getId(), $registry->getDescriptors());
        $this->assertSame(['ok'], $ids, 'unknown renderMode rejected, the rest of the catalogue unaffected');
    }//end testUnknownRenderModeIsRejected()

    /**
     * A data-provider kind without an accompanying provider is rejected.
     *
     * @return void
     */
    public function testDataProviderWithoutProviderIsRejected(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(
                    new LeafDescriptor(id: 'acme-notes', label: 'Notes', icon: 'NoteText', kinds: [LeafDescriptor::KIND_DATA_PROVIDER])
                    // No provider supplied.
                );
            },
        ]);

        $this->assertSame([], $registry->getDescriptors(), 'data-provider kind requires a provider');
    }//end testDataProviderWithoutProviderIsRejected()

    /**
     * A render-only leaf needs no provider and is accepted.
     *
     * @return void
     */
    public function testRenderOnlyLeafNeedsNoProvider(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(
                    new LeafDescriptor(id: 'acme-tab', label: 'Tab', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE])
                );
            },
        ]);

        $this->assertCount(1, $registry->getDescriptors());
    }//end testRenderOnlyLeafNeedsNoProvider()

    /**
     * Duplicate id: first registration wins (ADR-013).
     *
     * @return void
     */
    public function testDuplicateIdFirstWins(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(new LeafDescriptor(id: 'dupe', label: 'First', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]));
                $event->registerLeaf(new LeafDescriptor(id: 'dupe', label: 'Second', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]));
            },
        ]);

        $descriptors = $registry->getDescriptors();
        $this->assertCount(1, $descriptors);
        $this->assertSame('First', $descriptors[0]->getLabel(), 'first registration is kept');
    }//end testDuplicateIdFirstWins()

    /**
     * Namespaced ids do not collide.
     *
     * @return void
     */
    public function testNamespacedIdsDoNotCollide(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(new LeafDescriptor(id: 'acme-agent', label: 'A', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]));
                $event->registerLeaf(new LeafDescriptor(id: 'globex-agent', label: 'B', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]));
            },
        ]);

        $ids = array_map(fn ($d) => $d->getId(), $registry->getDescriptors());
        $this->assertSame(['acme-agent', 'globex-agent'], $ids);
    }//end testNamespacedIdsDoNotCollide()

    /**
     * app-local list() returns sibling-store items and OpenRegister persists
     * none of them.
     *
     * @return void
     */
    public function testAppLocalListReturnsSiblingStoreItems(): void
    {
        $provider                 = new _AppLocalNotesProvider();
        $provider->store['obj-1'] = [['id' => '1', 'body' => 'first note']];

        $items = $provider->list('reg', 'schema', 'obj-1');

        $this->assertSame([['id' => '1', 'body' => 'first note']], $items);
        // OpenRegister holds nothing: the only copy is the provider's own store.
        $this->assertSame($provider->store['obj-1'], $items);
    }//end testAppLocalListReturnsSiblingStoreItems()

    /**
     * An object with no items returns an empty list rather than an error.
     *
     * @return void
     */
    public function testAppLocalListEmptyWhenNoItems(): void
    {
        $provider = new _AppLocalNotesProvider();
        $this->assertSame([], $provider->list('reg', 'schema', 'obj-empty'));
    }//end testAppLocalListEmptyWhenNoItems()

    /**
     * create() persists a note in the sibling store; it appears in a subsequent
     * list().
     *
     * @return void
     */
    public function testAppLocalCreatePersistsNoteInSiblingStore(): void
    {
        $provider = new _AppLocalNotesProvider();
        $created  = $provider->create('reg', 'schema', 'obj-1', ['body' => 'hello']);

        $this->assertSame('hello', $created['body']);
        $list = $provider->list('reg', 'schema', 'obj-1');
        $this->assertCount(1, $list);
        $this->assertSame('hello', $list[0]['body']);
    }//end testAppLocalCreatePersistsNoteInSiblingStore()

    /**
     * A read-only app-local leaf lets create() throw while list() still works.
     *
     * @return void
     */
    public function testReadOnlyAppLocalLeafRefusesWritesCleanly(): void
    {
        $provider = new _ReadOnlyAppLocalProvider();

        // The read path is usable.
        $this->assertCount(1, $provider->list('reg', 'schema', 'obj-1'));

        // The write path throws the exact not-implemented shape query-time uses.
        $this->expectException(NotImplementedException::class);
        $provider->create('reg', 'schema', 'obj-1', ['body' => 'nope']);
    }//end testReadOnlyAppLocalLeafRefusesWritesCleanly()

    /**
     * OCS describeForCapabilities reports leaves + usability.
     *
     * @return void
     */
    public function testDescribeForCapabilitiesReportsLeavesAndUsability(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(true);

        $registry = $this->makeRegistry(
            [
                function (RegisterLeafProvidersEvent $event) {
                    $event->registerLeaf(
                        new LeafDescriptor(
                            id: 'acme-notes',
                            label: 'Notes',
                            icon: 'NoteText',
                            kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
                            requiredApp: 'acme',
                            surfaces: ['detail-page']
                        ),
                        new _AppLocalNotesProvider()
                    );
                },
            ],
            null,
            $appManager
        );

        $rows = $registry->describeForCapabilities();
        $this->assertCount(1, $rows);
        $this->assertSame('acme-notes', $rows[0]['id']);
        $this->assertSame(['detail-page'], $rows[0]['surfaces']);
        $this->assertSame([LeafDescriptor::KIND_DATA_PROVIDER], $rows[0]['kinds']);
        // renderMode defaults to `component` on the discovery surface.
        $this->assertSame('component', $rows[0]['renderMode']);
        $this->assertTrue($rows[0]['usable']);
    }//end testDescribeForCapabilitiesReportsLeavesAndUsability()

    /**
     * OCS discovery reports a mount-mode render-surface leaf's renderMode so a
     * manifest app / admin UI learns HOW it renders without loading its JS.
     *
     * @return void
     */
    public function testDescribeForCapabilitiesReportsMountRenderMode(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(
                    new LeafDescriptor(
                        id: 'hermiq-agent',
                        label: 'Agent',
                        icon: 'Robot',
                        kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
                        surfaces: ['single-entity'],
                        renderMode: LeafDescriptor::RENDER_MODE_MOUNT
                    )
                );
            },
        ]);

        $rows = $registry->describeForCapabilities();
        $this->assertCount(1, $rows);
        $this->assertSame('hermiq-agent', $rows[0]['id']);
        $this->assertSame('mount', $rows[0]['renderMode']);
    }//end testDescribeForCapabilitiesReportsMountRenderMode()

    /**
     * A leaf whose required app is disabled reports unusable.
     *
     * @return void
     */
    public function testLeafWithDisabledRequiredAppReportsUnusable(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(false);

        $registry = $this->makeRegistry(
            [
                function (RegisterLeafProvidersEvent $event) {
                    $event->registerLeaf(
                        new LeafDescriptor(id: 'acme-tab', label: 'Tab', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE], requiredApp: 'acme')
                    );
                },
            ],
            null,
            $appManager
        );

        $rows = $registry->describeForCapabilities();
        $this->assertFalse($rows[0]['usable'], 'a disabled required app reports the leaf unusable');
    }//end testLeafWithDisabledRequiredAppReportsUnusable()

    /**
     * A leaf with no required app is always usable.
     *
     * @return void
     */
    public function testLeafWithoutRequiredAppIsAlwaysUsable(): void
    {
        $registry = $this->makeRegistry([
            function (RegisterLeafProvidersEvent $event) {
                $event->registerLeaf(new LeafDescriptor(id: 'core-leaf', label: 'Core', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]));
            },
        ]);

        $rows = $registry->describeForCapabilities();
        $this->assertTrue($rows[0]['usable']);
    }//end testLeafWithoutRequiredAppIsAlwaysUsable()

    /**
     * Render-and-read boundary (ADR-066): the IntegrationProvider contract
     * exposes list + linked-item CRUD only — no business-action verb.
     *
     * @return void
     */
    public function testProviderContractExposesNoCommandPath(): void
    {
        $allowed = [
            'getid', 'getlabel', 'geticon', 'getgroup', 'getrequiredapp',
            'getstoragestrategy', 'getopenconnectorsource', 'isenabled',
            'requirespermission', 'authrequirements', 'list', 'get', 'create',
            'update', 'delete', 'health',
        ];

        $reflection = new \ReflectionClass(IntegrationProvider::class);
        foreach ($reflection->getMethods() as $method) {
            $this->assertContains(
                strtolower($method->getName()),
                $allowed,
                sprintf('IntegrationProvider must expose no command verb; found "%s"', $method->getName())
            );
        }
    }//end testProviderContractExposesNoCommandPath()
}//end class
