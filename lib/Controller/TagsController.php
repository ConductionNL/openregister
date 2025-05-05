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
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;
/**
 * Class ObjectsController
 */
class TagsController extends Controller
{

    /**
     * TagsController constructor.
     *
     * @param string $appName
     * @param IRequest $request
     * @param ObjectService $objectService
     * @param FileService $fileService
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly FileService $fileService,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Get all tags available in the system (visible and assignable by users)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function getAllTags(): JSONResponse
    {
        // Use the FileService to fetch all tags
        return new JSONResponse($this->fileService->getAllTags());
    }//end getAllTags()


}//end class
