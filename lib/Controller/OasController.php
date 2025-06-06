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
     * @var OasService
     */
    private readonly OasService $oasService;


    /**
     * OasController constructor.
     *
     * @param string     $appName
     * @param IRequest   $request
     * @param OasService $oasService
     */
    public function __construct(
        string $appName,
        IRequest $request,
        OasService $oasService
    ) {
        parent::__construct($appName, $request);
        $this->oasService = $oasService;

    }//end __construct()


    /**
     * Generate OAS for all registers
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return JSONResponse
     */
    public function generateAll(): JSONResponse
    {
        try {
            // Generate OAS for all registers.
            $oasData = $this->oasService->createOas();
            return new JSONResponse($oasData);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end generateAll()


    /**
     * Generate OAS for a specific register
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @param string $register The register slug or identifier
     *
     * @return JSONResponse
     */
    public function generate(string $id): JSONResponse
    {
        try {
            // Generate OAS for the specified register.
            $oasData = $this->oasService->createOas($id);
            return new JSONResponse($oasData);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end generate()


}//end class
