<?php

/**
 * OpenRegister Urn Controller
 *
 * REST endpoints for URN resolution. Surfaces three operations:
 *   - GET /api/urn/resolve?urn=...  → URL for the URN
 *   - GET /api/urn/lookup?url=...   → URN for the URL
 *   - POST /api/urn/bulk            → batch resolve { urns: [...] }
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\UrnService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * URN resolution controller.
 *
 * @psalm-suppress UnusedClass
 */
class UrnController extends Controller
{
    /**
     * Constructor.
     *
     * @param string     $appName    The application name.
     * @param IRequest   $request    The current request.
     * @param UrnService $urnService The URN resolution service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly UrnService $urnService
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Resolve a URN to its canonical API URL.
     *
     * Returns 200 `{urn, url, instance, register, schema, uuid}` on success;
     * 400 when the URN doesn't parse; 404 when the URN parses but the
     * referenced register/schema doesn't exist on this instance.
     *
     * @param string|null $urn The URN to resolve.
     *
     * @return JSONResponse JSON response with the resolution result or error.
     *
     * @NoCSRFRequired
     */
    public function resolve(?string $urn=null): JSONResponse
    {
        if ($urn === null || $urn === '') {
            return new JSONResponse(['error' => 'urn parameter is required'], Http::STATUS_BAD_REQUEST);
        }

        $parts = $this->urnService->parse($urn);
        if ($parts === null) {
            return new JSONResponse(
                ['error' => 'malformed URN; expected urn:'.UrnService::DEFAULT_NID.':<instance>:<register>:<schema>:<uuid>'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $url = $this->urnService->resolveUrl($urn);
        if ($url === null) {
            return new JSONResponse(
                ['error' => 'URN does not resolve on this instance', 'urn' => $urn, 'parts' => $parts],
                Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(
                [
                    'urn'      => $urn,
                    'url'      => $url,
                    'instance' => $parts['instance'],
                    'register' => $parts['register'],
                    'schema'   => $parts['schema'],
                    'uuid'     => $parts['uuid'],
                ]
                );
    }//end resolve()

    /**
     * Reverse: derive the URN that addresses an OpenRegister object URL.
     *
     * @param string|null $url The URL to reverse-resolve.
     *
     * @return JSONResponse JSON response with the URN or error.
     *
     * @NoCSRFRequired
     */
    public function lookup(?string $url=null): JSONResponse
    {
        if ($url === null || $url === '') {
            return new JSONResponse(['error' => 'url parameter is required'], Http::STATUS_BAD_REQUEST);
        }

        $urn = $this->urnService->urnFromUrl($url);
        if ($urn === null) {
            return new JSONResponse(
                ['error' => 'URL is not an OpenRegister object reference', 'url' => $url],
                Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(
                [
                    'url' => $url,
                    'urn' => $urn,
                ]
                );
    }//end lookup()

    /**
     * Batch URN resolution.
     *
     * Accepts JSON body `{urns: ["urn:nl-or:...", ...]}`. Returns a map
     * of `urn → url-or-null`, preserving the input list order via the
     * map keys.
     *
     * @param array|null $urns The list of URNs to resolve.
     *
     * @return JSONResponse JSON response with the bulk resolution result.
     *
     * @NoCSRFRequired
     */
    public function bulk(?array $urns=null): JSONResponse
    {
        if (is_array($urns) === false || count($urns) === 0) {
            return new JSONResponse(['error' => 'urns array is required'], Http::STATUS_BAD_REQUEST);
        }

        $resolved = $this->urnService->resolveBulk($urns);

        return new JSONResponse(
                [
                    'count'    => count($resolved),
                    'resolved' => $resolved,
                ]
                );
    }//end bulk()
}//end class
