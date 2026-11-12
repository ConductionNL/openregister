<?php

/**
 * OpenRegister ManifestController
 *
 * Exposes a per-app `/api/manifest/{appId}` endpoint that returns the host
 * app's bundled manifest.json enriched with a `runtime.user` block built
 * from the authenticated user's OpenRegister profile object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-012
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ManifestControllerServices;
use OCA\OpenRegister\Service\ManifestServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves enriched manifests for host apps.
 *
 * The endpoint is public (no CSRF, no admin) so that unauthenticated clients
 * can still load a manifest and receive `runtime.user = null`; public pages
 * are filtered by nc-vue using that null signal.
 *
 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-012
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ManifestController extends Controller {
	/**
	 * Service that enriches manifests.
	 *
	 * @var ManifestServiceInterface
	 */
	private readonly ManifestServiceInterface $manifestService;

	/**
	 * Nextcloud app manager (path resolution).
	 *
	 * @var IAppManager
	 */
	private readonly IAppManager $appManager;

	/**
	 * PSR logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param ManifestControllerServices $services The controller's collaborators.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		ManifestControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->manifestService = $services->manifestService;
		$this->appManager = $services->appManager;
		$this->logger = $services->logger;
	}//end __construct()

	/**
	 * Return the manifest for `appId`, enriched with `runtime.user` context.
	 *
	 * GET /index.php/apps/openregister/api/manifest/{appId}
	 *
	 * @param string $appId The Nextcloud app ID of the calling application.
	 *
	 * @return JSONResponse Enriched manifest, or 404/500 on failure.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-012
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(string $appId): JSONResponse {
		// Sanitise: appId must be a valid NC app slug (alphanumeric + underscore/hyphen).
		if (preg_match('/^[a-z0-9_-]+$/i', $appId) !== 1) {
			return new JSONResponse(['error' => 'Invalid app ID.'], Http::STATUS_BAD_REQUEST);
		}

		$manifest = $this->loadBundledManifest(appId: $appId);
		if ($manifest === null) {
			return $this->manifestNotFound(appId: $appId);
		}

		try {
			return $this->enrichedResponse(manifest: $manifest);
		} catch (Throwable $e) {
			return $this->enrichmentFailed(appId: $appId, e: $e);
		}
	}//end index()

	/**
	 * The 404 for an app without a loadable bundled manifest.
	 *
	 * @param string $appId The app slug.
	 *
	 * @return JSONResponse The 404.
	 *
	 * @spec exclude Private response helper of the manifest endpoint.
	 */
	private function manifestNotFound(string $appId): JSONResponse {
		return new JSONResponse(
			['error' => sprintf('Manifest not found for app "%s".', $appId)],
			Http::STATUS_NOT_FOUND
		);
	}//end manifestNotFound()

	/**
	 * The enriched manifest with an ETag over the final per-user payload.
	 *
	 * NotModifiedMiddleware turns a matching If-None-Match into a 304, so warm
	 * reloads skip the transfer (fleet manifests reach ~270KB) while staying
	 * fully fresh — enrichment still runs, and any change in manifest OR
	 * runtime.user changes the tag. `private, no-cache` replaces the default
	 * `no-store`: the browser MAY store the response but MUST revalidate, which
	 * is what makes the 304 path usable at all.
	 *
	 * @param array<string, mixed> $manifest The bundled manifest.
	 *
	 * @return JSONResponse The enriched manifest.
	 *
	 * @spec exclude Private response helper of the manifest endpoint.
	 */
	private function enrichedResponse(array $manifest): JSONResponse {
		$enriched = $this->manifestService->getEnrichedManifest(manifest: $manifest);

		$response = new JSONResponse($enriched);
		$response->setETag(hash(algo: 'xxh128', data: json_encode($enriched)));
		$response->addHeader('Cache-Control', 'private, no-cache');
		return $response;
	}//end enrichedResponse()

	/**
	 * Log an enrichment failure and answer a generic 500.
	 *
	 * @param string $appId The app slug.
	 * @param Throwable $e The enrichment failure.
	 *
	 * @return JSONResponse The 500.
	 *
	 * @spec exclude Private response helper of the manifest endpoint.
	 */
	private function enrichmentFailed(string $appId, Throwable $e): JSONResponse {
		$this->logger->error(
			message: sprintf('[ManifestController] Enrichment failed for app "%s": %s', $appId, $e->getMessage()),
			context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $appId]
		);
		return new JSONResponse(['error' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end enrichmentFailed()

	/**
	 * Load and JSON-decode a host app's bundled `src/manifest.json`.
	 *
	 * @param string $appId The Nextcloud app ID.
	 *
	 * @return array<string, mixed>|null Decoded manifest, or null if not readable.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec exclude Private helper: loads + JSON-decodes a host app's bundled manifest.json; the manifest endpoint
	 *              contract is owned by openregister-app-manifest REQ-OR-MAN-012.
	 */
	private function loadBundledManifest(string $appId): ?array {
		$manifestPath = $this->readableManifestPath(appId: $appId);
		if ($manifestPath === null) {
			return null;
		}

		return $this->decodeManifest(appId: $appId, manifestPath: $manifestPath);
	}//end loadBundledManifest()

	/**
	 * The app's installation path, resolved via the app manager.
	 *
	 * @param string $appId The app slug.
	 *
	 * @return string|null The path, or null when the app is unknown.
	 *
	 * @spec exclude Private helper of loadBundledManifest().
	 */
	private function appPath(string $appId): ?string {
		try {
			return $this->appManager->getAppPath(appId: $appId);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: sprintf('[ManifestController] App "%s" path not found: %s', $appId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end appPath()

	/**
	 * The app's bundled `src/manifest.json` path, when it is readable.
	 *
	 * @param string $appId The app slug.
	 *
	 * @return string|null The path, or null when the app or file is missing.
	 *
	 * @spec exclude Private helper of loadBundledManifest().
	 */
	private function readableManifestPath(string $appId): ?string {
		$appPath = $this->appPath(appId: $appId);
		if ($appPath === null) {
			return null;
		}

		$manifestPath = $appPath . '/src/manifest.json';
		if (is_readable($manifestPath) === false) {
			$this->logger->debug(
				message: sprintf('[ManifestController] manifest.json not readable at "%s"', $manifestPath),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return $manifestPath;
	}//end readableManifestPath()

	/**
	 * Read and JSON-decode a manifest file into an array.
	 *
	 * @param string $appId The app slug, for the log line.
	 * @param string $manifestPath The readable manifest path.
	 *
	 * @return array<string, mixed>|null The decoded manifest, or null when it is not a JSON object.
	 *
	 * @spec exclude Private helper of loadBundledManifest().
	 */
	private function decodeManifest(string $appId, string $manifestPath): ?array {
		$raw = file_get_contents($manifestPath);
		if ($raw === false) {
			return null;
		}

		$decoded = json_decode($raw, associative: true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			$this->logger->warning(
				message: sprintf('[ManifestController] manifest.json for "%s" is invalid JSON', $appId),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return $decoded;
	}//end decodeManifest()
}//end class
