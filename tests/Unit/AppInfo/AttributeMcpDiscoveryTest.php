<?php

declare(strict_types=1);

/**
 * Application::collectAttributeMcpProviders() DI-wiring tests (ADR-063 chain 3/3).
 *
 * Mirrors {@see \OCA\OpenRegister\Tests\Unit\Service\Integration\BuiltinProviderDiFactoryTest}'s
 * technique — reflect-invoke the private discovery method directly with a
 * fake container — to exercise the discovery-scope opt-in
 * (`IMcpScannableServices::<appId>` alias) and the collision policy
 * documented on `collectAttributeMcpProviders()`:
 *  - no scannable-services declaration → no provider appended;
 *  - a declared scannable class with attributed methods → one
 *    AttributeToolProvider appended, tools on both surfaces (see
 *    AttributeToolDualSurfaceTest for the full surface exercise);
 *  - an attributed id colliding with a HAND-WRITTEN id is silently
 *    self-suppressed (hand-written wins);
 *  - an attributed id colliding with a schema-DERIVED id is REJECTED with a
 *    logged error (REQ-ATTR-002).
 *
 * CONTAINER TOPOLOGY (#390): the `IMcpScannableServices::<appId>` opt-in
 * alias — and the attributed service instances — live in the OPTING-IN APP'S
 * OWN container, NOT in OR's shared container. These tests therefore register
 * the alias in a distinct per-app container obtained via the
 * `getRegisteredAppContainer()` seam (overridden here) and additionally assert
 * that an alias present ONLY in the shared container is NOT discovered — the
 * exact regression the earlier suite masked by feeding the alias through the
 * shared container.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppInfo
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/changes/or-mcp-tool-attribute/specs/ai-mcp/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Mcp\IMcpScannableServices;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\AttributeFixtureService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\CollidingFixtureService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

require_once __DIR__.'/../Mcp/Fixtures/AttributeFixtureService.php';
require_once __DIR__.'/../Mcp/Fixtures/CollidingFixtureService.php';
require_once __DIR__.'/Fixtures/GlobalOcServerStub.php';

/**
 * Application subclass that overrides the per-app-container seam so tests can
 * inject an explicit appId → container topology (and record which appIds were
 * asked for) WITHOUT touching the global `\OC::$server` service locator.
 */
class RecordingApplication extends Application
{

    /**
     * Explicit appId → app-container map.
     *
     * @var array<string, ContainerInterface>
     */
    public array $appContainers = [];

    /**
     * Every appId passed to getRegisteredAppContainer(), in order.
     *
     * @var list<string>
     */
    public array $requestedAppIds = [];


    /**
     * @param string          $appId  The candidate app id.
     * @param LoggerInterface $logger PSR logger (unused in the fake).
     *
     * @return ContainerInterface|null The mapped app container, or null.
     */
    protected function getRegisteredAppContainer(string $appId, LoggerInterface $logger): ?ContainerInterface
    {
        $this->requestedAppIds[] = $appId;
        return ($this->appContainers[$appId] ?? null);

    }//end getRegisteredAppContainer()
}//end class


/**
 * DI-wiring tests for the attribute-tool discovery step.
 */
class AttributeMcpDiscoveryTest extends TestCase
{


    /**
     * Reflect-invoke `collectAttributeMcpProviders()` on a RecordingApplication
     * wired with an explicit per-app container topology.
     *
     * @param ContainerInterface                 $sharedContainer OR's shared DI container.
     * @param array<string, ContainerInterface>  $appContainers   appId → app-container map.
     * @param LoggerInterface                    $logger          The (mocked) logger.
     * @param array<IMcpToolProvider>            $providers       Providers collected so far (mutated by reference).
     *
     * @return RecordingApplication The app instance (for asserting requestedAppIds).
     */
    private function invokeCollect(
        ContainerInterface $sharedContainer,
        array $appContainers,
        LoggerInterface $logger,
        array &$providers
    ): RecordingApplication {
        // newInstanceWithoutConstructor() — Application::__construct() calls
        // parent::__construct(), which touches \OC::$server; that global NC
        // bootstrap is out of scope for this local unit-test harness (and
        // orthogonal to what's under test here: the discovery method reads
        // only its own parameters + the overridden app-container seam).
        $app = (new ReflectionClass(RecordingApplication::class))->newInstanceWithoutConstructor();
        $app->appContainers = $appContainers;

        $method = new ReflectionMethod(Application::class, 'collectAttributeMcpProviders');
        $method->setAccessible(true);
        $method->invokeArgs($app, [$sharedContainer, $logger, &$providers]);

        return $app;

    }//end invokeCollect()


    /**
     * A minimal IMcpScannableServices implementation declaring the given classes.
     *
     * @param list<class-string> $classNames Classes to declare scannable.
     *
     * @return IMcpScannableServices
     */
    private function scannableDeclaration(array $classNames): IMcpScannableServices
    {
        return new class ($classNames) implements IMcpScannableServices
        {
            /**
             * @param list<class-string> $classNames Classes to declare scannable.
             */
            public function __construct(private readonly array $classNames)
            {
            }

            public function getScannableServiceClasses(): array
            {
                return $this->classNames;
            }
        };

    }//end scannableDeclaration()


