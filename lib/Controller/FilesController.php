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
class FilesController extends Controller
{
    public function __construct(
		$appName,
		IRequest $request,
        private readonly ObjectService $objectService,
        private readonly FileService $fileService
	)
    {
        parent::__construct($appName, $request);
    }


    /**
     * Returns the template of the main app's page
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse The rendered template response
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openconnector',
            'index',
            []
        );

    }//end page()


     /**
     * Get all files associated with a specific object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to retrieve files for
     * @return JSONResponse
     */
    public function index(
        string $register,
        string $schema,
        string $id): JSONResponse
    {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object = $this->objectService->setObject($id);

        try {
            // Get the raw files from the file service
            $files = $this->fileService->getFiles($id);

            // Format the files with pagination using request parameters
            $formattedFiles = $this->fileService->formatFiles($files, $this->request->getParams());

            return new JSONResponse($formattedFiles);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Files folder not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end indexFiles()

    /**
     * Get a specific file associated with an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to retrieve files for
     * @param string $filePath Path to the file to update
     *
     * @return JSONResponse
     */
    public function show(
        string $register,
        string $schema,
        string $id,
        string $filePath): JSONResponse
    {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object = $this->objectService->setObject($id);

        try {
            $file = $this->fileService->getFile($object, $filePath);
            return new JSONResponse($file);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try
    }//end showFile()

    /**
     * Add a new file to an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to retrieve files for
     * @param string $id The ID of the object
     *
     * @return JSONResponse
     */
    public function create(
        string $register,
        string $schema,
        string $id): JSONResponse
    {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object = $this->objectService->setObject($id);

        try {
            $data = $this->request->getParams();
            $result = $this->fileService->addFile($object, $data['name'], $data['content'], false, $data['tags']);
            return new JSONResponse($result);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try
    }//end createFile()

    /**
     * Add a new file to an object via multipart form upload
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to retrieve files for
     *
     * @return JSONResponse
     */
    public function createMultipart(
        string $register,
        string $schema,
        string $id): JSONResponse
    {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object = $this->objectService->setObject($id);

		$data = $this->request->getParams();
        try {
            // Get the uploaded file$data = $this->request->getParams();
            $uploadedFiles = [];

            // Check if multiple files have been uploaded.
            $files = $_FILES['files'] ?? null;

            if (empty($files) === false) {
                // Loop through each file using the count of 'name'
                for ($i = 0; $i < count($files['name']); $i++) {
                    $uploadedFiles[] = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i],
                        'share' => $data['share'] === 'true',
						'tags' => explode(',',$data['tags'][$i])
                    ];
                }
            }

            // Get the uploaded file from the request if a single file hase been uploaded.
            $uploadedFile = $this->request->getUploadedFile(key: 'file');
            if (empty($uploadedFile) === false) {
                $uploadedFiles[] = $uploadedFile;
            }

            if (empty($uploadedFiles) === true) {
                throw new Exception('No file(s) uploaded');
            }

            // Create file using the uploaded file's content and name.
            $results = [];
            foreach ($uploadedFiles as $file) {
                // Create file
                $results[] = $this->fileService->addFile(
                    $object,
                    $file['name'],
                    file_get_contents($file['tmp_name']),
                    $file['share'],
					$file['tags']
                );
            }

            return new JSONResponse($results);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try
    }//end createFileMultipart()

    /**
     * Update file metadata for an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to retrieve files for
	 * @param string $filePath Path to the file to update
	 * @param array $tags Optional tags to update
     *
     * @return JSONResponse
     */
    public function update(
        string $register,
        string $schema,
        string $id,
        string $filePath): JSONResponse
    {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object = $this->objectService->setObject($id);

        try {
            $data = $this->request->getParams();
            // Ensure tags is set to empty array if not provided
            $tags = $data['tags'] ?? [];
            $result = $this->fileService($filePath, $data['content'], $tags);
            return new JSONResponse($result);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try
    }//end updateFile()

    /**
     * Delete a file from an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to retrieve files for
	 * @param string $filePath Path to the file to delete
     * @return JSONResponse
     */
    public function delete(
        string $register,
        string $schema,
        string $id,
        string $filePath): JSONResponse
    {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object = $this->objectService->setObject($id);

        try {
            $result = $this->fileService->deleteFile($filePath);
            return new JSONResponse($result);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }
    }//end deleteFile()

    /**
     * Publish a file associated with an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to retrieve files for
     * @param string $filePath Path to the file to publish
     *
     * @return JSONResponse
     */
    public function publish(
        string $register,
        string $schema,
        string $id,
        string $filePath): JSONResponse
    {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object = $this->objectService->setObject($id);

        try {
            $result = $this->fileService->publishFile($object, $filePath);
            return new JSONResponse($result);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try
    }//end publishFile()

    /**
     * Depublish a file associated with an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to retrieve files for
     * @param string $filePath Path to the file to depublish
     *
     * @return JSONResponse
     */
    public function depublish(
        string $register,
        string $schema,
        string $id,
        string $filePath): JSONResponse
    {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object = $this->objectService->setObject($id);

        try {
            $result = $this->fileService->unpublishFile($object, $filePath);
            return new JSONResponse($result);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try
    }//end depublishFile()
}//end class
