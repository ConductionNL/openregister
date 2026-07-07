<?php

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the DoS-guard clamp on list/search page size (QueryHandler::MAX_PAGE_SIZE).
 */
class QueryHandlerLimitClampTest extends TestCase
{
    private MagicMapper $objectMapper;
    private QueryHandler $handler;

    /** @var array<string, mixed>|null Captured search query passed to the mapper. */
    private ?array $capturedQuery = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper = $this->createMock(MagicMapper::class);

        // Capture the paginated query the handler forwards to the mapper, and
        // return a minimal empty result so the method completes.
        $this->objectMapper
            ->method('searchObjectsPaginated')
            ->willReturnCallback(function (array $searchQuery) {
                $this->capturedQuery = $searchQuery;
                return [
                    'results'   => [],
                    'total'     => 0,
                    'registers' => [],
                    'schemas'   => [],
                    'facets'    => [],
                    'facetable' => [],
                ];
            });

        $this->handler = new QueryHandler(
            $this->objectMapper,
            $this->createMock(GetObject::class),
            $this->createMock(RenderObject::class),
            $this->createMock(SearchQueryHandler::class),
            $this->createMock(FacetHandler::class),
            $this->createMock(PerformanceOptimizationHandler::class),
            $this->createMock(IAppContainer::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IRequest::class)
        );
    }

    private function runWithLimit(int $limit): void
    {
        $this->handler->searchObjectsPaginatedDatabase(
            query: ['_limit' => $limit],
            _rbac: false,
            _multitenancy: false
        );
    }

    public function testOversizedLimitIsClampedToMax(): void
    {
        $this->runWithLimit(1000000);
        $this->assertNotNull($this->capturedQuery);
        $this->assertSame(
            QueryHandler::MAX_PAGE_SIZE,
            $this->capturedQuery['_limit'],
            'An oversized _limit must be clamped to MAX_PAGE_SIZE'
        );
    }

    public function testLimitAtMaxIsPreserved(): void
    {
        $this->runWithLimit(QueryHandler::MAX_PAGE_SIZE);
        $this->assertSame(QueryHandler::MAX_PAGE_SIZE, $this->capturedQuery['_limit']);
    }

    public function testNormalLimitIsUnchanged(): void
    {
        $this->runWithLimit(20);
        $this->assertSame(20, $this->capturedQuery['_limit']);
    }
}
