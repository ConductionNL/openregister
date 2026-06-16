<?php

/**
 * OpenRegister AppHost — Generic Health Controller
 *
 * Engine-owned public health endpoint. Leaf apps alias their existing
 * `/api/health` route at this controller (keeping their URL unchanged); the
 * controller resolves the calling app's manifest, executes its declarative
 * health checks, and renders the ADR-006 `{status, app, version, checks}`
 * shape. Auth posture (public) and the contract are owned here — leaf apps
 * cannot drift them through the manifest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public, declarative health endpoint for AppHost-adopting apps.
 *
 * @psalm-suppress UnusedClass
 */
class GenericHealthController extends Controller
{
    /**
     * Constructor.
     *
     * The controller's `$appName` is the calling (leaf) app id — set by the
     * leaf app's alias registration in its own Application.php. For OpenRegister
     * self-dogfooding it is `openregister`.
     *
     * @param string              $appName        Calling app id.
     * @param IRequest            $request        HTTP request.
     * @param ManifestLoader      $manifestLoader Loads the app's observability config.
     * @param HealthCheckExecutor $executor       Runs the checks.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ManifestLoader $manifestLoader,
        private readonly HealthCheckExecutor $executor
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * GET /api/health — declarative health check (ADR-006).
     *
     * @return JSONResponse `{status, app, version, checks}` with HTTP code per statusCodePolicy.
     *
     * @spec openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md — Requirement: Declarative Health Execution
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $appId    = $this->appName;
        $manifest = $this->manifestLoader->load(appId: $appId);
        $result   = $this->executor->execute(manifest: $manifest);

        $response = new JSONResponse(
            [
                'status'  => $result->status,
                'app'     => $appId,
                'version' => $this->manifestLoader->appVersion(appId: $appId),
                'checks'  => $result->checks,
            ],
            $result->httpStatusCode
        );

        if ($manifest->cors === true) {
            $response->addHeader('Access-Control-Allow-Origin', '*');
            $response->addHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
        }

        return $response;
    }//end index()
}//end class