    /**
     * A ContainerInterface mock backed by an explicit binding map, falling
     * back to `new $id()` for any class not explicitly bound.
     *
     * @param array<string, mixed> $bindings Key => value/factory-callable.
     *
     * @return ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function container(array $bindings)
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => array_key_exists($id, $bindings)
        );
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($bindings) {
                if (array_key_exists($id, $bindings) === true) {
                    $value = $bindings[$id];
                    return (is_callable($value) === true) ? $value() : $value;
                }

                if (class_exists($id) === true) {
                    return new $id();
                }

                throw new RuntimeException('No binding for '.$id);
            }
        );

        return $container;

    }//end container()


    /**
     * OR's shared container — provides IAppManager (installed-app enumeration)
     * and OR-owned services (AuditTrailMapper), plus any extra bindings.
     *
     * @param list<string>         $appIds Installed app ids to report.
     * @param array<string, mixed> $extra  Extra bindings to merge in.
     *
     * @return ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function sharedContainer(array $appIds, array $extra=[])
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn($appIds);

        $bindings = array_merge(
            [
                'OCP\App\IAppManager'  => $appManager,
                AuditTrailMapper::class => $this->createMock(AuditTrailMapper::class),
            ],
            $extra
        );

        return $this->container($bindings);

    }//end sharedContainer()


    // ── Opt-in discovery ──────────────────────────────────────────────


    public function testNoScannableServicesDeclarationAppendsNothing(): void
    {
        $shared    = $this->sharedContainer(['pipelinq']);
        // App container exists but declares no scannable-services alias.
        $appConers = ['pipelinq' => $this->container([])];
        $logger    = $this->createMock(LoggerInterface::class);

        $providers = [];
        $this->invokeCollect($shared, $appConers, $logger, $providers);

        $this->assertSame([], $providers);

    }//end testNoScannableServicesDeclarationAppendsNothing()


    public function testAppWithoutRegisteredContainerIsSkipped(): void
    {
        $shared = $this->sharedContainer(['pipelinq']);
        // No app container registered for 'pipelinq' → getRegisteredAppContainer
        // returns null → the app is skipped entirely.
        $logger = $this->createMock(LoggerInterface::class);

        $providers = [];
        $app       = $this->invokeCollect($shared, [], $logger, $providers);

        $this->assertSame([], $providers);
        $this->assertSame(['pipelinq'], $app->requestedAppIds, 'The per-app container seam must be consulted with the appId.');

    }//end testAppWithoutRegisteredContainerIsSkipped()


    public function testDeclaredScannableClassInAppContainerIsDiscovered(): void
    {
        $shared    = $this->sharedContainer(['pipelinq']);
        $appConers = [
            'pipelinq' => $this->container(
                [
                    'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq' => $this->scannableDeclaration([AttributeFixtureService::class]),
                ]
            ),
        ];
        $logger = $this->createMock(LoggerInterface::class);

        $providers = [];
        $app       = $this->invokeCollect($shared, $appConers, $logger, $providers);

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(AttributeToolProvider::class, $providers[0]);
        $this->assertSame(['pipelinq'], $app->requestedAppIds);

        $ids = array_column($providers[0]->getTools(), 'id');
        $this->assertContains('pipelinq.createLead', $ids);
        $this->assertContains('pipelinq.logContactmoment', $ids);

    }//end testDeclaredScannableClassInAppContainerIsDiscovered()


    /**
     * REGRESSION (#390): the alias present ONLY in OR's shared container — the
     * exact wiring the earlier suite used — must NOT be discovered, because
     * resolution now goes through the opting-in app's OWN container.
     */
    public function testAliasRegisteredOnlyInSharedContainerIsNotDiscovered(): void
    {
        // Alias lives in the SHARED container (the pre-fix mistake) but the
        // app's OWN container declares nothing.
        $shared = $this->sharedContainer(
            ['pipelinq'],
            ['OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq' => $this->scannableDeclaration([AttributeFixtureService::class])]
        );
        $appConers = ['pipelinq' => $this->container([])];
        $logger    = $this->createMock(LoggerInterface::class);

        $providers = [];
        $this->invokeCollect($shared, $appConers, $logger, $providers);

        $this->assertSame([], $providers, 'The opt-in alias must be resolved from the app container, never the shared container (#390).');

    }//end testAliasRegisteredOnlyInSharedContainerIsNotDiscovered()


    // ── Collision policy ──────────────────────────────────────────────


