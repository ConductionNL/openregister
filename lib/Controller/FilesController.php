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
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotFoundException;
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
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


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
      * @param  string $register The register slug or identifier
      * @param  string $schema   The schema slug or identifier
      * @param  string $id       The ID of the object to retrieve files for
      * @return JSONResponse
      */
    public function index(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        try {
            // Get the raw files from the file service
            $files = $this->fileService->getFiles(object: $id);

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

    }//end index()


    /**
     * Get a specific file associated with an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   The ID of the file to retrieve
     *
     * @return JSONResponse
     */
    public function show(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object   = $this->objectService->setObject($id);

        try {
            $file = $this->fileService->getFile($object, $fileId);
            if ($file === null) {
                return new JSONResponse(['error' => 'File not found'], 404);
            }

            return new JSONResponse($this->fileService->formatFile($file));
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try

    }//end show()


    /**
     * Add a new file to an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param string $id       The ID of the object
     *
     * @return JSONResponse
     */
    public function create(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object   = $this->objectService->setObject($id);

        try {
            $data   = $this->request->getParams();
            $result = $this->fileService->addFile(objectEntity: $object, fileName: $data['name'], content: $data['content'], share: false, tags: $data['tags']);
            return new JSONResponse($this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try

    }//end create()


    /**
     * Save a file to an object (create new or update existing)
     *
     * This endpoint provides generic save functionality that automatically determines
     * whether to create a new file or update an existing one. Perfect for synchronization
     * scenarios where you want to "upsert" files.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to save the file to
     *
     * @return JSONResponse
     */
    public function save(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object   = $this->objectService->setObject($id);

        try {
            $data = $this->request->getParams();

            // Validate required parameters
            if (empty($data['name']) === true) {
                return new JSONResponse(['error' => 'File name is required'], 400);
            }

            if (empty($data['content']) === true) {
                return new JSONResponse(['error' => 'File content is required'], 400);
            }

            // Extract parameters with defaults
            $fileName = $data['name'];
            $content  = $data['content'];
            $share    = isset($data['share']) && $data['share'] === true;
            $tags     = $data['tags'] ?? [];

            // Ensure tags is an array
            if (is_string($tags) === true) {
                $tags = explode(',', $tags);
                $tags = array_map('trim', $tags);
            }

            $result = $this->fileService->saveFile(
                objectEntity: $object,
                fileName: $fileName,
                content: $content,
                share: $share,
                tags: $tags
            );

            return new JSONResponse($this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try

    }//end save()


    /**
     * Add a new file to an object via multipart form upload
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     *
     * @return JSONResponse
     */
    public function createMultipart(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object   = $this->objectService->setObject($id);

        $data = $this->request->getParams();
        try {
            // Get the uploaded file$data = $this->request->getParams();
            $uploadedFiles = [];

            // Check if multiple files have been uploaded.
            $files = $_FILES['files'] ?? null;

            // Lets see if we have files in the request.
            if (empty($files) === true) {
                throw new Exception('No files uploaded');
            }

            // Normalize single file upload to array structure
            if (isset($files['name']) === true && is_array($files['name']) === false) {
                $tags = $data['tags'] ?? '';
                if (!is_array($tags)) {
                    $tags = explode(',', $tags);
                }

                $uploadedFiles[] = [
                    'name'     => $files['name'],
                    'type'     => $files['type'],
                    'tmp_name' => $files['tmp_name'],
                    'error'    => $files['error'],
                    'size'     => $files['size'],
                    'share'    => $data['share'] === 'true',
                    'tags'     => $tags,
                ];
            } else if (isset($files['name']) === true && is_array($files['name']) === true) {
                // Loop through each file using the count of 'name'
                for ($i = 0; $i < count($files['name']); $i++) {
                    $tags = $data['tags'][$i] ?? '';
                    if (!is_array($tags)) {
                        $tags = explode(',', $tags);
                    }

                    $uploadedFiles[] = [
                        'name'     => $files['name'][$i],
                        'type'     => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i],
                        'share'    => $data['share'] === 'true',
                        'tags'     => $tags,
                    ];
                }
            }//end if

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
                // Check for upload errors first
                if (isset($file['error']) === true && $file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File upload error for ' . $file['name'] . ': ' . $this->getUploadErrorMessage($file['error']));
                }

                // Verify the temporary file exists and is readable
                if (file_exists($file['tmp_name']) === false || is_readable($file['tmp_name']) === false) {
                    throw new Exception('Temporary file not found or not readable for: ' . $file['name']);
                }

                // Read the file content with error handling
                $content = file_get_contents($file['tmp_name']);
                if ($content === false) {
                    throw new Exception('Failed to read uploaded file content for: ' . $file['name']);
                }

                // Create file
                $results[] = $this->fileService->addFile(
                    objectEntity: $this->objectService->getObject(),
                    fileName: $file['name'],
                    content: $content,
                    share: $file['share'],
                    tags: $file['tags']
                );
            }

            return new JSONResponse($this->fileService->formatFiles($results, $this->request->getParams())['results']);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try

    }//end createMultipart()


    /**
     * Update file metadata for an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   ID of the file to update
     * @param array  $tags     Optional tags to update
     *
     * @return JSONResponse
     */
    public function update(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $object   = $this->objectService->setObject($id);

        try {
            $data = $this->request->getParams();
            // Ensure tags is set to empty array if not provided
            $tags = $data['tags'] ?? [];
            // Content is optional for metadata-only updates
            $content = $data['content'] ?? null;
            $result = $this->fileService->updateFile($fileId, $content, $tags, $this->objectService->getObject());
            return new JSONResponse($this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try

    }//end update()


    /**
     * Delete a file from an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  string $register The register slug or identifier
     * @param  string $schema   The schema slug or identifier
     * @param  string $id       The ID of the object to retrieve files for
     * @param  int    $fileId   ID of the file to delete
     * @return JSONResponse
     */
    public function delete(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        try {
            $result = $this->fileService->deleteFile($fileId, $this->objectService->getObject());
            return new JSONResponse(['success' => $result]);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }

    }//end delete()


    /**
     * Publish a file associated with an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   ID of the file to publish
     *
     * @return JSONResponse
     */
    public function publish(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        try {
            $result = $this->fileService->publishFile($this->objectService->getObject(), $fileId);
            return new JSONResponse($this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try

    }//end publish()


    /**
     * Depublish a file associated with an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   ID of the file to depublish
     *
     * @return JSONResponse
     */
    public function depublish(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        try {
            $result = $this->fileService->unpublishFile($this->objectService->getObject(), $fileId);
            return new JSONResponse($this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }//end try

    }//end depublish()


    /**
     * Download a file by its ID (authenticated endpoint)
     *
     * This endpoint allows downloading a file by its file ID without needing
     * to know the object, register, or schema. This is used for authenticated
     * file access where the user must be logged in to Nextcloud.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $fileId ID of the file to download
     *
     * @return JSONResponse|\OCP\AppFramework\Http\StreamResponse
     *
     * @phpstan-param  int $fileId
     * @phpstan-return JSONResponse|\OCP\AppFramework\Http\StreamResponse
     */
    public function downloadById(int $fileId): mixed
    {
        try {
            // Get the file using the file service
            $file = $this->fileService->getFileById($fileId);
            
            if ($file === null) {
                return new JSONResponse(['error' => 'File not found'], 404);
            }
            
            // Stream the file content back to the client
            return $this->fileService->streamFile($file);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end downloadById()


    /**
     * Get a human-readable error message for PHP file upload errors
     *
     * This helper method translates PHP's file upload error codes into
     * meaningful error messages that can be displayed to users or logged.
     *
     * @param int $errorCode The PHP upload error code from $_FILES['file']['error']
     *
     * @return string Human-readable error message
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        // Map PHP upload error codes to human-readable messages
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error (code: ' . $errorCode . ')',
        };

    }//end getUploadErrorMessage()


}//end class
