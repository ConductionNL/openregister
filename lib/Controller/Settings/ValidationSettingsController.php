<?php

/**
 * OpenRegister Validation Settings Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
class ValidationSettingsController extends Controller {
	/**
	 * The result fields of a validation run that failed before it counted anything.
	 *
	 * @var array<string, mixed>
	 */
	private const FAILED_VALIDATION_RESULTS = [
		'total_objects' => 0,
		'valid_objects' => 0,
		'invalid_objects' => 0,
		'validation_errors' => [],
		'summary' => ['has_errors' => true, 'error_count' => 1],
	];

	/**
	 * The statistics of a mass validation that failed before it processed anything.
	 *
	 * @var array<string, int>
	 */
	private const EMPTY_MASS_VALIDATION_STATS = [
		'total_objects' => 0,
		'processed_objects' => 0,
		'successful_saves' => 0,
		'failed_saves' => 0,
		'duration_seconds' => 0,
	];

	/**
	 * The mass validation options and their defaults, in the order they are read.
	 *
	 * @var array<string, mixed>
	 */
	private const MASS_VALIDATION_DEFAULTS = [
		'maxObjects' => 0,
		'batchSize' => 1000,
		'mode' => 'serial',
		'collectErrors' => false,
	];

	/**
	 * The object count a memory prediction assumes when the request names none.
	 *
	 * @var int
	 */
	private const DEFAULT_OBJECT_ESTIMATE = 10000;

	/**
	 * The memory one object is assumed to take while it is processed (50KB).
	 *
	 * @var int
	 */
	private const MEMORY_PER_OBJECT_BYTES = 50 * 1024;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param AppSettingsInterface $settingsService Settings service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly AppSettingsInterface $settingsService,
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
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with validation results
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function validateAllObjects(): JSONResponse {
		try {
			$validationResults = $this->settingsService->validateAllObjects();
			return new JSONResponse(data: $validationResults);
		} catch (Exception $e) {
			return new JSONResponse(
				data: array_merge(
					['error' => 'Failed to validate objects: ' . $e->getMessage()],
					self::FAILED_VALIDATION_RESULTS
				),
				statusCode: 500
			);
		}
	}//end validateAllObjects()

	/**
	 * Mass validate all objects by re-saving them to trigger business logic
	 *
	 * This method re-saves all objects in the system to ensure all business logic
	 * is triggered and objects are properly processed according to current rules.
	 * Unlike validateAllObjects, this actually saves each object.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with mass validation results
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function massValidateObjects(): JSONResponse {
		try {
			return new JSONResponse(data: $this->runMassValidation(options: $this->massValidationOptions()));
		} catch (InvalidArgumentException $e) {
			// Parameter validation errors.
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		} catch (Exception $e) {
			return $this->massValidationFailed(e: $e);
		}
	}//end massValidateObjects()

	/**
	 * Run a mass validation with the options the request asked for.
	 *
	 * @param array{maxObjects: int, batchSize: int, mode: string, collectErrors: bool} $options The options.
	 *
	 * @return array The validation results
	 */
	private function runMassValidation(array $options): array {
		return $this->settingsService->massValidateObjects(
			maxObjects: $options['maxObjects'],
			batchSize: $options['batchSize'],
			mode: $options['mode'],
			collectErrors: $options['collectErrors']
		);
	}//end runMassValidation()

	/**
	 * The mass validation options: query parameters, else the JSON body when the query left the defaults.
	 *
	 * @return array{maxObjects: mixed, batchSize: mixed, mode: mixed, collectErrors: mixed} The options.
	 */
	private function massValidationOptions(): array {
		$options = [];
		foreach (self::MASS_VALIDATION_DEFAULTS as $key => $default) {
			$options[$key] = $this->request->getParam($key, $default);
		}

		if ($options['maxObjects'] === 0 && $options['batchSize'] === 1000) {
			$options = $this->bodyMassValidationOptions(options: $options);
		}

		// Convert string boolean to actual boolean.
		if (is_string($options['collectErrors']) === true) {
			$options['collectErrors'] = filter_var($options['collectErrors'], FILTER_VALIDATE_BOOLEAN);
		}

		return $options;
	}//end massValidationOptions()

	/**
	 * The mass validation options from the JSON body, replacing all query options when there is one.
	 *
	 * @param array<string, mixed> $options The options from the query.
	 *
	 * @return array<string, mixed> The body's options with defaults, or the query's when there is no body.
	 */
	private function bodyMassValidationOptions(array $options): array {
		$data = $this->jsonBody();
		if ($data === null) {
			return $options;
		}

		$bodyOptions = [];
		foreach (self::MASS_VALIDATION_DEFAULTS as $key => $default) {
			$bodyOptions[$key] = $data[$key] ?? $default;
		}

		return $bodyOptions;
	}//end bodyMassValidationOptions()

	/**
	 * The decoded JSON request body, or null when there is none (empty, unreadable, null or false).
	 *
	 * @return mixed The decoded body.
	 */
	private function jsonBody(): mixed {
		$input = file_get_contents('php://input');
		if ($input === false || $input === '') {
			return null;
		}

		$data = json_decode($input, true);
		if ($data === false) {
			return null;
		}

		return $data;
	}//end jsonBody()

	/**
	 * Log a failed mass validation and answer it with empty statistics.
	 *
	 * @param Exception $e The exception.
	 *
	 * @return JSONResponse The 500 answer.
	 */
	private function massValidationFailed(Exception $e): JSONResponse {
		$this->logger->error(
			message: '[ValidationSettingsController] ❌ MASS VALIDATION FAILED',
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
		);

		return new JSONResponse(data: $this->massValidationFailure(e: $e), statusCode: 500);
	}//end massValidationFailed()

	/**
	 * The body answering a failed mass validation.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return array<string, mixed> The body
	 */
	private function massValidationFailure(Exception $e): array {
		return [
			'success' => false,
			'error' => 'Mass validation failed: ' . $e->getMessage(),
			'stats' => self::EMPTY_MASS_VALIDATION_STATS,
			'errors' => [['error' => $e->getMessage()]],
			'timestamp' => date('c'),
		];
	}//end massValidationFailure()

	/**
	 * Predict memory usage for mass validation operation
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with memory prediction
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function predictMassValidationMemory(): JSONResponse {
		try {
			$estimate = $this->memoryEstimate(maxObjects: $this->predictionMaxObjects());
			return new JSONResponse(data: $this->memoryPrediction(estimate: $estimate));
		} catch (Exception $e) {
			return new JSONResponse(data: $this->predictionFailure(e: $e), statusCode: 500);
		}
	}//end predictMassValidationMemory()

	/**
	 * The body answering a memory prediction that failed. It defaults to safe.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return array<string, mixed> The body
	 */
	private function predictionFailure(Exception $e): array {
		return [
			'success' => false,
			'error' => 'Failed to predict memory usage: ' . $e->getMessage(),
			// Default to safe if we can't predict.
			'prediction_safe' => true,
			'formatted' => ['total_predicted' => 'Unknown', 'available' => 'Unknown'],
		];
	}//end predictionFailure()

	/**
	 * The object count asked for: the query parameter, else the JSON body's when the query left it at 0.
	 *
	 * @return mixed The requested maximum number of objects.
	 */
	private function predictionMaxObjects(): mixed {
		$maxObjects = $this->request->getParam('maxObjects', 0);
		if ($maxObjects !== 0) {
			return $maxObjects;
		}

		$data = $this->jsonBody();
		if ($data === null) {
			return $maxObjects;
		}

		return $data['maxObjects'] ?? 0;
	}//end predictionMaxObjects()

	/**
	 * Estimate the memory a mass validation needs against what this process has left.
	 *
	 * A lightweight approach: no objects are loaded or counted; each object is
	 * assumed to take MEMORY_PER_OBJECT_BYTES while it is processed.
	 *
	 * @param mixed $maxObjects The requested maximum number of objects.
	 *
	 * @return array{count: mixed, total: mixed, current: int, limit: int, available: int, safe: bool} The estimate.
	 */
	private function memoryEstimate(mixed $maxObjects): array {
		$currentMemory = memory_get_usage(true);
		$memoryLimitBytes = $this->settingsService->convertToBytes(ini_get('memory_limit'));
		$availableMemory = $memoryLimitBytes - $currentMemory;

		$objectCount = $this->estimatedObjectCount(maxObjects: $maxObjects);
		$totalEstimatedMemory = $objectCount * self::MEMORY_PER_OBJECT_BYTES;
		return [
			'count' => $objectCount,
			'total' => $totalEstimatedMemory,
			'current' => $currentMemory,
			'limit' => $memoryLimitBytes,
			'available' => $availableMemory,
			// Use 80% as safety margin.
			'safe' => $totalEstimatedMemory < ($availableMemory * 0.8),
		];
	}//end memoryEstimate()

	/**
	 * The object count a prediction assumes: the requested maximum, else a default estimate.
	 *
	 * @param mixed $maxObjects The requested maximum number of objects.
	 *
	 * @return mixed The object count.
	 */
	private function estimatedObjectCount(mixed $maxObjects): mixed {
		if ($maxObjects > 0) {
			return $maxObjects;
		}

		return self::DEFAULT_OBJECT_ESTIMATE;
	}//end estimatedObjectCount()

	/**
	 * The memory prediction answer for an estimate.
	 *
	 * @param array<string, mixed> $estimate The estimate from memoryEstimate().
	 *
	 * @return array<string, mixed> The prediction.
	 */
	private function memoryPrediction(array $estimate): array {
		return array_merge(
			$this->predictionFigures(estimate: $estimate),
			[
				'formatted' => $this->formattedPrediction(estimate: $estimate),
				'recommendation' => $this->recommendation(safe: $estimate['safe']),
				'note' => 'Fast prediction mode - actual object count will be determined during processing',
			]
		);
	}//end memoryPrediction()

	/**
	 * The raw figures of a memory prediction.
	 *
	 * @param array<string, mixed> $estimate The estimate from memoryEstimate().
	 *
	 * @return array<string, mixed> The figures, in bytes.
	 */
	private function predictionFigures(array $estimate): array {
		return [
			'success' => true,
			'prediction_safe' => $estimate['safe'],
			'objects_to_process' => $estimate['count'],
			// Don't count all objects for speed.
			'total_objects_available' => 'Unknown (fast mode)',
			'memory_per_object_bytes' => self::MEMORY_PER_OBJECT_BYTES,
			'total_predicted_bytes' => $estimate['total'],
			'current_memory_bytes' => $estimate['current'],
			'memory_limit_bytes' => $estimate['limit'],
			'available_memory_bytes' => $estimate['available'],
			'safety_margin_percentage' => 80,
		];
	}//end predictionFigures()

	/**
	 * The figures of a memory prediction formatted for display.
	 *
	 * @param array<string, mixed> $estimate The estimate from memoryEstimate().
	 *
	 * @return array<string, string> The formatted figures.
	 */
	private function formattedPrediction(array $estimate): array {
		return [
			'total_predicted' => $this->settingsService->formatBytes($estimate['total']),
			'available' => $this->settingsService->formatBytes($estimate['available']),
			'current_usage' => $this->settingsService->formatBytes($estimate['current']),
			'memory_limit' => $this->settingsService->formatBytes($estimate['limit']),
			'memory_per_object' => $this->settingsService->formatBytes(self::MEMORY_PER_OBJECT_BYTES),
		];
	}//end formattedPrediction()

	/**
	 * The recommendation for a prediction.
	 *
	 * @param bool $safe Whether the prediction fits the safety margin.
	 *
	 * @return string The recommendation.
	 */
	private function recommendation(bool $safe): string {
		if ($safe === true) {
			return 'Safe to process';
		}

		return 'Warning: Memory usage may exceed available memory';
	}//end recommendation()
}//end class
