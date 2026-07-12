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

/**
 * DI-wiring tests for the attribute-tool discovery step.
 */
class AttributeMcpDiscoveryTest extends TestCase
{

    /**
     * Reflect-invoke the private `collectAttributeMcpProviders()` method.
     *
     * @param ContainerInterface       $container The (mocked) DI container.
     * @param LoggerInterface          $logger    The (mocked) logger.
     * @param array<IMcpToolProvider>  $providers Providers collected so far (mutated by reference).
     *
     * @return void
     */
    private function invokeCollect(ContainerInterface $container, LoggerInterface $logger, array &$providers): void
    {
        // newInstanceWithoutConstructor() — Application::__construct() calls
        // parent::__construct(), which touches \OC::$server; that global NC
        // bootstrap is out of scope for this local unit-test harness (and
        // orthogonal to what's under test here: the private discovery
        // method reads only its own parameters, no constructor-set state).
        $app    = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Application::class, 'collectAttributeMcpProviders');
        $method->setAccessible(true);
        $method->invokeArgs($app, [$container, $logger, &$providers]);

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
     * @param IAppManager           $appManager The app manager to bind under 'OCP\App\IAppManager'.
     *
     * @return ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mockContainer(array $bindings, IAppManager $appManager)
    {
        $bindings['OCP\App\IAppManager'] = $appManager;

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

    }//end mockContainer()

    /**
     * @param list<string> $appIds Installed app ids to report.
     *
     * @return IAppManager
     */
    private function appManager(array $appIds): IAppManager
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn($appIds);

        return $appManager;

    }//end appManager()


    // ── Opt-in discovery ──────────────────────────────────────────────


    public function testNoScannableServicesDeclarationAppendsNothing(): void
    {
        $container = $this->mockContainer([], $this->appManager(['pipelinq']));
        $logger    = $this->createMock(LoggerInterface::class);

        $providers = [];
        $this->invokeCollect($container, $logger, $providers);

        $this->assertSame([], $providers);

    }//end testNoScannableServicesDeclarationAppendsNothing()


    public function testDeclaredScannableClassAppendsOneAttributeToolProvider(): void
    {
        $bindings = [
            'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq' => $this->scannableDeclaration([AttributeFixtureService::class]),
            AuditTrailMapper::class => $this->createMock(AuditTrailMapper::class),
        ];
        $container = $this->mockContainer($bindings, $this->appManager(['pipelinq']));
        $logger    = $this->createMock(LoggerInterface::class);

        $providers = [];
        $this->invokeCollect($container, $logger, $providers);

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(AttributeToolProvider::class, $providers[0]);

        $ids = array_column($providers[0]->getTools(), 'id');
        $this->assertContains('pipelinq.createLead', $ids);
        $this->assertContains('pipelinq.logContactmoment', $ids);

    }//end testDeclaredScannableClassAppendsOneAttributeToolProvider()


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

        $bindings = [
            'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq' => $this->scannableDeclaration([AttributeFixtureService::class]),
            AuditTrailMapper::class => $this->createMock(AuditTrailMapper::class),
        ];
        $container = $this->mockContainer($bindings, $this->appManager(['pipelinq']));
        $logger    = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $providers = [$handWritten];
        $this->invokeCollect($container, $logger, $providers);

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

        $bindings = [
            'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq' => $this->scannableDeclaration([CollidingFixtureService::class]),
            AuditTrailMapper::class => $this->createMock(AuditTrailMapper::class),
        ];
        $container = $this->mockContainer($bindings, $this->appManager(['pipelinq']));
        $logger    = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('collides with a schema-derived tool id'));

        $providers = [$derivedProvider];
        $this->invokeCollect($container, $logger, $providers);

        // The colliding attributed tool was the ONLY descriptor on
        // CollidingFixtureService, so no AttributeToolProvider is appended
        // at all — the derived provider is untouched.
        $this->assertCount(1, $providers);
        $this->assertSame($derivedProvider, $providers[0]);

    }//end testAttributedIdCollidingWithSchemaDerivedIsRejectedAndLogged()
}//end class
