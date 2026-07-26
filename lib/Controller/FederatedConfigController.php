<?php

/**
 * The federated configuration sharing API.
 *
 * The surface a client (or the builder UI) uses to share configuration over
 * GitHub: list the shareable types every app has contributed, package a
 * selection into a portable bundle, and install a bundle — the last governed by
 * the organisation's source allowlist.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;
use UnexpectedValueException;

/**
 * REST surface for sharing, bundling and installing configuration.
 */
class FederatedConfigController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName The app id.
     * @param IRequest               $request The request.
     * @param FederatedConfigService $service The federation engine.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FederatedConfigService $service
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * The shareable configuration types every app has contributed.
     *
     * @return JSONResponse `{types: [{id, name, topic}]}`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function types(): JSONResponse
    {
        return new JSONResponse(['types' => $this->service->types()]);

    }//end types()

    /**
     * Package a selection of a type's configuration into a portable bundle.
     *
     * @return JSONResponse The bundle, or a 4xx.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function bundle(): JSONResponse
    {
        $type = trim((string) $this->request->getParam('type', ''));
        if ($type === '') {
            return new JSONResponse(['error' => 'A bundle needs a type.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $bundle = $this->service->bundle(typeId: $type, selection: (array) $this->request->getParam('selection', []));
        } catch (UnexpectedValueException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($bundle);

    }//end bundle()

    /**
     * Install a bundle, subject to the organisation's source allowlist.
     *
     * @return JSONResponse The install result, 404 unknown type, or 403 when the
     *                      source is not allowlisted.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function install(): JSONResponse
    {
        $type = trim((string) $this->request->getParam('type', ''));
        if ($type === '') {
            return new JSONResponse(['error' => 'An install needs a type.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->service->install(
                typeId: $type,
                bundle: (array) $this->request->getParam('bundle', []),
                source: trim((string) $this->request->getParam('source', ''))
            );
        } catch (UnexpectedValueException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            // A blocked source (allowlist) is a 403; anything else a 500.
            $status = Http::STATUS_INTERNAL_SERVER_ERROR;
            if (str_contains($e->getMessage(), 'allowlist') === true) {
                $status = Http::STATUS_FORBIDDEN;
            }

            return new JSONResponse(['error' => $e->getMessage()], $status);
        }

        return new JSONResponse($result);

    }//end install()
}//end class