    public function testAttributedIdCollidingWithHandWrittenIsSilentlySuppressed(): void
    {
        $handWritten = $this->createMock(IMcpToolProvider::class);
        $handWritten->method('getAppId')->willReturn('pipelinq');
        $handWritten->method('getTools')->willReturn(
            [
                [
                    'id'          => 'pipelinq.createLead',
                    'name'        => 'createLead',
                    'description' => 'Hand-written create lead.',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                ],
            ]
        );

        $shared    = $this->sharedContainer(['pipelinq']);
        $appConers = [
            'pipelinq' => $this->container(
                [
                    'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq' => $this->scannableDeclaration([AttributeFixtureService::class]),
                ]
            ),
        ];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $providers = [$handWritten];
        $this->invokeCollect($shared, $appConers, $logger, $providers);

        $this->assertCount(2, $providers);
        $attributeProvider = $providers[1];
        $this->assertInstanceOf(AttributeToolProvider::class, $attributeProvider);

        $ids = array_column($attributeProvider->getTools(), 'id');
        $this->assertNotContains('pipelinq.createLead', $ids, 'Hand-written must win — the derived duplicate must be absent, not merely shadowed.');
        $this->assertContains('pipelinq.logContactmoment', $ids, 'Non-colliding attributed tools must still be registered.');

    }//end testAttributedIdCollidingWithHandWrittenIsSilentlySuppressed()


    public function testAttributedIdCollidingWithSchemaDerivedIsRejectedAndLogged(): void
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug('lead');
        $schema->setProperties([]);
        $schema->setRequired([]);
        $schema->setConfiguration(['x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => []]]]);

        $derivedProvider = new SchemaDerivedToolProvider(
            appId: 'pipelinq',
            schemaEntries: [['schema' => $schema, 'register' => null]],
            suppressedIds: [],
            objectService: $this->createMock(ObjectService::class),
            auditTrailMapper: $this->createMock(AuditTrailMapper::class),
            logger: $this->createMock(LoggerInterface::class)
        );

        // Sanity: the derived provider really does emit 'pipelinq.lead.search'.
        $this->assertContains('pipelinq.lead.search', array_column($derivedProvider->getTools(), 'id'));

        $shared    = $this->sharedContainer(['pipelinq']);
        $appConers = [
            'pipelinq' => $this->container(
                [
                    'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq' => $this->scannableDeclaration([CollidingFixtureService::class]),
                ]
            ),
        ];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('collides with a schema-derived tool id'));

        $providers = [$derivedProvider];
        $this->invokeCollect($shared, $appConers, $logger, $providers);

        // The colliding attributed tool was the ONLY descriptor on
        // CollidingFixtureService, so no AttributeToolProvider is appended
        // at all — the derived provider is untouched.
        $this->assertCount(1, $providers);
        $this->assertSame($derivedProvider, $providers[0]);

    }//end testAttributedIdCollidingWithSchemaDerivedIsRejectedAndLogged()


    // ── getRegisteredAppContainer() seam (real, fail-soft) ────────────


    /**
     * Reflect-invoke the REAL getRegisteredAppContainer() seam (not the
     * RecordingApplication override) with a controlled `\OC::$server`.
     *
     * @param object          $server The fake server to install on \OC::$server.
     * @param string          $appId  The app id to resolve.
     * @param LoggerInterface $logger PSR logger.
     *
     * @return ContainerInterface|null The seam's return value.
     */
    private function invokeRealSeam(object $server, string $appId, LoggerInterface $logger): ?ContainerInterface
    {
        \OC::$server = $server;
        $app         = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $method      = new ReflectionMethod(Application::class, 'getRegisteredAppContainer');
        $method->setAccessible(true);

        return $method->invoke($app, $appId, $logger);

    }//end invokeRealSeam()


    public function testRealSeamReturnsNullWhenServerThrows(): void
    {
        $server = new class extends \OC_FakeServer {
            public function getRegisteredAppContainer(string $appName): object
            {
                throw new RuntimeException('no container for '.$appName);
            }
        };
        $logger = $this->createMock(LoggerInterface::class);

        $this->assertNull($this->invokeRealSeam($server, 'pipelinq', $logger));

    }//end testRealSeamReturnsNullWhenServerThrows()


    public function testRealSeamReturnsContainerWhenRegistered(): void
    {
        $appContainer = $this->container([]);
        $server       = new class ($appContainer) extends \OC_FakeServer {
            public function __construct(private object $appContainer)
            {
            }

            public function getRegisteredAppContainer(string $appName): object
            {
                return $this->appContainer;
            }
        };
        $logger = $this->createMock(LoggerInterface::class);

        $this->assertSame($appContainer, $this->invokeRealSeam($server, 'pipelinq', $logger));

    }//end testRealSeamReturnsContainerWhenRegistered()


    public function testRealSeamReturnsNullForNonContainer(): void
    {
        $server = new class extends \OC_FakeServer {
            public function getRegisteredAppContainer(string $appName): object
            {
                // A non-PSR-container object (e.g. an unexpected NC internal).
                return new \stdClass();
            }
        };
        $logger = $this->createMock(LoggerInterface::class);

        $this->assertNull($this->invokeRealSeam($server, 'pipelinq', $logger));

    }//end testRealSeamReturnsNullForNonContainer()
}//end class
