<?php

declare(strict_types=1);

/*
 * FilesController
 *
 * Controller for file operations in the OpenRegister application.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * FilesController
 *
 * Handles file operations for objects in registers
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */
class FilesController extends Controller
{

    /**
     * File service for handling file operations
     *
     * @var FileService
     */
    private readonly FileService $fileService;

    /**
     * Object service for handling object operations
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;


    /**
     * Constructor
     *
     * @param string        $appName       Application name
     * @param IRequest      $request       HTTP request
     * @param FileService   $fileService   File service
     * @param ObjectService $objectService Object service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        FileService $fileService,
        ObjectService $objectService
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->fileService   = $fileService;
        $this->objectService = $objectService;

    }//end __construct()


     /**
      * Get all files associated with a specific object
      *
      * @param string $register The register slug or identifier
      * @param string $schema   The schema slug or identifier
      * @param string $id       The ID of the object to retrieve files for
      *
      * @return JSONResponse
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
            // Get the raw files from the file service.
            $files = $this->fileService->getFiles(object: $id);

            // Format the files with pagination using request parameters.
            $formattedFiles = $this->fileService->formatFiles($files, $this->request->getParams());

            return new JSONResponse(data: $formattedFiles);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['error' => 'Files folder not found'], statusCode: 404);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try

    }//end index()


    /**
     * Get a specific file associated with an object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   The ID of the file to retrieve
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
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
        $this->objectService->setObject($id);
        $object = $this->objectService->getObject();

        try {
            $file = $this->fileService->getFile($object, $fileId);
            if ($file === null) {
                return new JSONResponse(data: ['error' => 'File not found'], statusCode: 404);
            }

            return new JSONResponse(data: $this->fileService->formatFile($file));
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try

    }//end show()


    /**
     * Add a new file to an object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $this->objectService->setObject($id);
        $object = $this->objectService->getObject();

        try {
            $data = $this->request->getParams();
            if (empty($data['name']) === true) {
                return new JSONResponse(data: ['error' => 'File name is required'], statusCode: 400);
            }

            if (array_key_exists('content', $data) === false) {
                return new JSONResponse(data: ['error' => 'File content is required'], statusCode: 400);
            }

            $share = $this->parseBool($data['share'] ?? false);
            $tags  = $this->normalizeTags($data['tags'] ?? []);

            $result = $this->fileService->addFile(
                objectEntity: $object,
                fileName: $data['name'],
                content: (string) $data['content'],
                share: $share,
                tags: $tags
            );
            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try

    }//end create()


    /**
     * Save a file to an object (create new or update existing)
     *
     * This endpoint provides generic save functionality that automatically determines
     * whether to create a new file or update an existing one. Perfect for synchronization
     * scenarios where you want to "upsert" files.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to save the file to
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function save(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $this->objectService->setObject($id);
        $object = $this->objectService->getObject();

        try {
            $data = $this->request->getParams();

            // Validate required parameters.
            if (empty($data['name']) === true) {
                return new JSONResponse(data: ['error' => 'File name is required'], statusCode: 400);
            }

            if (array_key_exists('content', $data) === false || empty($data['content']) === true) {
                return new JSONResponse(data: ['error' => 'File content is required'], statusCode: 400);
            }

            // Extract parameters with defaults.
            $fileName = (string) $data['name'];
            $content  = (string) $data['content'];
            $share    = isset($data['share']) && $data['share'] === true;
            $tags     = $data['tags'] ?? [];

            // Ensure tags is an array.
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

            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try

    }//end save()


    /**
     * Add a new file to an object via multipart form upload
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function createMultipart(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $schema   = $this->objectService->setSchema($schema);
        $register = $this->objectService->setRegister($register);
        $this->objectService->setObject($id);
        $object = $this->objectService->getObject();

        $data = $this->request->getParams();
        try {
            // Get the uploaded file.
            $uploadedFiles = [];

            // Check if multiple files have been uploaded.
            $files = $_FILES['files'] ?? null;

            // Lets see if we have files in the request.
            if (empty($files) === true) {
                throw new Exception('No files uploaded');
            }

            // Normalize single file upload to array structure.
            if (isset($files['name']) === true && is_array($files['name']) === false) {
                $tags = $data['tags'] ?? '';
                if (is_array($tags) === false) {
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
                // Loop through each file using the count of 'name'.
                for ($i = 0; $i < count($files['name']); $i++) {
                    $tags = $data['tags'][$i] ?? '';
                    if (is_array($tags) === false) {
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
                // Check for upload errors first.
                if (isset($file['error']) === true && $file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File upload error for '.$file['name'].': '.$this->getUploadErrorMessage($file['error']));
                }

                // Verify the temporary file exists and is readable.
                if (file_exists($file['tmp_name']) === false || is_readable($file['tmp_name']) === false) {
                    throw new Exception('Temporary file not found or not readable for: '.$file['name']);
                }

                // Read the file content with error handling.
                $content = file_get_contents($file['tmp_name']);
                if ($content === false) {
                    throw new Exception('Failed to read uploaded file content for: '.$file['name']);
                }

                // Create file.
                $results[] = $this->fileService->addFile(
                    objectEntity: $object,
                    fileName: $file['name'],
                    content: $content,
                    share: $file['share'],
                    tags: $file['tags']
                );
            }//end foreach

            return new JSONResponse(data: $this->fileService->formatFiles(files: $results, requestParams: $this->request->getParams())['results']);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try

    }//end createMultipart()


    /**
     * Update file metadata for an object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   ID of the file to update
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
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
            // Ensure tags is set to empty array if not provided.
            $tags = $data['tags'] ?? [];
            // Content is optional for metadata-only updates.
            $content = $data['content'] ?? null;
            $result  = $this->fileService->updateFile($fileId, $content, $tags, $this->objectService->getObject());
            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try

    }//end update()


    /**
     * Delete a file from an object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   ID of the file to delete
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
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
            return new JSONResponse(data: ['success' => $result]);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }

    }//end delete()


    /**
     * Publish a file associated with an object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   ID of the file to publish
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
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
            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try

    }//end publish()


    /**
     * Depublish a file associated with an object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   ID of the file to depublish
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
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
            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try

    }//end depublish()


    /**
     * Download a file by its ID (authenticated endpoint)
     *
     * This endpoint allows downloading a file by its file ID without needing
     * to know the object, register, or schema. This is used for authenticated
     * file access where the user must be logged in to Nextcloud.
     *
     * @param int $fileId ID of the file to download
     *
     * @return JSONResponse|\OCP\AppFramework\Http\StreamResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-param  int $fileId
     * @phpstan-return JSONResponse|\OCP\AppFramework\Http\StreamResponse
     */
    public function downloadById(int $fileId): mixed
    {
        try {
            // Get the file using the file service.
            $file = $this->fileService->getFileById($fileId);

            if ($file === null) {
                return new JSONResponse(data: ['error' => 'File not found'], statusCode: 404);
            }

            // Stream the file content back to the client.
            return $this->fileService->streamFile($file);
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['error' => 'File not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
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
        // Map PHP upload error codes to human-readable messages.
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error (code: '.$errorCode.')',
        };

    }//end getUploadErrorMessage()


    /**
     * Parse a value to boolean
     *
     * Handles various input types (string, int, bool) and converts them
     * to boolean values. Supports common string representations like
     * 'true', 'false', '1', '0', 'yes', 'no'.
     *
     * @param mixed $value The value to parse
     *
     * @return bool The parsed boolean value
     */
    private function parseBool(mixed $value): bool
    {
        // If already boolean, return as-is.
        if (is_bool($value) === true) {
            return $value;
        }

        // Handle string values.
        if (is_string($value) === true) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', '1', 'on', 'yes'], true);
        }

        // Handle numeric values.
        if (is_numeric($value) === true) {
            return (bool) $value;
        }

        // Fallback to false for other types.
        return false;

    }//end parseBool()


    /**
     * Normalize tags input to an array
     *
     * Handles both string (comma-separated) and array inputs for tags.
     * Trims whitespace from each tag.
     *
     * @param mixed $tags The tags input (string or array)
     *
     * @return array The normalized tags array
     */
    private function normalizeTags(mixed $tags): array
    {
        // If already an array, just trim values.
        if (is_array($tags) === true) {
            return array_map('trim', $tags);
        }

        // If string, split by comma and trim.
        if (is_string($tags) === true) {
            $tags = explode(',', $tags);
            return array_map('trim', $tags);
        }

        // Default to empty array.
        return [];

    }//end normalizeTags()


    /**
     * Render the Files page
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(appName: 'openregister', templateName: 'index', params: []);

    }//end page()


}//end class
