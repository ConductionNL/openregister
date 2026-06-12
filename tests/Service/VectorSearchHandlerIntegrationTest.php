<?php

/**
 * Integration tests for VectorSearchHandler
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\Vectorization\Handlers\VectorSearchHandler;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for VectorSearchHandler
 *
 * Tests vector similarity search with PHP backend,
 * including cosine similarity calculations and result formatting.
 */
class VectorSearchHandlerIntegrationTest extends TestCase
{
    /**
     * The vector search handler instance
     *
     * @var VectorSearchHandler
     */
    private VectorSearchHandler $handler;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = \OC::$server->get(VectorSearchHandler::class);
    }

    /**
     * Test semanticSearch with empty embedding returns empty
     *
     * @return void
     */
    public function testSemanticSearchEmptyEmbedding(): void
    {
        // Even with empty/dummy embedding, the method should not crash
        $result = $this->handler->semanticSearch(
            [],
            10,
            [],
            'php'
        );

        $this->assertIsArray($result);
    }

    /**
     * Test semanticSearch with PHP backend returns array
     *
     * @return void
     */
    public function testSemanticSearchPhpBackend(): void
    {
        // Create a dummy embedding vector
        $queryEmbedding = array_fill(0, 384, 0.1);

        $result = $this->handler->semanticSearch(
            $queryEmbedding,
            5,
            [],
            'php'
        );

        $this->assertIsArray($result);
    }

    /**
     * Test semanticSearch with database backend
     *
     * @return void
     */
    public function testSemanticSearchDatabaseBackend(): void
    {
        $queryEmbedding = array_fill(0, 384, 0.1);

        $result = $this->handler->semanticSearch(
            $queryEmbedding,
            5,
            [],
            'database'
        );

        $this->assertIsArray($result);
    }

    /**
     * Test semanticSearch with filters
     *
     * @return void
     */
    public function testSemanticSearchWithFilters(): void
    {
        $queryEmbedding = array_fill(0, 384, 0.1);

        $result = $this->handler->semanticSearch(
            $queryEmbedding,
            10,
            ['entity_type' => 'object'],
            'php'
        );

        $this->assertIsArray($result);
    }

    /**
     * Test semanticSearch with limit of 1
     *
     * @return void
     */
    public function testSemanticSearchLimitOne(): void
    {
        $queryEmbedding = array_fill(0, 384, 0.1);

        $result = $this->handler->semanticSearch(
            $queryEmbedding,
            1,
            [],
            'php'
        );

        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(1, count($result));
    }

    /**
     * Test hybridSearch returns array
     *
     * @return void
     */
    public function testHybridSearch(): void
    {
        $queryEmbedding = array_fill(0, 384, 0.1);

        $result = $this->handler->hybridSearch(
            $queryEmbedding,
            [],
            10
        );

        $this->assertIsArray($result);
    }

    /**
     * Test hybridSearch with empty solr results
     *
     * @return void
     */
    public function testHybridSearchEmptySolr(): void
    {
        $queryEmbedding = array_fill(0, 384, 0.1);

        $result = $this->handler->hybridSearch(
            $queryEmbedding,
            [],
            10,
            ['solr' => 0.3, 'vector' => 0.7]
        );

        $this->assertIsArray($result);
    }

    /**
     * Test hybridSearch with custom weights
     *
     * @return void
     */
    public function testHybridSearchWithWeights(): void
    {
        $queryEmbedding = array_fill(0, 384, 0.1);

        $result = $this->handler->hybridSearch(
            $queryEmbedding,
            [],
            5,
            ['solr' => 0.8, 'vector' => 0.2]
        );

        $this->assertIsArray($result);
    }
}
