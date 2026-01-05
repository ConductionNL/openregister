<?php

/**
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

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * FilesController handles file operations for objects in registers
 *
 * Provides REST API endpoints for managing files associated with objects.
 * Supports file upload, download, listing, and deletion operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class FilesController extends Controller
{

    /**
     * File service for handling file operations
     *
     * Handles file storage, retrieval, and management operations.
     *
     * @var FileService File service instance
     */
    private readonly FileService $fileService;

    /**
     * Object service for handling object operations
     *
     * Used to validate object existence and permissions.
     *
     * @var ObjectService Object service instance
     */
    private readonly ObjectService $objectService;

    /**
     * Constructor
     *
     * Initializes controller with required dependencies for file operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string        $appName       Application name
     * @param IRequest      $request       HTTP request object
     * @param FileService   $fileService   File service for file operations
     * @param ObjectService $objectService Object service for object validation
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        FileService $fileService,
        ObjectService $objectService
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

        // Store dependencies for use in controller methods.
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404|500,
     *     array{error?: string, results?: array<int, array<string, mixed>>,
     *     total?: int, page?: int, pages?: int, limit?: int, offset?: int},
     *     array<never, never>>
     */
    public function index(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Note: $register and $schema are route parameters for API consistency.
        // They are part of the URL structure (/api/objects/{register}/{schema}/{id}/files)
        // But only $id is used to fetch files.
        // Reference them to satisfy static analysis.
        $routeParams = ['register' => $register, 'schema' => $schema];
        unset($routeParams);

        try {
            // Get the raw files from the file service.
            $files = $this->fileService->getFiles(object: $id);

            // Format the files with pagination using request parameters.
            $formattedFiles = $this->fileService->formatFiles(files: $files, requestParams: $this->request->getParams());

            return new JSONResponse(data: $formattedFiles);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Object not found'],
                statusCode: 404
            );
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['error' => 'Files folder not found'], statusCode: 404);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end index()

    /**
     * Get a specific file associated with an object
     *
     * Retrieves file details and metadata for a specific file ID.
     * Validates that the file belongs to the specified object.
     *
     * @param string $register The register slug or identifier (route parameter, used for validation)
     * @param string $schema   The schema slug or identifier (route parameter, used for validation)
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   The ID of the file to retrieve
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing file details
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array<string, mixed>, array<never, never>>
     */
    public function show(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if they are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            $file = $this->fileService->getFile(object: $object, file: $fileId);

            if ($file === null) {
                return new JSONResponse(
                    data: ['error' => 'File not found'],
                    statusCode: 404
                );
            }

            return new JSONResponse(data: $this->fileService->formatFile($file));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array{error?: mixed|string, labels?: list<string>,...}, array<never, never>>
     */
    public function create(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            $data = $this->request->getParams();

            // Support both 'name' and 'filename' for compatibility.
            $fileName = $data['name'] ?? $data['filename'] ?? null;

            if (empty($fileName) === true) {
                return new JSONResponse(
                    data: ['error' => 'File name is required (use "name" or "filename")'],
                    statusCode: 400
                );
            }

            if (array_key_exists('content', $data) === false) {
                return new JSONResponse(
                    data: ['error' => 'File content is required'],
                    statusCode: 400
                );
            }

            $share = $this->parseBool($data['share'] ?? false);
            $tags  = $this->normalizeTags($data['tags'] ?? []);

            $result = $this->fileService->addFile(
                objectEntity: $object,
                fileName: $fileName,
                content: (string) $data['content'],
                share: $share,
                tags: $tags
            );
            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array{error?: mixed|string, labels?: list<string>,...}, array<never, never>>
     */
    public function save(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            $data = $this->request->getParams();

            // Validate required parameters.
            if (empty($data['name']) === true) {
                return new JSONResponse(
                    data: ['error' => 'File name is required'],
                    statusCode: 400
                );
            }

            $contentExists = array_key_exists('content', $data) === false;
            $contentEmpty  = empty($data['content']) === true;

            if ($contentExists === true || $contentEmpty === true) {
                return new JSONResponse(
                    data: ['error' => 'File content is required'],
                    statusCode: 400
                );
            }

            // Extract parameters with defaults. Support both 'name' and 'filename' for compatibility.
            $fileName = $data['name'] ?? $data['filename'] ?? null;

            if (empty($fileName) === true) {
                return new JSONResponse(
                    data: ['error' => 'File name is required (use "name" or "filename")'],
                    statusCode: 400
                );
            }

            $content = (string) $data['content'];

            $share = false;
            if (isset($data['share']) === true && $data['share'] === true) {
                $share = true;
            }

            $tags = $data['tags'] ?? [];

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
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array<'error'|int, array<string, mixed>|string>, array<never, never>>
     */
    public function createMultipart(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        try {
            // Validate object exists.
            $object = $this->validateAndGetObject(
                register: $register,
                schema: $schema,
                id: $id
            );

            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            // Extract and validate uploaded files.
            $uploadedFiles = $this->extractUploadedFiles();

            if (empty($uploadedFiles) === true) {
                throw new Exception('No file(s) uploaded');
            }

            // Process all uploaded files.
            $results = $this->processUploadedFiles(
                object: $object,
                uploadedFiles: $uploadedFiles
            );

            // Format and return results.
            $formattedFiles = $this->fileService->formatFiles(
                files: $results,
                requestParams: $this->request->getParams()
            );

            return new JSONResponse($formattedFiles['results']);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end createMultipart()

    /**
     * Validate and retrieve object entity.
     *
     * @param string $register Register identifier
     * @param string $schema   Schema identifier
     * @param string $id       Object ID
     *
     * @return ObjectEntity|null Object entity or null if not found
     */
    private function validateAndGetObject(string $register, string $schema, string $id): ?ObjectEntity
    {
        // Set the schema and register to the object service (forces a check if they are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end validateAndGetObject()

    /**
     * Extract uploaded files from request.
     *
     * @return array<int, array{name: string, type: string, tmp_name: string,
     *     error: int, size: int, share: bool, tags: array<int, string>}>
     *     Normalized uploaded files array
     *
     * @throws Exception If no files are uploaded
     */
    private function extractUploadedFiles(): array
    {
        $uploadedFiles = [];
        $data          = $this->request->getParams();

        // Check for multipart file uploads.
        $files = $_FILES['files'] ?? [];

        if (empty($files) === false) {
            $uploadedFiles = $this->normalizeMultipartFiles(files: $files, data: $data);
        }

        // Check for single file upload.
        $uploadedFile = $this->request->getUploadedFile('file');

        if (empty($uploadedFile) === false) {
            $uploadedFiles[] = $uploadedFile;
        }

        if (empty($uploadedFiles) === true) {
            throw new Exception('No files uploaded');
        }

        return $uploadedFiles;
    }//end extractUploadedFiles()

    /**
     * Normalize $_FILES array to consistent format for single or multiple files.
     *
     * @param array<string, array<int, string>|string|int> $files Files from $_FILES
     * @param array                                        $data  Request parameters
     *
     * @return array<int,
     *     array{name: string, type: string, tmp_name: string, error: int,
     *     size: int, share: bool, tags: array<int, string>}>
     *     Normalized files array
     */
    private function normalizeMultipartFiles(array $files, array $data): array
    {
        $uploadedFiles = [];
        $fileName      = $files['name'] ?? null;

        // Single file upload.
        if ($fileName !== null && is_array($fileName) === false) {
            $uploadedFiles[] = $this->normalizeSingleFile(files: $files, data: $data);
            return $uploadedFiles;
        }

        // Multiple file upload.
        if ($fileName !== null && is_array($fileName) === true) {
            $uploadedFiles = $this->normalizeMultipleFiles(files: $files, data: $data, fileNames: $fileName);
        }

        return $uploadedFiles;
    }//end normalizeMultipartFiles()

    /**
     * Normalize single file upload.
     *
     * @param array<string, array<int, string>|string|int> $files Files from $_FILES
     * @param array                                        $data  Request parameters
     *
     * @return array Normalized file data
     */
    private function normalizeSingleFile(array $files, array $data): array
    {
        $tags = $data['tags'] ?? '';
        if (is_array($tags) === false) {
            $tags = explode(',', $tags);
        }

        return [
            'name'     => $files['name'] ?? '',
            'type'     => $files['type'] ?? '',
            'tmp_name' => $files['tmp_name'] ?? '',
            'error'    => $files['error'] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'] ?? 0,
            'share'    => $data['share'] === 'true',
            'tags'     => $tags,
        ];
    }//end normalizeSingleFile()

    /**
     * Normalize multiple file uploads.
     *
     * @param array<string, array<int, string>|string|int> $files     Files from $_FILES
     * @param array                                        $data      Request parameters
     * @param array<int, string>                           $fileNames Array of file names
     *
     * @return array<int,
     *     array{name: string, type: string, tmp_name: string, error: int,
     *     size: int, share: bool, tags: array<int, string>}>
     *     Normalized files array
     */
    private function normalizeMultipleFiles(array $files, array $data, array $fileNames): array
    {
        $uploadedFiles = [];
        $fileCount     = count($fileNames);

        for ($i = 0; $i < $fileCount; $i++) {
            $tags = $data['tags'][$i] ?? '';
            if (is_array($tags) === false) {
                $tags = explode(',', $tags);
            }

            // Extract file arrays safely.
            if (is_array($files['type'] ?? null) === true) {
                $typeArray = $files['type'];
            } else {
                $typeArray = [];
            }

            if (is_array($files['tmp_name'] ?? null) === true) {
                $tmpNameArray = $files['tmp_name'];
            } else {
                $tmpNameArray = [];
            }

            $errorValue = $files['error'] ?? null;
            if (is_array($errorValue) === true) {
                $errorArray = $errorValue;
            } else {
                $errorArray = [];
            }

            if (is_int($errorValue) === true) {
                $errorScalar = $errorValue;
            } else {
                $errorScalar = null;
            }

            $sizeValue = $files['size'] ?? null;
            if (is_array($sizeValue) === true) {
                $sizeArray = $sizeValue;
            } else {
                $sizeArray = [];
            }

            if (is_int($sizeValue) === true) {
                $sizeScalar = $sizeValue;
            } else {
                $sizeScalar = null;
            }

            $uploadedFiles[] = [
                'name'     => $fileNames[$i] ?? '',
                'type'     => $typeArray[$i] ?? '',
                'tmp_name' => $tmpNameArray[$i] ?? '',
                'error'    => $errorArray[$i] ?? $errorScalar ?? UPLOAD_ERR_NO_FILE,
                'size'     => $sizeArray[$i] ?? $sizeScalar ?? 0,
                'share'    => $data['share'] === 'true',
                'tags'     => $tags,
            ];
        }//end for

        return $uploadedFiles;
    }//end normalizeMultipleFiles()

    /**
     * Process all uploaded files and create file entities.
     *
     * @param ObjectEntity $object        Object entity to attach files to
     * @param array        $uploadedFiles Normalized uploaded files array
     *
     * @return array<int, mixed> Array of created file entities
     *
     * @throws Exception If file validation or processing fails
     */
    private function processUploadedFiles(ObjectEntity $object, array $uploadedFiles): array
    {
        $results = [];

        foreach ($uploadedFiles as $file) {
            // Validate file upload.
            $this->validateUploadedFile(file: $file);

            // Read file content.
            $content = file_get_contents($file['tmp_name']);

            if ($content === false) {
                throw new Exception(
                    'Failed to read uploaded file content for: '.$file['name']
                );
            }

            // Create file entity.
            $results[] = $this->fileService->addFile(
                objectEntity: $object,
                fileName: $file['name'],
                content: $content,
                share: $file['share'],
                tags: $file['tags']
            );
        }//end foreach

        return $results;
    }//end processUploadedFiles()

    /**
     * Validate uploaded file for errors and readability.
     *
     * @param array{name: string, tmp_name: string, error: int} $file File data
     *
     * @return void
     *
     * @throws Exception If file validation fails
     */
    private function validateUploadedFile(array $file): void
    {
        // Check for upload errors.
        $fileError = $file['error'] ?? null;

        if ($fileError !== null && ($fileError !== UPLOAD_ERR_OK) === true) {
            throw new Exception(
                'File upload error for '.$file['name'].': '.$this->getUploadErrorMessage($fileError)
            );
        }

        // Verify temporary file exists and is readable.
        $tmpName = $file['tmp_name'];

        if (file_exists($tmpName) === false || is_readable($tmpName) === false) {
            throw new Exception(
                'Temporary file not found or not readable for: '.$file['name']
            );
        }
    }//end validateUploadedFile()

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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array{error?: mixed|string, labels?: list<string>|mixed,...}, array<never, never>>
     */
    public function update(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);

            $data = $this->request->getParams();

            // Ensure tags is set to empty array if not provided.
            $tags = $data['tags'] ?? [];

            // Content is optional for metadata-only updates.
            $content = $data['content'] ?? null;

            $result = $this->fileService->updateFile(
                filePath: $fileId,
                content: $content,
                tags: $tags,
                object: $this->objectService->getObject()
            );

            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array{error?: string, success?: bool}, array<never, never>>
     */
    public function delete(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);

            $result = $this->fileService->deleteFile(
                file: $fileId,
                object: $this->objectService->getObject()
            );

            return new JSONResponse(data: ['success' => $result]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 400
            );
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array{error?: mixed|string, labels?: list<string>,...}, array<never, never>>
     */
    public function publish(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            $result = $this->fileService->publishFile(
                object: $object,
                file: $fileId
            );

            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array{error?: mixed|string, labels?: list<string>,...}, array<never, never>>
     */
    public function depublish(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse {
        // Set the schema and register to the object service (forces a check if the are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            $result = $this->fileService->unpublishFile(
                object: $object,
                filePath: $fileId
            );

            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
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
     *
     * @NoCSRFRequired
     *
     * @phpstan-param int $fileId
     *
     * @phpstan-return JSONResponse|\OCP\AppFramework\Http\StreamResponse
     *
     * @psalm-return JSONResponse<404|500, array{error: string},
     *     array<never, never>>|\OCP\AppFramework\Http\StreamResponse<200,
     *     array<never, never>>
     */
    public function downloadById(int $fileId): JSONResponse|\OCP\AppFramework\Http\StreamResponse
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
     * @return string[] The normalized tags array
     *
     * @psalm-return array<string>
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
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @psalm-return TemplateResponse<200, array<never, never>>
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            appName: 'openregister',
            templateName: 'index',
            params: []
        );
    }//end page()
}//end class
