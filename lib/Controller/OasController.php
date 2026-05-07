<?php

/**
 * Class OasController
 *
 * Controller for generating OpenAPI Specifications (OAS) for registers in the OpenRegister app.
 * Provides endpoints to generate OAS for a single register or all registers.
 *
 * Supports `?validate=true` (attaches an `x-validation-summary` extension to the
 * response) and `?strict=true` (returns HTTP 422 with the validation report when
 * any validation error is detected, instead of auto-correcting).
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-oas-generation-2026-05-01/tasks.md#task-1
 * @spec openspec/changes/retrofit-oas-generation-2026-05-01/tasks.md#task-2
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\OasService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;

/**
 * OasController class.
 *
 * Controller for generating OpenAPI Specifications (OAS) for registers.
 *
 * @psalm-suppress UnusedClass - This controller is registered via routes.php and used by Nextcloud's routing system
 */
class OasController extends Controller
{

    /**
     * OAS service instance
     *
     * @var OasService
     */
    private readonly OasService $oasService;

    /**
     * OasController constructor
     *
     * @param string     $appName    Application name
     * @param IRequest   $request    Request object
     * @param OasService $oasService OAS service instance
     */
    public function __construct(
        string $appName,
        IRequest $request,
        OasService $oasService
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->oasService = $oasService;
    }//end __construct()

    /**
     * Generate OAS for all registers
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @return JSONResponse
     *
     * @psalm-return JSONResponse<200|422|500, array<string, mixed>, array<never, never>>
     *
     * @spec openspec/changes/retrofit-oas-generation-2026-05-01/tasks.md#task-1
     */
    public function generateAll(): JSONResponse
    {
        return $this->generateInternal(registerId: null);
    }//end generateAll()

    /**
     * Generate OAS for a specific register
     *
     * @param string $id The register slug or identifier.
     *
     * @return JSONResponse OAS specification JSON response.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @psalm-return JSONResponse<200|422|500, array<string, mixed>, array<never, never>>
     *
     * @spec openspec/changes/retrofit-oas-generation-2026-05-01/tasks.md#task-2
     */
    public function generate(string $id): JSONResponse
    {
        return $this->generateInternal(registerId: $id);
    }//end generate()

    /**
     * Shared generation path for both single-register and all-registers routes.
     *
     * Honours the `?strict=true` and `?validate=true` query parameters from
     * `$this->request`.
     *
     * @param string|null $registerId Register slug/identifier or null for all registers.
     *
     * @return JSONResponse
     */
    private function generateInternal(?string $registerId): JSONResponse
    {
        $strict      = $this->boolQueryParam(name: 'strict');
        $emitSummary = $this->boolQueryParam(name: 'validate');

        try {
            $oasData = $this->oasService->createOas($registerId, $strict);
        } catch (OasValidationException $e) {
            // Strict mode rejected the document — return the report so the
            // caller can see exactly which checks failed.
            return new JSONResponse(
                data: [
                    'error'   => $e->getMessage(),
                    'summary' => $e->getReport()->toSummary(),
                ],
                statusCode: 422,
            );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

        if ($emitSummary === true) {
            $oasData['x-validation-summary'] = $this->oasService->getLastValidationReport()->toSummary();
        }

        return new JSONResponse(data: $oasData);
    }//end generateInternal()

    /**
     * Read a boolean query parameter. Accepts "true", "1", "yes" (case-insensitive)
     * as truthy; everything else is false. Missing parameters are false.
     *
     * @param string $name Query parameter name.
     *
     * @return bool
     */
    private function boolQueryParam(string $name): bool
    {
        $raw = $this->request->getParam($name);
        if ($raw === null || $raw === '') {
            return false;
        }

        $normalized = strtolower((string) $raw);
        return in_array($normalized, ['true', '1', 'yes', 'on'], true);
    }//end boolQueryParam()
}//end class
