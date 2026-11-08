<?php

/**
 * OpenRegister Cache Settings Controller
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
use OCA\OpenRegister\Controller\Support\CacheSettingsControllerServices;
use OC\Files\AppData\Factory;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\GenericFileException;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for cache management.
 *
 * Handles:
 * - Cache statistics
 * - Cache clearing operations
 * - Cache warmup operations
 * - Cache warmup interval configuration
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class CacheSettingsController extends Controller {
	/**
	 * Settings service.
	 *
	 * @var AppSettingsInterface
	 */
	private readonly AppSettingsInterface $settingsService;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * App configuration.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param CacheSettingsControllerServices $services The controller's collaborators.
	 * @param Factory $appDataFactory App data factory.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		CacheSettingsControllerServices $services,
		private readonly Factory $appDataFactory,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->settingsService = $services->settingsService;
		$this->logger = $services->logger;
		$this->appConfig = $services->appConfig;
	}//end __construct()

	/**
	 * Get comprehensive cache statistics and performance metrics.
	 *
	 * This method provides detailed insights into cache usage, performance, memory consumption,
	 * hit/miss rates, and object name cache statistics for admin monitoring.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with cache statistics or error
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getCacheStats(): JSONResponse {
		try {
			$result = $this->settingsService->getCacheStats();
			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return $this->failure(e: $e, statusCode: 500);
		}
	}//end getCacheStats()

	/**
	 * Clear cache with granular control.
	 *
	 * This method supports clearing different types of caches: 'all', 'object', 'schema', 'facet', 'distributed', 'names'.
	 * It accepts a JSON body with 'type' parameter to specify which cache to clear.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with clear cache result
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function clearCache(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$type = $data['type'] ?? 'all';

			$result = $this->settingsService->clearCache($type);
			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return $this->failure(e: $e, statusCode: 500);
		}
	}//end clearCache()

	/**
	 * Warmup object names cache manually.
	 *
	 * This method triggers manual cache warmup for object names to improve performance
	 * after system maintenance or during off-peak hours.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with warmup result or error
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function warmupNamesCache(): JSONResponse {
		try {
			$result = $this->settingsService->warmupNamesCache();
			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return $this->failure(e: $e, statusCode: 422);
		}
	}//end warmupNamesCache()

	/**
	 * Get the cache warmup interval configuration.
	 *
	 * Returns the current interval in seconds and the last run timestamp.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with warmup interval config
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getWarmupInterval(): JSONResponse {
		try {
			$interval = (int)$this->appConfig->getValueString(app: 'openregister', key: 'cache_warmup_interval', default: '3600');
			$lastRun = $this->appConfig->getValueString(app: 'openregister', key: 'cache_warmup_last_run', default: '');

			$lastRun = $this->lastRunOrNull(lastRun: $lastRun);

			return new JSONResponse(data: ['success' => true, 'interval' => $interval, 'last_run' => $lastRun, 'enabled' => $interval > 0]);
		} catch (Exception $e) {
			return $this->failure(e: $e, statusCode: 500);
		}
	}//end getWarmupInterval()

	/**
	 * The last warmup run as saved, or null when none is saved.
	 *
	 * @param string $lastRun The saved last run.
	 *
	 * @return string|null The last run.
	 */
	private function lastRunOrNull(string $lastRun): ?string {
		if (empty($lastRun) === false) {
			return $lastRun;
		}

		return null;
	}//end lastRunOrNull()

	/**
	 * Set the cache warmup interval.
	 *
	 * Accepts an interval in seconds. Use 0 to disable the warmup job.
	 * Minimum non-zero value is 300 seconds (5 minutes).
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated interval config
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function setWarmupInterval(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$interval = (int)($data['interval'] ?? 3600);
			// Validate: 0 (disabled) or >= 300 seconds.
			if ($interval !== 0 && $interval < 300) {
				return new JSONResponse(
					data: ['success' => false, 'error' => 'Interval must be 0 (disabled) or at least 300 seconds (5 minutes)'],
					statusCode: 422
				);
			}

			return $this->saveWarmupInterval(interval: $interval);
		} catch (Exception $e) {
			return $this->failure(e: $e, statusCode: 500);
		}
	}//end setWarmupInterval()

	/**
	 * Save the warmup interval, log the change and answer it.
	 *
	 * @param int $interval The interval in seconds; 0 disables the warmup job.
	 *
	 * @return JSONResponse The saved interval.
	 */
	private function saveWarmupInterval(int $interval): JSONResponse {
		$this->appConfig->setValueString(app: 'openregister', key: 'cache_warmup_interval', value: (string)$interval);
		$this->logger->info(
			message: '[CacheSettingsController] Cache warmup interval updated to ' . $interval . ' seconds',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return new JSONResponse(
			data: [
				'success' => true,
				'interval' => $interval,
				'enabled' => $interval > 0,
				'message' => $this->intervalMessage(interval: $interval),
			]
		);
	}//end saveWarmupInterval()

	/**
	 * Describe a saved warmup interval.
	 *
	 * @param int $interval The interval in seconds; 0 disables the warmup job.
	 *
	 * @return string The message.
	 */
	private function intervalMessage(int $interval): string {
		if ($interval === 0) {
			return 'Cache warmup disabled';
		}

		return 'Cache warmup interval set to ' . $interval . ' seconds';
	}//end intervalMessage()

	/**
	 * The answer for an action that threw.
	 *
	 * @param Exception $e The exception.
	 * @param int $statusCode The response status.
	 *
	 * @return JSONResponse The failure.
	 */
	private function failure(Exception $e, int $statusCode): JSONResponse {
		return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $statusCode);
	}//end failure()

	/**
	 * Invalidate the Nextcloud app store cache.
	 *
	 * This forces Nextcloud to fetch fresh app data from apps.nextcloud.com
	 * on the next request to Settings > Apps. Instead of deleting the cache
	 * files (which can cause permission issues), this method sets the cached
	 * timestamp to 0, making the cache appear expired.
	 *
	 * The cache files that can be invalidated:
	 * - apps.json: Main app catalog (default)
	 * - categories.json: App categories
	 * - discover.json: Featured/discover section
	 * - all: All app store cache files
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with invalidation result
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function clearAppStoreCache(): JSONResponse {
		try {
			$type = $this->request->getParams()['type'] ?? 'apps';
			$folder = $this->appDataFactory->get('appstore')->getFolder('/');
			$result = $this->invalidateCacheFiles(
				folder: $folder,
				fileNames: $this->appStoreCacheFiles(type: $type)
			);

			return $this->appStoreCacheInvalidated(result: $result);
		} catch (NotFoundException $e) {
			// The appstore folder doesn't exist yet (no cache).
			return $this->noAppStoreCache();
		} catch (Exception $e) {
			return $this->appStoreCacheFailure(e: $e);
		}
	}//end clearAppStoreCache()

	/**
	 * The answer for an app store cache invalidation.
	 *
	 * @param array{invalidated: list<string>, skipped: list<string>} $result The outcome.
	 *
	 * @return JSONResponse The 200 answer.
	 */
	private function appStoreCacheInvalidated(array $result): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'App store cache invalidated successfully',
				'invalidated' => $result['invalidated'],
				'skipped' => $result['skipped'],
				'note' => 'Fresh data will be fetched on the next visit to Settings > Apps',
			],
			statusCode: 200
		);
	}//end appStoreCacheInvalidated()

	/**
	 * The answer when the app store folder does not exist yet, so there is no cache.
	 *
	 * @return JSONResponse The 200 answer.
	 */
	private function noAppStoreCache(): JSONResponse {
		return new JSONResponse(
			data: ['success' => true, 'message' => 'No app store cache exists yet', 'invalidated' => [], 'skipped' => []],
			statusCode: 200
		);
	}//end noAppStoreCache()

	/**
	 * Log a failed app store cache invalidation and answer it.
	 *
	 * @param Exception $e The exception.
	 *
	 * @return JSONResponse The 500 answer.
	 */
	private function appStoreCacheFailure(Exception $e): JSONResponse {
		$this->logger->error(
			message: '[CacheSettingsController] Failed to invalidate app store cache: ' . $e->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
		);

		return new JSONResponse(
			data: ['success' => false, 'error' => 'Failed to invalidate app store cache: ' . $e->getMessage()],
			statusCode: 500
		);
	}//end appStoreCacheFailure()

	/**
	 * The app store cache files that a cache type covers.
	 *
	 * @param mixed $type The requested cache type: apps, categories, discover or all.
	 *
	 * @return list<string> The file names; apps.json for an unknown type.
	 */
	private function appStoreCacheFiles(mixed $type): array {
		return match ($type) {
			'apps' => ['apps.json'],
			'categories' => ['categories.json'],
			'discover' => ['discover.json'],
			'all' => ['apps.json', 'categories.json', 'discover.json'],
			default => ['apps.json'],
		};
	}//end appStoreCacheFiles()

	/**
	 * Expire app store cache files by setting their timestamp to 0.
	 *
	 * @param ISimpleFolder $folder The app store app data folder.
	 * @param list<string> $fileNames The cache files to expire.
	 *
	 * @return array{invalidated: list<string>, skipped: list<string>} The expired files, and the skipped ones with the reason.
	 */
	private function invalidateCacheFiles(ISimpleFolder $folder, array $fileNames): array {
		$result = ['invalidated' => [], 'skipped' => []];
		foreach ($fileNames as $fileName) {
			[$outcome, $entry] = $this->invalidateCacheFile(folder: $folder, fileName: $fileName);
			$result[$outcome][] = $entry;
		}

		return $result;
	}//end invalidateCacheFiles()

	/**
	 * Expire one app store cache file by setting its timestamp to 0.
	 *
	 * The Fetcher checks: timestamp > (now - TTL). With timestamp=0 that is
	 * always false, so the next request refreshes the cache.
	 *
	 * @param ISimpleFolder $folder The app store app data folder.
	 * @param string $fileName The cache file to expire.
	 *
	 * @return array{0: 'invalidated'|'skipped', 1: string} The outcome and its entry: the file name, with the reason when skipped.
	 */
	private function invalidateCacheFile(ISimpleFolder $folder, string $fileName): array {
		try {
			$file = $folder->getFile($fileName);
			$json = json_decode($file->getContent(), true);
			if (is_array($json) !== true || isset($json['timestamp']) !== true) {
				return ['skipped', $fileName . ' (invalid format)'];
			}

			$json['timestamp'] = 0;
			$file->putContent(json_encode($json));
			return ['invalidated', $fileName];
		} catch (NotFoundException|GenericFileException $e) {
			// File doesn't exist, nothing to invalidate.
			return ['skipped', $fileName . ' (not found)'];
		}
	}//end invalidateCacheFile()
}//end class
