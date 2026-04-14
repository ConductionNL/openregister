<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\ObjectsProvider;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilter;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectsProviderTest extends TestCase
{
    private ObjectsProvider $provider;
    private IL10N&MockObject $l10n;
    private IURLGenerator&MockObject $urlGenerator;
    private ObjectService&MockObject $objectService;
    private LoggerInterface&MockObject $logger;
    private DeepLinkRegistryService&MockObject $deepLinkRegistry;

    protected function setUp(): void
    {
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(function (string $text, $args = []) {
            if (is_array($args) && count($args) > 0) {
                return vsprintf($text, $args);
            }
            return $text;
        });

        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);

        $this->provider = new ObjectsProvider(
            $this->l10n,
            $this->urlGenerator,
            $this->objectService,
            $this->logger,
            $this->deepLinkRegistry,
            $this->createMock(SchemaMapper::class),
            $this->createMock(RegisterMapper::class)
        );
    }

    public function testGetId(): void
    {
        $this->assertSame('openregister_objects', $this->provider->getId());
    }

    public function testGetName(): void
    {
        $this->assertSame('Open Register Objects', $this->provider->getName());
    }

    public function testGetOrder(): void
    {
        $this->assertSame(10, $this->provider->getOrder('some.route', []));
    }

    public function testGetSupportedFilters(): void
    {
        $filters = $this->provider->getSupportedFilters();
        $this->assertContains('term', $filters);
        $this->assertContains('since', $filters);
        $this->assertContains('until', $filters);
        $this->assertContains('person', $filters);
        $this->assertContains('register', $filters);
        $this->assertContains('schema', $filters);
    }

    public function testGetAlternateIds(): void
    {
        $this->assertSame([], $this->provider->getAlternateIds());
    }

    public function testGetCustomFilters(): void
    {
        $filters = $this->provider->getCustomFilters();
        $this->assertCount(2, $filters);
        $this->assertInstanceOf(FilterDefinition::class, $filters[0]);
        $this->assertInstanceOf(FilterDefinition::class, $filters[1]);
    }

    public function testSearchWithNoResults(): void
    {
        $user = $this->createMock(IUser::class);
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturn(null);

        $this->objectService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $result = $this->provider->search($user, $query);

        $this->assertInstanceOf(SearchResult::class, $result);
    }

    public function testSearchWithTermFilter(): void
    {
        $user = $this->createMock(IUser::class);

        $termFilter = $this->createMock(IFilter::class);
        $termFilter->method('get')->willReturn('test search');

        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturnCallback(function (string $name) use ($termFilter) {
            return $name === 'term' ? $termFilter : null;
        });

        $this->objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with(
                $this->callback(function (array $q) {
                    return $q['_search'] === 'test search' && $q['_limit'] === 25;
                }),
                $this->isTrue(),
                $this->isTrue()
            )
            ->willReturn(['results' => [], 'total' => 0]);

        $this->provider->search($user, $query);
    }

    public function testSearchWithRegisterAndSchemaFilters(): void
    {
        $user = $this->createMock(IUser::class);

        $registerFilter = $this->createMock(IFilter::class);
        $registerFilter->method('get')->willReturn('1');

        $schemaFilter = $this->createMock(IFilter::class);
        $schemaFilter->method('get')->willReturn('2');

        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturnCallback(function (string $name) use ($registerFilter, $schemaFilter) {
            if ($name === 'register') {
                return $registerFilter;
            }
            if ($name === 'schema') {
                return $schemaFilter;
            }
            return null;
        });

        $this->objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with(
                $this->callback(function (array $q) {
                    return ($q['@self']['register'] ?? null) === 1
                        && ($q['@self']['schema'] ?? null) === 2;
                }),
                $this->isTrue(),
                $this->isTrue()
            )
            ->willReturn(['results' => [], 'total' => 0]);

        $this->provider->search($user, $query);
    }

    public function testSearchWithDateFilters(): void
    {
        $user = $this->createMock(IUser::class);

        $sinceFilter = $this->createMock(IFilter::class);
        $sinceFilter->method('get')->willReturn('2024-01-01');

        $untilFilter = $this->createMock(IFilter::class);
        $untilFilter->method('get')->willReturn('2024-12-31');

        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturnCallback(function (string $name) use ($sinceFilter, $untilFilter) {
            if ($name === 'since') {
                return $sinceFilter;
            }
            if ($name === 'until') {
                return $untilFilter;
            }
            return null;
        });

        $this->objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with(
                $this->callback(function (array $q) {
                    return ($q['@self']['created']['$gte'] ?? null) === '2024-01-01'
                        && ($q['@self']['created']['$lte'] ?? null) === '2024-12-31';
                }),
                $this->isTrue(),
                $this->isTrue()
            )
            ->willReturn(['results' => [], 'total' => 0]);

        $this->provider->search($user, $query);
    }

    public function testSearchWithUntilFilterOnly(): void
    {
        $user = $this->createMock(IUser::class);

        $untilFilter = $this->createMock(IFilter::class);
        $untilFilter->method('get')->willReturn('2024-12-31');

        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturnCallback(function (string $name) use ($untilFilter) {
            return $name === 'until' ? $untilFilter : null;
        });

        $this->objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with(
                $this->callback(function (array $q) {
                    return ($q['@self']['created']['$lte'] ?? null) === '2024-12-31'
                        && !isset($q['@self']['created']['$gte']);
                }),
                $this->isTrue(),
                $this->isTrue()
            )
            ->willReturn(['results' => [], 'total' => 0]);

        $this->provider->search($user, $query);
    }

    public function testSearchWithResults(): void
    {
        $user = $this->createMock(IUser::class);
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturn(null);

        $this->objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [
                    [
                        'title' => 'Test Object',
                        '@self' => [
                            'id' => 'uuid-123',
                            'register' => 1,
                            'schema' => 2,
                            'name' => 'test',
                        ],
                    ],
                ],
                'total' => 1,
            ]);

        $this->deepLinkRegistry->method('resolveUrl')
            ->willReturn('https://example.com/object/uuid-123');
        $this->deepLinkRegistry->method('resolveIcon')
            ->willReturn('icon-custom');

        $result = $this->provider->search($user, $query);

        $this->assertInstanceOf(SearchResult::class, $result);
    }

    public function testSearchWithObjectEntityResults(): void
    {
        $user = $this->createMock(IUser::class);
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturn(null);

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn([
            'title' => 'Entity Object',
            '@self' => [
                'id' => 'uuid-456',
                'register' => 1,
                'schema' => 2,
            ],
        ]);

        $this->objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$objectEntity],
                'total' => 1,
            ]);

        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
        $this->urlGenerator->method('linkToRoute')->willReturn('/openregister/objects/uuid-456');

        $result = $this->provider->search($user, $query);

        $this->assertInstanceOf(SearchResult::class, $result);
    }

    public function testSearchResultWithNameFallback(): void
    {
        $user = $this->createMock(IUser::class);
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturn(null);

        $this->objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [
                    [
                        '@self' => [
                            'id' => 'uuid-789',
                            'register' => 1,
                            'schema' => 2,
                            'name' => 'My Object Name',
                        ],
                    ],
                ],
                'total' => 1,
            ]);

        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
        $this->urlGenerator->method('linkToRoute')->willReturn('/objects/uuid-789');

        $result = $this->provider->search($user, $query);

        $this->assertInstanceOf(SearchResult::class, $result);
    }

    public function testSearchResultWithUuidFallback(): void
    {
        $user = $this->createMock(IUser::class);
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturn(null);

        $this->objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [
                    [
                        '@self' => [
                            'id' => 'uuid-fallback',
                            'register' => 1,
                            'schema' => 2,
                        ],
                    ],
                ],
                'total' => 1,
            ]);

        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
        $this->urlGenerator->method('linkToRoute')->willReturn('/objects/uuid-fallback');

        $result = $this->provider->search($user, $query);

        $this->assertInstanceOf(SearchResult::class, $result);
    }

    public function testSearchResultWithDescription(): void
    {
        $user = $this->createMock(IUser::class);
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturn(null);

        $this->objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [
                    [
                        'title' => 'Test',
                        'description' => str_repeat('a', 150),
                        '@self' => [
                            'id' => 'uuid-desc',
                            'register' => 1,
                            'schema' => 2,
                            'updated' => '2024-06-15 10:30:00',
                        ],
                    ],
                ],
                'total' => 1,
            ]);

        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
        $this->urlGenerator->method('linkToRoute')->willReturn('/objects/uuid-desc');

        $result = $this->provider->search($user, $query);

        $this->assertInstanceOf(SearchResult::class, $result);
    }

    public function testSearchResultWithSummary(): void
    {
        $user = $this->createMock(IUser::class);
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getFilter')->willReturn(null);

        $this->objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [
                    [
                        'title' => 'Test',
                        'summary' => 'Short summary',
                        '@self' => [
                            'id' => 'uuid-sum',
                            'register' => 1,
                            'schema' => 2,
                        ],
                    ],
                ],
                'total' => 1,
            ]);

        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
        $this->urlGenerator->method('linkToRoute')->willReturn('/objects/uuid-sum');

        $result = $this->provider->search($user, $query);

        $this->assertInstanceOf(SearchResult::class, $result);
    }
}
