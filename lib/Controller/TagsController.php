<?php
/**
 * OpenRegister Tags Controller
 *
 * Controller for managing tag operations in the OpenRegister app.
 * Provides endpoints for retrieving and managing tags used for categorizing
 * objects and files.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;

/**
 * TagsController handles tag management operations
 *
 * Provides REST API endpoints for retrieving tags used throughout the system.
 * Tags are used for categorizing and organizing objects and files.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class TagsController extends Controller
{
    /**
     * TagsController constructor
     *
     * Initializes controller with required dependencies for tag operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string        $appName       Application name
     * @param IRequest      $request       HTTP request object
     * @param ObjectService $objectService Object service instance (for future tag operations)
     * @param FileService   $fileService   File service instance for tag retrieval
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly FileService $fileService,
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Get all tags available in the system
     *
     * Retrieves all tags that are visible and assignable by users.
     * Tags are used for categorizing objects and files throughout the system.
     * Returns array of tag names as strings.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, list<string>, array<never, never>>
     */
    public function getAllTags(): JSONResponse
    {
        // Retrieve all tags from file service.
        // FileService manages tags used across objects and files.
        $tags = $this->fileService->getAllTags();

        // Return tags as JSON response.
        return new JSONResponse(data: $tags);

    }//end getAllTags()
}//end class
