<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the full derived-tool catalogue (golden), derived-vs-derived duplicate
 * handling, search paging edges and tool-id resolution.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider
 */
class SchemaDerivedToolProviderCharacterisationTest extends TestCase
{

    private const GOLDEN = __DIR__.'/fixtures/schema-derived-tools-golden.json';

    /** @var ObjectService&MockObject */
    private ObjectService $objectService;

    /** @var array<int, array{0: string, 1: array}> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->warnings      = [];
    }

    private function schema(int $id, ?string $slug, ?array $mcp, array $properties = [], array $required = []): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        if ($slug !== null) {
            $schema->setSlug($slug);
        }
        $schema->setProperties($properties);
        $schema->setRequired($required);
        $schema->setConfiguration($mcp === null ? [] : ['x-openregister-mcp' => $mcp]);
        return $schema;
    }

    private function provider(array $schemas, array $suppressed = []): SchemaDerivedToolProvider
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function ($message, array $context = []): void {
                $this->warnings[] = [(string) $message, $context];
            }
        );
        return new SchemaDerivedToolProvider(
            appId: 'app.sub',
            schemaEntries: array_map(fn (Schema $s) => ['schema' => $s, 'register' => null], $schemas),
            suppressedIds: $suppressed,
            objectService: $this->objectService,
            auditTrailMapper: $this->createMock(AuditTrailMapper::class),
            logger: $logger
        );
    }

    /** @return list<Schema> */
    private function catalogueSchemas(): array
    {
        $properties = [
            'name'    => ['maxLength' => 10, 'type' => 'string', 'title' => 'Name', '$ref' => '#/x', 'x-or' => 1],
            'odd'     => 'not-an-array',
            'choice'  => ['enum' => ['a', 'b'], 'inversedBy' => 'y'],
            'untyped' => ['description' => 'no type'],
            'tags'    => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['k' => ['format' => 'uuid', 'relation' => 'z'], 'v' => 5]]],
            'shape'   => ['type' => 'object', 'properties' => 'not-a-map'],
            'id'      => ['type' => 'string'],
        ];
        return [
            $this->schema(1, 'lead', ['enabled' => true], $properties, ['name']),
            $this->schema(2, 'lead', ['enabled' => true], $properties, ['name']),
            $this->schema(
                3,
                'case',
                [
                    'enabled' => true,
                    'tools'   => [
                        'delete' => ['destructiveHint' => true, 'scope' => 'write'],
                        'search' => ['filters' => ['name', 'choice'], 'description' => 'Find cases', 'readOnlyHint' => true],
                        'bogus'  => [],
                        'get'    => 'not-an-array',
                    ],
                ],
                $properties
            ),
            $this->schema(4, null, ['enabled' => true, 'tools' => ['get' => []]]),
            $this->schema(5, 'off', ['enabled' => false]),
            $this->schema(6, 'truthy', ['enabled' => 1]),
            $this->schema(7, 'none', null),
        ];
    }

    public function testCatalogueMatchesGolden(): void
    {
        $tools = $this->provider($this->catalogueSchemas(), ['app.sub.case.search'])->getTools();
        $json  = json_encode($tools, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $this->assertSame(file_get_contents(self::GOLDEN), $json);
    }

    public function testDuplicateSlugKeepsFirstSchemaAndWarnsPerVerb(): void
    {
        $tools = $this->provider($this->catalogueSchemas(), ['app.sub.case.search'])->getTools();
        $ids   = array_column($tools, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertCount(5, $this->warnings);
        foreach ($this->warnings as $i => [$message, $context]) {
            $this->assertSame('[SchemaDerivedToolProvider] duplicate tool id suppressed — two schema rows share one slug', $message);
            $this->assertSame(1, $context['keptSchemaId']);
            $this->assertSame(2, $context['droppedSchemaId']);
            $this->assertStringStartsWith('app.sub.lead.', $context['toolId']);
        }
    }

    /** @return array<string, array{0: array, 1: int, 2: int}> */
    public static function pagingProvider(): array
    {
        return [
            'zero page'          => [['page' => 0, 'pageSize' => 5], 5, 0],
            'negative page'      => [['page' => -3], 20, 0],
            'third page'         => [['page' => '3', 'pageSize' => '7'], 7, 14],
            'zero page size'     => [['page' => 2, 'pageSize' => 0], 20, 20],
            'negative page size' => [['pageSize' => -1], 20, 0],
            'huge page size'     => [['page' => 2, 'pageSize' => 1000], 100, 100],
        ];
    }

    #[DataProvider('pagingProvider')]
    public function testSearchPaging(array $arguments, int $limit, int $offset): void
    {
        $seen = null;
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) use (&$seen): array {
                $seen = $config;
                return [];
            }
        );
        $this->objectService->method('count')->willReturn(0);

        $result = $this->provider([$this->schema(1, 'lead', ['enabled' => true])])->invokeTool('app.sub.lead.search', $arguments);

        $this->assertSame(['limit' => $limit, 'offset' => $offset, 'filters' => []], $seen);
        $this->assertSame($limit, $result['pageSize']);
        $this->assertFalse($result['hasMore']);
    }

    public function testSearchFieldsOnlyWhenNonEmptyArray(): void
    {
        $configs = [];
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) use (&$configs): array {
                $configs[] = $config;
                return [];
            }
        );
        $this->objectService->method('count')->willReturn(0);
        $provider = $this->provider([$this->schema(1, 'lead', ['enabled' => true])]);
        $provider->invokeTool('app.sub.lead.search', ['fields' => []]);
        $provider->invokeTool('app.sub.lead.search', ['fields' => 'name']);
        $provider->invokeTool('app.sub.lead.search', ['fields' => ['name']]);
        $this->assertArrayNotHasKey('fields', $configs[0]);
        $this->assertArrayNotHasKey('fields', $configs[1]);
        $this->assertSame(['name'], $configs[2]['fields']);
    }

    public function testSearchRefusesNonObjectFilters(): void
    {
        $this->objectService->expects($this->never())->method('findAll');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('`filters` must be an object.');
        $this->provider([$this->schema(1, 'lead', ['enabled' => true])])->invokeTool('app.sub.lead.search', ['filters' => 'x']);
    }

    public function testSearchUndeclaredFilterMessageListsDeclared(): void
    {
        $schema = $this->schema(1, 'lead', ['enabled' => true, 'tools' => ['search' => ['filters' => ['a', 'b']]]]);
        $this->expectExceptionMessage('Undeclared search filter "0". Declared filters: [a, b].');
        $this->provider([$schema])->invokeTool('app.sub.lead.search', ['filters' => ['x']]);
    }

    public function testSearchNonListDeclaredFiltersRefusesAnyFilter(): void
    {
        $schema = $this->schema(1, 'lead', ['enabled' => true, 'tools' => ['search' => ['filters' => 'a']]]);
        $this->expectExceptionMessage('Undeclared search filter "a". Declared filters: [].');
        $this->provider([$schema])->invokeTool('app.sub.lead.search', ['filters' => ['a' => 1]]);
    }

    /** @return array<string, array{0: string}> */
    public static function unknownIdProvider(): array
    {
        return [
            'too short'    => ['lead.search'],
            'other app'    => ['other.lead.search'],
            'partial app'  => ['sub.lead.search'],
            'unknown verb' => ['app.sub.lead.list'],
            'unknown slug' => ['app.sub.nope.get'],
        ];
    }

    #[DataProvider('unknownIdProvider')]
    public function testUnknownToolIdsAreRefused(string $toolId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: '.$toolId);
        $this->provider([$this->schema(1, 'lead', ['enabled' => true])])->invokeTool($toolId, ['id' => 'x']);
    }

    public function testResolveUsesFirstSchemaWithSlugAndNumericFallbackSlug(): void
    {
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->method('count')->willReturn(0);
        $provider = $this->provider([$this->schema(9, null, ['enabled' => true]), $this->schema(1, 'lead', ['enabled' => true])]);
        $this->assertSame(0, $provider->invokeTool('app.sub.9.search', [])['total']);
        $this->assertSame(0, $provider->invokeTool('app.sub.lead.search', [])['total']);
    }
}
