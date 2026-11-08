<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Mcp\IMcpScannableServices;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\AttributeFixtureService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\CollidingFixtureService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolServices;

/**
 * Pins which attributed-tool providers discovery builds per installed app,
 * how ids colliding with derived or hand-written tools are dropped, and the
 * fail-soft paths.
 *
 * @covers \OCA\OpenRegister\Mcp\McpProviderDiscovery
 */
class McpProviderDiscoveryAttributeCharacterisationTest extends TestCase
{

    /** @var list<string> */
    private array $log = [];

    /** @var list<array> */
    private array $contexts = [];

    private function container(array $bindings, ?\Throwable $hasThrows = null): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static function (string $id) use ($bindings, $hasThrows): bool {
                if ($hasThrows !== null) {
                    throw $hasThrows;
                }
                return array_key_exists($id, $bindings);
            }
        );
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($bindings) {
                if (array_key_exists($id, $bindings) === true) {
                    return $bindings[$id];
                }
                throw new RuntimeException('No binding for '.$id);
            }
        );
        return $container;
    }

    private function scannable(array $classes): IMcpScannableServices
    {
        $declaration = $this->createMock(IMcpScannableServices::class);
        $declaration->method('getScannableServiceClasses')->willReturn($classes);
        return $declaration;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function ($message, array $context = []) use ($level): void {
                    $this->log[] = $level.': '.$message;
                    $this->contexts[] = $context;
                }
            );
        }
        return $logger;
    }

    /**
     * @param array<string, ?ContainerInterface> $appContainers
     * @param list<IMcpToolProvider>             $providers
     *
     * @return list<IMcpToolProvider>
     */
    private function collect(array|\Throwable $apps, array $appContainers, array $providers): array
    {
        $appManager = $this->createMock(IAppManager::class);
        if ($apps instanceof \Throwable) {
            $appManager->method('getInstalledApps')->willThrowException($apps);
        } else {
            $appManager->method('getInstalledApps')->willReturn($apps);
        }
        $container = $this->container(
            [
                'OCP\\App\\IAppManager'            => $appManager,
                AuditTrailMapperInterface::class => $this->createMock(AuditTrailMapperInterface::class),
            ]
        );
        $discovery = new class($appContainers) extends McpProviderDiscovery {
            /** @param array<string, ?ContainerInterface> $map */
            public function __construct(private readonly array $map)
            {
            }

            protected function getRegisteredAppContainer(string $appId, ContainerInterface $container): ?ContainerInterface
            {
                return $this->map[$appId] ?? null;
            }
        };
        $method = new ReflectionMethod(McpProviderDiscovery::class, 'collectAttributeMcpProviders');
        $method->invokeArgs($discovery, [$container, $this->logger(), &$providers]);
        return $providers;
    }

    private function handWritten(string $appId, array $ids): IMcpToolProvider
    {
        $provider = $this->createMock(IMcpToolProvider::class);
        $provider->method('getAppId')->willReturn($appId);
        $provider->method('getTools')->willReturn(array_map(static fn (string $id): array => ['id' => $id], $ids));
        return $provider;
    }

    private function derived(string $appId, string $slug): SchemaDerivedToolProvider
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug($slug);
        $schema->setConfiguration(['x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => []]]]);
        return new SchemaDerivedToolProvider(
        	appId: $appId,
        	schemaEntries: [['schema' => $schema, 'register' => null]],
        	suppressedIds: [],
        	services: new SchemaDerivedToolServices(
        		objectService: $this->createMock(ObjectService::class),
        		auditTrailMapper: $this->createMock(AuditTrailMapper::class),
        		logger: $this->createMock(LoggerInterface::class),
        	),
        );
    }

    /** @return list<list<string>> */
    private function toolIds(array $providers): array
    {
        return array_map(static fn (IMcpToolProvider $p): array => array_column($p->getTools(), 'id'), $providers);
    }

    public function testBuildsOneProviderPerOptedInAppAndSkipsTheRest(): void
    {
        $key = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::';
        $apps = [
            'nocontainer' => null,
            'nodecl'      => $this->container([]),
            'thrower'     => $this->container([], new RuntimeException('broken')),
            'wrongtype'   => $this->container([$key.'wrongtype' => new \stdClass()]),
            'empty'       => $this->container([$key.'empty' => $this->scannable([])]),
            'noattrs'     => $this->container([$key.'noattrs' => $this->scannable([\stdClass::class])]),
            'unresolved'  => $this->container([$key.'unresolved' => $this->scannable([AttributeFixtureService::class])]),
            'pipelinq'    => $this->container(
                [
                    $key.'pipelinq'                 => $this->scannable([AttributeFixtureService::class, CollidingFixtureService::class]),
                    AttributeFixtureService::class  => new AttributeFixtureService(),
                    CollidingFixtureService::class  => new CollidingFixtureService(),
                ]
            ),
        ];
        $existing = [$this->handWritten('other', ['x.y'])];

        $providers = $this->collect(array_keys($apps), $apps, $existing);

        $this->assertCount(2, $providers);
        $this->assertSame($existing[0], $providers[0]);
        $this->assertInstanceOf(AttributeToolProvider::class, $providers[1]);
        $this->assertSame('pipelinq', $providers[1]->getAppId());
        $this->assertSame([['x.y'], ['pipelinq.createLead', 'pipelinq.logContactmoment', 'pipelinq.computeScore', 'pipelinq.lead.search']], $this->toolIds($providers));
        $this->assertSame(
            [
                'debug: [Application] Scannable-services alias resolve failed',
                'warning: [AttributeToolScanner] #[McpTool] on a non-public method is ignored',
                'warning: [Application] Could not resolve attributed tool service instance',
                'warning: [Application] Could not resolve attributed tool service instance',
                'warning: [Application] Could not resolve attributed tool service instance',
                'warning: [AttributeToolScanner] #[McpTool] on a non-public method is ignored',
            ],
            $this->log
        );
        $this->assertSame(['appId' => 'thrower', 'error' => 'broken'], $this->contexts[0]);
        $this->assertSame('unresolved', $this->contexts[2]['appId']);
        $this->assertSame('pipelinq', $this->contexts[5]['appId']);
    }

    public function testCollisionsWithDerivedAreRejectedAndWithHandWrittenSuppressed(): void
    {
        $key  = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq';
        $apps = [
            'pipelinq' => $this->container(
                [
                    $key                            => $this->scannable([AttributeFixtureService::class, CollidingFixtureService::class]),
                    AttributeFixtureService::class  => new AttributeFixtureService(),
                    CollidingFixtureService::class  => new CollidingFixtureService(),
                ]
            ),
        ];
        $existing = [
            $this->derived('pipelinq', 'lead'),
            $this->handWritten('pipelinq', ['pipelinq.createLead']),
            $this->handWritten('elsewhere', ['pipelinq.logContactmoment']),
        ];

        $providers = $this->collect(['pipelinq'], $apps, $existing);

        $this->assertCount(4, $providers);
        $this->assertSame(['pipelinq.logContactmoment', 'pipelinq.computeScore'], $this->toolIds($providers)[3]);
        $this->assertSame(
            [
                'warning: [AttributeToolScanner] #[McpTool] on a non-public method is ignored',
                'error: [Application] Attributed tool id collides with a schema-derived tool id — rejected',
            ],
            $this->log
        );
        $this->assertSame(['appId' => 'pipelinq', 'toolId' => 'pipelinq.lead.search'], $this->contexts[1]);
    }

    public function testEverythingCollidingAddsNoProvider(): void
    {
        $key  = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq';
        $apps = [
            'pipelinq' => $this->container([$key => $this->scannable([CollidingFixtureService::class]), CollidingFixtureService::class => new CollidingFixtureService()]),
        ];
        $existing  = [$this->handWritten('pipelinq', ['pipelinq.lead.search'])];
        $providers = $this->collect(['pipelinq'], $apps, $existing);
        $this->assertSame($existing, $providers);
        $this->assertSame([], $this->log);
    }

    public function testAnEnumerationFailureIsLoggedAndKeepsEarlierProviders(): void
    {
        $existing  = [$this->handWritten('a', ['a.b'])];
        $providers = $this->collect(new RuntimeException('apps table gone'), [], $existing);
        $this->assertSame($existing, $providers);
        $this->assertSame(['warning: [Application] Attributed-tool provider enumeration failed: apps table gone'], $this->log);
    }
}
