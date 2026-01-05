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
 */
class ObjectTextExtractionJob extends QueuedJob
{

    /**
     * Configuration service
     *
     * @var IAppConfig
     */
    private IAppConfig $config;

    /**
     * Logger service
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Text extraction service
     *
     * @var TextExtractionService
     */
    private TextExtractionService $textExtractor;

    /**
     * Constructor
     *
     * Initializes the background job with required services via dependency injection.
     *
     * @param IAppConfig            $config                Configuration service
     * @param LoggerInterface       $logger                Logger service
     * @param TextExtractionService $textExtractor Text extraction service
     *
     * @return void
     */
    public function __construct(
        IAppConfig $config,
        LoggerInterface $logger,
        TextExtractionService $textExtractor
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->textExtractor = $textExtractor;
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
     */
    protected function run($argument): void
    {
        // Check if object extraction is enabled.
        $objMgmtValue = $this->config->getValueString(
            app: 'openregister',
            key: 'objectManagement',
            default: '{}'
        );
        $objectSettings        = json_decode($objMgmtValue, true);
        if (($objectSettings['objectExtractionMode'] ?? 'background') === 'none') {
            $message = '[ObjectTextExtractionJob] Object extraction is disabled. Not extracting text from objects.';
            $this->logger->info($message);
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
            $this->textExtractor->extractObject(objectId: $objectId, forceReExtract: false);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                '[ObjectTextExtractionJob] Text extraction completed successfully',
                [
                    'object_id'          => $objectId,
                    'processing_time_ms' => $processingTime,
                ]
            );
        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                '[ObjectTextExtractionJob] Exception during text extraction',
                [
                    'object_id'          => $objectId,
                    'error'              => $e->getMessage(),
                    'trace'              => $e->getTraceAsString(),
                    'processing_time_ms' => $processingTime,
                ]
            );
        }//end try
    }//end run()
}//end class
