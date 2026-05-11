<?php

/**
 * OpenRegister ManifestController
 *
 * Exposes a per-app `/api/manifest/{appId}` endpoint that returns the host
 * app's bundled manifest.json enriched with a `runtime.user` block built
 * from the authenticated user's OpenRegister profile object.
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
 * @spec openspec/changes/manifest-user-context/tasks.md
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ManifestService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ManifestController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName         The app name ('openregister').
     * @param IRequest        $request         The HTTP request.
     * @param ManifestService $manifestService Service that enriches manifests.
     * @param IAppManager     $appManager      Nextcloud app manager (path resolution).
     * @param LoggerInterface $logger          PSR logger.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ManifestService $manifestService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
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
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function index(string $appId): JSONResponse
    {
        // Sanitise: appId must be a valid NC app slug (alphanumeric + underscore/hyphen).
        if (preg_match('/^[a-z0-9_-]+$/i', $appId) !== 1) {
            return new JSONResponse(['error' => 'Invalid app ID.'], Http::STATUS_BAD_REQUEST);
        }

        // Resolve the app's manifest.json path via the app manager.
        $manifest = $this->loadBundledManifest(appId: $appId);
        if ($manifest === null) {
            return new JSONResponse(
                ['error' => sprintf('Manifest not found for app "%s".', $appId)],
                Http::STATUS_NOT_FOUND
            );
        }

        try {
            $enriched = $this->manifestService->getEnrichedManifest(manifest: $manifest);
            return new JSONResponse($enriched);
        } catch (Throwable $e) {
            $this->logger->error(
                message: sprintf('[ManifestController] Enrichment failed for app "%s": %s', $appId, $e->getMessage()),
                context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $appId]
            );
            return new JSONResponse(['error' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end index()

    /**
     * Load and JSON-decode a host app's bundled `src/manifest.json`.
     *
     * @param string $appId The Nextcloud app ID.
     *
     * @return array<string, mixed>|null Decoded manifest, or null if not readable.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function loadBundledManifest(string $appId): ?array
    {
        try {
            $appPath = $this->appManager->getAppPath(appId: $appId);
        } catch (Throwable $e) {
            $this->logger->debug(
                message: sprintf('[ManifestController] App "%s" path not found: %s', $appId, $e->getMessage()),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        $manifestPath = $appPath.'/src/manifest.json';
        if (is_readable($manifestPath) === false) {
            $this->logger->debug(
                message: sprintf('[ManifestController] manifest.json not readable at "%s"', $manifestPath),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

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
    }//end loadBundledManifest()
}//end class
