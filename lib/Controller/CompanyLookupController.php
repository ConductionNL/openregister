<?php

/**
 * CompanyLookupController — read-only company-lookup endpoints backed by
 * the KvK and OpenCorporates integration leaves (external,
 * OpenConnector-routed).
 *
 * Surface:
 *   - GET /api/integrations/kvk/company?kvkNumber=         — KvK lookup by number
 *   - GET /api/integrations/kvk/search?q=                  — KvK free-text search
 *   - GET /api/integrations/opencorporates/search?q=       — OpenCorporates search
 *
 * Unlike the NC-native Tier-2 leaves, KvK / OpenCorporates are external:
 * there is no local NC app to gate on. The OpenConnector app carries the
 * `kvk` / `opencorporates` sources + credentials; when one (or its source)
 * is missing the provider returns the 4-state cause
 * (`openconnector-down` / `openconnector-source-missing` / `provider-auth`
 * / `upstream-service-down`) which this controller relays as a 503 with
 * `details.cause` so a consuming app renders the right banner (AD-23).
 *
 * The endpoints round-trip the raw upstream JSON rows — the Dutch→prospect
 * (KvK) and company→prospect (OpenCorporates) field mapping stays in the
 * consuming app (pipelinq's `KvkResultMapper` / `OpenCorporatesResultMapper`).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only company-lookup controller (KvK + OpenCorporates leaves).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class CompanyLookupController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName                App id.
     * @param IRequest               $request                HTTP request.
     * @param KvkProvider            $kvkProvider            KvK lookup leaf.
     * @param OpenCorporatesProvider $openCorporatesProvider OpenCorporates search leaf.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly KvkProvider $kvkProvider,
        private readonly OpenCorporatesProvider $openCorporatesProvider,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Look up a single company by its KvK number.
     *
     * Query param: `kvkNumber` (8-digit KvK number, required).
     *
     * @return JSONResponse `{ results, total }` on success, or a 503 with
     *                      `details.cause` when the `kvk` source is
     *                      unconfigured / KvK is down (AD-23).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt External-gateway proxy: forwards a KVK number to the admin-configured external KVK
     *   provider; takes no OpenRegister object id.
     *
     * @spec openspec/changes/integration-kvk-opencorporates/specs/integration-company-lookup/spec.md
     */
    public function kvkCompany(): JSONResponse
    {
        $kvkNumber = trim((string) $this->request->getParam('kvkNumber', ''));
        if ($kvkNumber === '') {
            return new JSONResponse(['error' => 'kvkNumber is required'], 400);
        }

        $result = $this->kvkProvider->lookupByKvkNumber($kvkNumber);

        return $this->relay(result: $result, sourceLabel: 'kvk');
    }//end kvkCompany()

    /**
     * Free-text company search against the KvK Handelsregister.
     *
     * Query params: `q` (company-name query), `limit`, `page`, plus optional
     * KvK criteria (`plaats`, `type`, `sbiHoofdActiviteit`) passed through.
     *
     * @return JSONResponse `{ results, total, limit, page }` on success, or
     *                      a 503 with `details.cause` when unconfigured/down.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt External-gateway proxy: free-text company search against the admin-configured KVK
     *   provider; takes no OpenRegister object id.
     *
     * @spec openspec/changes/integration-kvk-opencorporates/specs/integration-company-lookup/spec.md
     */
    public function kvkSearch(): JSONResponse
    {
        $query = (string) $this->request->getParam('q', '');
        $limit = (int) $this->request->getParam('limit', 25);
        $page  = (int) $this->request->getParam('page', 1);

        $criteria = [];
        foreach (['plaats', 'type', 'sbiHoofdActiviteit'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null && (string) $value !== '') {
                $criteria[$key] = (string) $value;
            }
        }

        $result = $this->kvkProvider->searchCompanies($query, $criteria, $limit, $page);

        return $this->relay(result: $result, sourceLabel: 'kvk');
    }//end kvkSearch()

    /**
     * Free-text company search against the OpenCorporates register.
     *
     * Query params: `q` (company-name query), `jurisdiction` (ISO code,
     * optional), `limit`, `page`.
     *
     * @return JSONResponse `{ results, total, limit, page }` on success, or
     *                      a 503 with `details.cause` when unconfigured/down.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt External-gateway proxy: free-text company search against the admin-configured
     *   OpenCorporates provider; takes no OpenRegister object id.
     *
     * @spec openspec/changes/integration-kvk-opencorporates/specs/integration-company-lookup/spec.md
     */
    public function openCorporatesSearch(): JSONResponse
    {
        $query = (string) $this->request->getParam('q', '');

        $jurisdiction = $this->request->getParam('jurisdiction');
        if ($jurisdiction !== null) {
            $jurisdiction = (string) $jurisdiction;
        }

        $limit = (int) $this->request->getParam('limit', 30);
        $page  = (int) $this->request->getParam('page', 1);

        $result = $this->openCorporatesProvider->searchCompanies($query, $jurisdiction, $limit, $page);

        return $this->relay(result: $result, sourceLabel: 'opencorporates');
    }//end openCorporatesSearch()

    /**
     * Relay a provider lookup result as a JSONResponse.
     *
     * A degraded `{ unavailable: true, cause }` descriptor is surfaced as a
     * 503 carrying `details.cause` so the consuming app's 4-state banner can
     * render (AD-23); a successful envelope is returned as-is (200).
     *
     * @param array<string,mixed> $result      The provider lookup result.
     * @param string              $sourceLabel Leaf id (for the error message).
     *
     * @return JSONResponse
     *
     * @spec exclude Private helper: maps the provider's degraded descriptor to a
     *   503-with-cause; the lookup REST contract is owned by
     *   integration-kvk-opencorporates/specs/integration-company-lookup/spec.md.
     */
    private function relay(array $result, string $sourceLabel): JSONResponse
    {
        if (($result['unavailable'] ?? false) === true) {
            return new JSONResponse(
                [
                    'error'   => sprintf('%s source is not available', $sourceLabel),
                    'details' => ['cause' => $result['cause']],
                ],
                503
            );
        }

        return new JSONResponse($result);
    }//end relay()
}//end class
