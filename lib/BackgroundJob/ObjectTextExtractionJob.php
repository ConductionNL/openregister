<?php

/**
 * Object Text Extraction Background Job
 *
 * One-time background job that extracts text from OpenRegister objects asynchronously.
 * This job is queued automatically when objects are created or modified to avoid
 * blocking user requests with potentially slow text extraction operations.
 *
 * @category  BackgroundJob
 * @package   OCA\OpenRegister\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\TextExtractionService;
use OCP\BackgroundJob\QueuedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * One-time background job for object text extraction
 *
 * This job is automatically queued when objects are created or modified to
 * extract text content asynchronously without blocking the user's request.
 *
 * Features:
 * - Runs once per object in the background
 * - Non-blocking: doesn't slow down object saves
 * - Automatic retry for failed extractions
 * - Comprehensive logging and error handling
 * - Extracts text from object properties, metadata, and relationships
 *
 * @package OCA\OpenRegister\BackgroundJob
 *
 * @psalm-suppress UnusedClass - This background job is registered and instantiated by Nextcloud's job system
 */
class ObjectTextExtractionJob extends QueuedJob
{


    /**
     * Constructor
     *
     * @param ITimeFactory         $timeFactory          Time factory for job scheduling
     * @param TextExtractionService $textExtractionService Text extraction service
     * @param LoggerInterface      $logger               Logger instance
     * @param IAppConfig           $config               Application configuration
     *
     * @psalm-suppress PossiblyUnusedMethod - Constructor is called by Nextcloud's job system via dependency injection
     */
    public function __construct(
        ITimeFactory $timeFactory,
        private readonly TextExtractionService $textExtractionService,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $config,
    ) {
        parent::__construct($timeFactory);

    }//end __construct()


    /**
     * Run the background job
     *
     * Extracts text from the specified object and stores it in the database.
     * The job expects an argument array with 'object_id' key.
     *
     * @param array $argument Job arguments containing object_id
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod - This method is called by Nextcloud's job system when the job executes
     */
    protected function run($argument): void
    {
        // Check if object extraction is enabled.
        $objectSettings = json_decode($this->config->getValueString(app: 'openregister', key: 'objectManagement', default: '{}'), true);
        if (($objectSettings['objectExtractionMode'] ?? 'background') === 'none') {
            $this->logger->info('[ObjectTextExtractionJob] Object extraction is disabled. Not extracting text from objects.');
            return;
        }

        // Validate argument.
        if (isset($argument['object_id']) === false) {
            $this->logger->error(
                    '[ObjectTextExtractionJob] Missing object_id in job arguments',
                    [
                        'argument' => $argument,
                    ]
                    );
            return;
        }

        $objectId = (int) $argument['object_id'];

        $this->logger->info(
                '[ObjectTextExtractionJob] Starting text extraction',
                [
                    'object_id' => $objectId,
                    'job_id'    => $this->getId(),
                ]
                );

        $startTime = microtime(true);

        try {
            // Extract text using TextExtractionService.
            $this->textExtractionService->extractObject($objectId, false);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                    '[ObjectTextExtractionJob] Text extraction completed successfully',
                    [
                        'object_id'            => $objectId,
                        'processing_time_ms'   => $processingTime,
                    ]
                    );
        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                    '[ObjectTextExtractionJob] Exception during text extraction',
                    [
                        'object_id'            => $objectId,
                        'error'                => $e->getMessage(),
                        'trace'                => $e->getTraceAsString(),
                        'processing_time_ms'   => $processingTime,
                    ]
                    );
        }//end try

    }//end run()


}//end class

