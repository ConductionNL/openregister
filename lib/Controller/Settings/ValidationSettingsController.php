<?php

/**
 * OpenRegister Validation Settings Controller
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Controller for object validation operations.
 *
 * Handles:
 * - Object validation
 * - Mass validation operations
 * - Memory usage predictions
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class ValidationSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName         The app name.
     * @param IRequest        $request         The request.
     * @param SettingsService $settingsService Settings service.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Validate all objects in the system
     *
     * This method validates all objects against their schemas and returns
     * a summary of validation results including any errors found.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Validation results summary
     *
     * @psalm-return JSONResponse<200|500, array{error?: string, total_objects: int<0, max>, valid_objects: 0|1|2, invalid_objects: int, validation_errors: list{0?: array{object_id: mixed, object_name: mixed, register: mixed, schema: mixed, errors: list{string}|mixed},...}, summary: array{has_errors: bool, error_count: int<0, max>, validation_success_rate?: 100|float}}, array<never, never>>
     */
    public function validateAllObjects(): JSONResponse
    {
        try {
            $validationResults = $this->settingsService->validateAllObjects();
            return new JSONResponse(data: $validationResults);
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'error'             => 'Failed to validate objects: '.$e->getMessage(),
                    'total_objects'     => 0,
                    'valid_objects'     => 0,
                    'invalid_objects'   => 0,
                    'validation_errors' => [],
                    'summary'           => ['has_errors' => true, 'error_count' => 1],
                ],
                statusCode: 500
                );
        }//end try

    }//end validateAllObjects()

    /**
     * Mass validate all objects by re-saving them to trigger business logic
     *
     * This method re-saves all objects in the system to ensure all business logic
     * is triggered and objects are properly processed according to current rules.
     * Unlike validateAllObjects, this actually saves each object.
     *
     * @return JSONResponse Mass validation results summary.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|500, array, array<never, never>>
     */
    public function massValidateObjects(): JSONResponse
    {
        try {
            // Get request parameters from JSON body or query parameters.
            $maxObjects    = $this->request->getParam('maxObjects', 0);
            $batchSize     = $this->request->getParam('batchSize', 1000);
            $mode          = $this->request->getParam('mode', 'serial');
            $collectErrors = $this->request->getParam('collectErrors', false);

            // Try to get from JSON body if not in query params.
            if ($maxObjects === 0 && $batchSize === 1000) {
                $input = file_get_contents('php://input');
                if ($input !== false && $input !== '') {
                    $data = json_decode($input, true);
                    if ($data !== null && $data !== false) {
                        $maxObjects    = $data['maxObjects'] ?? 0;
                        $batchSize     = $data['batchSize'] ?? 1000;
                        $mode          = $data['mode'] ?? 'serial';
                        $collectErrors = $data['collectErrors'] ?? false;
                    }
                }
            }

            // Convert string boolean to actual boolean.
            if (is_string($collectErrors) === true) {
                $collectErrors = filter_var($collectErrors, FILTER_VALIDATE_BOOLEAN);
            }

            // Delegate to service for business logic.
            $results = $this->settingsService->massValidateObjects(
                maxObjects: $maxObjects,
                batchSize: $batchSize,
                mode: $mode,
                collectErrors: $collectErrors
            );

            return new JSONResponse(data: $results);
        } catch (InvalidArgumentException $e) {
            // Parameter validation errors.
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 400
            );
        } catch (Exception $e) {
            // Other errors.
            $this->logger->error(
                '❌ MASS VALIDATION FAILED',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success'   => false,
                    'error'     => 'Mass validation failed: '.$e->getMessage(),
                    'stats'     => [
                        'total_objects'     => 0,
                        'processed_objects' => 0,
                        'successful_saves'  => 0,
                        'failed_saves'      => 0,
                        'duration_seconds'  => 0,
                    ],
                    'errors'    => [
                        ['error' => $e->getMessage()],
                    ],
                    'timestamp' => date('c'),
                ],
                statusCode: 500
            );
        }//end try

    }//end massValidateObjects()

    /**
     * Predict memory usage for mass validation operation
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with mass validation memory prediction
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, prediction_safe: bool, formatted: array{total_predicted: string, available: string, current_usage?: string, memory_limit?: string, memory_per_object?: string}, objects_to_process?: 10000|mixed, total_objects_available?: 'Unknown (fast mode)', memory_per_object_bytes?: 51200, total_predicted_bytes?: mixed, current_memory_bytes?: int, memory_limit_bytes?: int, available_memory_bytes?: int, safety_margin_percentage?: 80, recommendation?: 'Safe to process'|'Warning: Memory usage may exceed available memory', note?: 'Fast prediction mode - actual object count will be determined during processing'}, array<never, never>>
     */
    public function predictMassValidationMemory(): JSONResponse
    {
        try {
            // Get request parameters.
            $maxObjects = $this->request->getParam('maxObjects', 0);

            // Try to get from JSON body if not in query params.
            if ($maxObjects === 0) {
                $input = file_get_contents('php://input');
                if ($input !== false && $input !== '') {
                    $data = json_decode($input, true);
                    if ($data !== null && $data !== false) {
                        $maxObjects = $data['maxObjects'] ?? 0;
                    }
                }
            }

            // Get current memory usage without loading all objects (much faster).
            $currentMemory = memory_get_usage(true);
            $memoryLimit   = ini_get('memory_limit');

            // Convert memory limit to bytes.
            $memoryLimitBytes = $this->settingsService->convertToBytes($memoryLimit);
            $availableMemory  = $memoryLimitBytes - $currentMemory;

            // Use a lightweight approach - estimate based on typical object size.
            // We'll use the maxObjects parameter or provide a reasonable default estimate.
            $estimatedObjectCount = 10000;
            // Default estimate.
            if ($maxObjects > 0) {
                $estimatedObjectCount = $maxObjects;
            }

            // Estimate memory usage (rough calculation).
            // Assume each object uses approximately 50KB in memory during processing.
            $estimatedMemoryPerObject = 50 * 1024;
            // 50KB.
            $totalEstimatedMemory = $estimatedObjectCount * $estimatedMemoryPerObject;

            // Determine if prediction is safe.
            $predictionSafe = $totalEstimatedMemory < ($availableMemory * 0.8);
            // Use 80% as safety margin.
            // Get recommendation message based on prediction safety.
            if ($predictionSafe === true) {
                $recommendationMessage = 'Safe to process';
            } else {
                $recommendationMessage = 'Warning: Memory usage may exceed available memory';
            }

            $prediction = [
                'success'                  => true,
                'prediction_safe'          => $predictionSafe,
                'objects_to_process'       => $estimatedObjectCount,
                'total_objects_available'  => 'Unknown (fast mode)',
            // Don't count all objects for speed.
                'memory_per_object_bytes'  => $estimatedMemoryPerObject,
                'total_predicted_bytes'    => $totalEstimatedMemory,
                'current_memory_bytes'     => $currentMemory,
                'memory_limit_bytes'       => $memoryLimitBytes,
                'available_memory_bytes'   => $availableMemory,
                'safety_margin_percentage' => 80,
                'formatted'                => [
                    'total_predicted'   => $this->settingsService->formatBytes($totalEstimatedMemory),
                    'available'         => $this->settingsService->formatBytes($availableMemory),
                    'current_usage'     => $this->settingsService->formatBytes($currentMemory),
                    'memory_limit'      => $this->settingsService->formatBytes($memoryLimitBytes),
                    'memory_per_object' => $this->settingsService->formatBytes($estimatedMemoryPerObject),
                ],
                // Get recommendation message based on prediction safety.
                'recommendation'           => $recommendationMessage,
                'note'                     => 'Fast prediction mode - actual object count will be determined during processing',
            ];

            return new JSONResponse(data: $prediction);
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success'         => false,
                    'error'           => 'Failed to predict memory usage: '.$e->getMessage(),
                    'prediction_safe' => true,
                    // Default to safe if we can't predict.
                    'formatted'       => [
                        'total_predicted' => 'Unknown',
                        'available'       => 'Unknown',
                    ],
                ],
                statusCode: 500
            );
        }//end try

    }//end predictMassValidationMemory()
}//end class
