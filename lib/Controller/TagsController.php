<?php
/**
 * Class ObjectsController
 *
 * Controller for managing object operations in the OpenRegister app.
 * Provides CRUD functionality for objects within registers and schemas.
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

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;
/**
 * Class ObjectsController
 */
class TagsController extends Controller
{


    public function __construct(
        $appName,
        IRequest $request,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Update file metadata for an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $objectType The type of object
     * @param string $id         The ID of the object
     * @param string $filePath   Path to the file to update
     * @param array  $tags       Optional tags to update
     *
     * @return JSONResponse
     */
    public function getAllTags(): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        return new JSONResponse($this->objectService->getAllTags());

    }//end getAllTags()


}//end class
