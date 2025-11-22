<?php
/**
 * Class OasController
 *
 * Controller for generating OpenAPI Specifications (OAS) for registers in the OpenRegister app.
 * Provides endpoints to generate OAS for a single register or all registers.
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
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\OasService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;

/**
 * Class OasController
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
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function generateAll(): JSONResponse
    {
        try {
            // Generate OAS for all registers.
            $oasData = $this->oasService->createOas();
            return new JSONResponse(data: $oasData);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end generateAll()


    /**
     * Generate OAS for a specific register
     *
     * @param string $id The register slug or identifier
     *
     * @return JSONResponse OAS data for the register
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function generate(string $id): JSONResponse
    {
        try {
            // Generate OAS for the specified register.
            $oasData = $this->oasService->createOas($id);
            return new JSONResponse(data: $oasData);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end generate()


}//end class
