<?php

/**
 * PersonLookupController — read-only person-lookup endpoint backed by the
 * BRP HaalCentraal integration leaf (external, OpenConnector-routed).
 *
 * Surface:
 *   - GET /api/integrations/brp/person?bsn=   — BRP person lookup by BSN
 *
 * Like the KvK / OpenCorporates company-lookup leaves, BRP is external: there
 * is no local NC app to gate on. The OpenConnector app carries the
 * `brp-haalcentraal` source + OAuth2 client_credentials secret + PKIoverheid
 * mutual-TLS client certificate; when one (or its source) is missing the
 * provider returns the 4-state cause (`openconnector-down` /
 * `openconnector-source-missing` / `provider-auth` / `upstream-service-down`)
 * which this controller relays as a 503 with `details.cause` so a consuming
 * app renders the right banner (AD-23).
 *
 * The endpoint round-trips the raw upstream HAL+JSON person object — the
 * BRP→domain field mapping (naam / geboorte / verblijfplaats normalisation,
 * geslacht-code mapping) and the BSN validation (elfproef) + masking stay in
 * the consuming app (pipelinq's `HaalCentraalClient` + `BsnValidationService`).
 *
 * Privacy: the BSN arrives as a query parameter on this internal,
 * authenticated endpoint and is forwarded to the provider in the request body
 * only; it is never logged here.
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

use OCA\OpenRegister\Service\Integration\Providers\BrpPersoonProvider;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only person-lookup controller (BRP HaalCentraal leaf).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class PersonLookupController extends Controller
{
    /**
     * Constructor.
     *
     * @param string             $appName     App id.
     * @param IRequest           $request     HTTP request.
     * @param BrpPersoonProvider $brpProvider BRP person lookup leaf.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly BrpPersoonProvider $brpProvider,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Look up a single person by Burgerservicenummer (BSN).
     *
     * Query param: `bsn` (9-digit Burgerservicenummer, required). The BSN is
     * forwarded to the provider (and, downstream, the HaalCentraal request
     * body) — it is never logged. This endpoint does NOT validate the elfproef
     * checksum; the consuming app does that before/after this call.
     *
     * @return JSONResponse `{ results, total, meta }` on success (results is
     *                      the raw HaalCentraal person object list — 0 or 1
     *                      entries; `meta` is the Wet-BRP audit metadata
     *                      `{ correlationId, durationMs, status }` the
     *                      consuming app persists into its `brpLookupVerzoek`
     *                      record), or a 503 with `details.cause` when the
     *                      `brp-haalcentraal` source is unconfigured / BRP is
     *                      down (AD-23).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/integration-brp-audit-metadata/specs/integration-person-lookup/spec.md
     */
    public function brpPerson(): JSONResponse
    {
        $bsn = trim((string) $this->request->getParam('bsn', ''));
        if ($bsn === '') {
            return new JSONResponse(['error' => 'bsn is required'], 400);
        }

        $result = $this->brpProvider->lookupByBsn($bsn);

        if (($result['unavailable'] ?? false) === true) {
            return new JSONResponse(
                [
                    'error'   => 'brp-haalcentraal source is not available',
                    'details' => ['cause' => $result['cause']],
                ],
                503
            );
        }

        return new JSONResponse($result);
    }//end brpPerson()
}//end class
