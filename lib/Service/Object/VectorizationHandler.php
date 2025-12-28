<?php

/**
 * Vectorization Handler
 *
 * Handles vectorization operations for objects.
 * Acts as a bridge between ObjectsController and VectorizationService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\VectorizationService;
use Psr\Log\LoggerInterface;

/**
 * VectorizationHandler
 *
 * Responsible for coordinating object vectorization operations.
 *
 * RESPONSIBILITIES:
 * - Batch vectorize objects
 * - Get vectorization statistics
 * - Get vectorization counts
 * - Coordinate between ObjectService and VectorizationService
 *
 * NOTE: This handler is thin by design. Heavy lifting is done by VectorizationService.
 * This exists to provide object-specific vectorization logic if needed in the future.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 */
class VectorizationHandler
{
    /**
     * Constructor
     *
     * @param VectorizationService $vectorizationService Vectorization service
     * @param ObjectEntityMapper   $objectEntityMapper   Object entity mapper
     * @param LoggerInterface      $logger               PSR-3 logger
     */
    public function __construct(
        private readonly VectorizationService $vectorizationService,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Vectorize objects in batch
     *
     * Delegates to VectorizationService with 'object' entity type.
     *
     * @param array|null $views     Optional view filters
     * @param int        $batchSize Number of objects to process per batch
     *
     * @return (((int|string)[]|mixed)[]|int|mixed|string|true)[] Vectorization results
     *
     * @throws \Exception If vectorization fails
     *
     * @psalm-return array{success: true, message: string, entity_type: string, total_entities: int<0, max>, total_items: int<0, max>, vectorized: int<0, max>, failed: int<0, max>, errors?: list{0?: array{entity_id: int|string, error: string, item_index?: array-key},...}, processed?: mixed}
     */
    public function vectorizeBatch(?array $views = null, int $batchSize = 25): array
    {
        $this->logger->info(
            message: '[VectorizationHandler] Starting batch vectorization',
            context: [
                'batch_size' => $batchSize,
                'views'      => $views,
            ]
        );

        try {
            // Delegate to unified VectorizationService.
            $result = $this->vectorizationService->vectorizeBatch(
                entityType: 'object',
                options: [
                    'views'      => $views,
                    'batch_size' => $batchSize,
                    'mode'       => 'serial',
                    // Objects use serial mode by default.
                ]
            );

            $this->logger->info(
                message: '[VectorizationHandler] Batch vectorization completed',
                context: [
                    'processed' => $result['processed'] ?? 0,
                    'success'   => $result['success'] ?? 0,
                    'failed'    => $result['failed'] ?? 0,
                ]
            );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[VectorizationHandler] Batch vectorization failed',
                context: [
                    'error' => $e->getMessage(),
                    'views' => $views,
                ]
            );
            throw $e;
        }//end try
    }//end vectorizeBatch()

    /**
     * Get vectorization statistics
     *
     * Returns statistics about vectorized objects with optional view filters.
     *
     * @param array|null $views Optional view filters
     *
     * @return (array|int|null)[] Statistics data
     *
     * @throws \Exception If stats retrieval fails
     *
     * @psalm-return array{total_objects: int<0, max>, views: array|null}
     */
    public function getStatistics(?array $views = null): array
    {
        $this->logger->debug(
            message: '[VectorizationHandler] Getting vectorization statistics',
            context: ['views' => $views]
        );

        try {
            // Count total objects with view filter support.
            $result       = $this->objectEntityMapper->findAll(
                limit: null,
                offset: null
            );
            $totalObjects = count($result);

            $stats = [
                'total_objects' => $totalObjects,
                'views'         => $views,
            ];

            $this->logger->debug(
                message: '[VectorizationHandler] Statistics retrieved',
                context: $stats
            );

            return $stats;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[VectorizationHandler] Failed to get statistics',
                context: [
                    'error' => $e->getMessage(),
                    'views' => $views,
                ]
            );
            throw $e;
        }//end try
    }//end getStatistics()

    /**
     * Get count of objects available for vectorization
     *
     * Returns count of objects that can be vectorized.
     *
     * @param array|null $schemas Optional schema filters
     *
     * @return int Object count
     *
     * @throws \Exception If count fails
     */
    public function getCount(?array $schemas = null): int
    {
        $this->logger->debug(
            message: '[VectorizationHandler] Getting object count',
            context: ['schemas' => $schemas]
        );

        try {
            // TODO: Implement proper counting logic with schemas parameter.
            // For now, return 0 as per original implementation.
            $count = 0;

            $this->logger->debug(
                message: '[VectorizationHandler] Count retrieved',
                context: ['count' => $count]
            );

            return $count;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[VectorizationHandler] Failed to get count',
                context: [
                    'error'   => $e->getMessage(),
                    'schemas' => $schemas,
                ]
            );
            throw $e;
        }//end try
    }//end getCount()
}//end class
