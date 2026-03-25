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
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\AppFramework\Db\DoesNotExistException;
use Exception;

/**
 * TagsController handles tag management operations
 *
 * Provides REST API endpoints for retrieving tags used throughout the system
 * and for managing tags on individual objects.
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
     * @param string         $appName        Application name
     * @param IRequest       $request        HTTP request object
     * @param ObjectService  $objectService  Object service instance
     * @param FileService    $fileService    File service instance for tag retrieval
     * @param TaggingHandler $taggingHandler Tagging handler for object-level tags
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly FileService $fileService,
        private readonly TaggingHandler $taggingHandler,
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get all tags available in the system
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with all tags
     *
     * @psalm-return JSONResponse<200, list<string>, array<never, never>>
     */
    public function getAllTags(): JSONResponse
    {
        $tags = $this->fileService->getAllTags();

        return new JSONResponse(data: $tags);
    }//end getAllTags()

    /**
     * Get tags for a specific object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The object ID
     *
     * @return JSONResponse JSON response with the object's tags
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        try {
            $this->objectService->setSchema($schema);
            $this->objectService->setRegister($register);
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

            $tags = $this->taggingHandler->getObjectTags($object->getUuid());

            return new JSONResponse(data: $tags);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }
    }//end index()

    /**
     * Add a tag to an object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The object ID
     *
     * @return JSONResponse JSON response with the updated tags
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function add(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        try {
            $this->objectService->setSchema($schema);
            $this->objectService->setRegister($register);
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

            $data = $this->request->getParams();

            if (empty($data['tag']) === true) {
                return new JSONResponse(
                    data: ['error' => 'Tag name is required'],
                    statusCode: 400
                );
            }

            $this->taggingHandler->addObjectTag($object->getUuid(), $data['tag']);
            $tags = $this->taggingHandler->getObjectTags($object->getUuid());

            return new JSONResponse(data: $tags, statusCode: 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try
    }//end add()

    /**
     * Remove a tag from an object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The object ID
     * @param string $tag      The tag name to remove
     *
     * @return JSONResponse JSON response with the updated tags
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function remove(
        string $register,
        string $schema,
        string $id,
        string $tag
    ): JSONResponse {
        try {
            $this->objectService->setSchema($schema);
            $this->objectService->setRegister($register);
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

            $this->taggingHandler->removeObjectTag($object->getUuid(), $tag);
            $tags = $this->taggingHandler->getObjectTags($object->getUuid());

            return new JSONResponse(data: $tags);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }
    }//end remove()
}//end class
