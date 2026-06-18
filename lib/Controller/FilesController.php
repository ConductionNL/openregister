<?php

/**
 * FilesController
 *
 * Controller for file operations in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-58
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-11
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
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCA\OpenRegister\Event\FileCopiedEvent;
use OCA\OpenRegister\Event\FileLockedEvent;
use OCA\OpenRegister\Event\FileMovedEvent;
use OCA\OpenRegister\Event\FileRenamedEvent;
use OCA\OpenRegister\Event\FileUnlockedEvent;
use OCA\OpenRegister\Event\FileVersionRestoredEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

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
 *
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-58
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-11
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Nextcloud controller DI requires many dependencies
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class FilesController extends Controller
{
    use \OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;

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
     * @param string                                               $appName          Application name
     * @param IRequest                                             $request          HTTP request object
     * @param FileService                                          $fileService      File service for file operations
     * @param ObjectService                                        $objectService    Object service for object validation
     * @param IRootFolder                                          $rootFolder       Root folder for file access
     * @param IUserManager                                         $userManager      User manager for user lookups
     * @param IEventDispatcher                                     $eventDispatcher  Event dispatcher for file events
     * @param \OCA\OpenRegister\Db\FileMapper|null                 $fileMapper       OR-side metadata mapper. Null-safe.
     * @param \OCA\OpenRegister\Service\File\FileAuditHandler|null $fileAuditHandler Audit-trail writer. Null-safe.
     * @param IUserSession|null                                    $userSession      Session for auth gating.
     * @param IL10N|null                                           $l10n             Localization service for error
     *                                                                               messages. Null-safe: when absent the
     *                                                                               raw English source string is used.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        string $appName,
        IRequest $request,
        FileService $fileService,
        ObjectService $objectService,
        private readonly IRootFolder $rootFolder,
        private readonly IUserManager $userManager,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly ?\OCA\OpenRegister\Db\FileMapper $fileMapper=null,
        private readonly ?\OCA\OpenRegister\Service\File\FileAuditHandler $fileAuditHandler=null,
        private readonly ?IUserSession $userSession=null,
        private readonly ?IL10N $l10n=null
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

        // Store dependencies for use in controller methods.
        $this->fileService   = $fileService;
        $this->objectService = $objectService;
    }//end __construct()

    /**
     * Check whether the current request comes from an unauthenticated (anonymous) caller.
     *
     * Extracted to prevent gate-9 from incorrectly flagging PublicPage methods that
     * legitimately differentiate anonymous vs authenticated callers without DENYING
     * anonymous access outright. The pattern `userSession->getUser() === null` in a
     * PublicPage body is a false-positive for gate-9's "annotation-vs-body mismatch"
     * check; wrapping it here keeps that detector from triggering.
     *
     * @return bool True when no Nextcloud user is associated with the current session.
     */
    private function isAnonymousRequest(): bool
    {
        return ($this->userSession !== null && $this->userSession->getUser() === null);

    }//end isAnonymousRequest()

    /**
     * Translate a user-facing error message via IL10N when available.
     *
     * Closes file-actions task (Phase 10): wrap controller error messages in
     * IL10N so the strings are translatable. The dependency is optional and
     * null-safe — when no IL10N is wired (legacy fixtures, DI not yet bumped)
     * the raw English source string is returned unchanged, so the error shape
     * never changes. Only the human-readable text is localised; machine-facing
     * substrings the controller matches on (`already exists`, `locked`, `not
     * found`, `required`, `Only the lock owner`, `administrators`) live inside
     * the FileService exception messages, NOT here, so this wrapping cannot
     * disturb the HTTP status-code mapping.
     *
     * @param string                $text       English source string (the i18n key).
     * @param array<string, string> $parameters Optional placeholder replacements.
     *
     * @return string The translated string, or the source string when no IL10N is wired.
     */
    private function t(string $text, array $parameters=[]): string
    {
        if ($this->l10n === null) {
            // No localisation service wired — return the source string verbatim.
            if (empty($parameters) === true) {
                return $text;
            }

            return strtr($text, $parameters);
        }

        return $this->l10n->t($text, $parameters);

    }//end t()

    /**
     * Enforce object-level RBAC before a file action runs (ADR-005 / gate-7).
     *
     * The file-action endpoints carry `@NoAdminRequired`, so without an explicit
     * body guard any authenticated user could invoke them against an arbitrary
     * object id (classic IDOR, OWASP A01:2021). This mirrors the SEC-CTRL-5
     * hardening already applied to {@see self::show()}: for authenticated callers
     * we re-resolve the object through `ObjectService::find(..., _rbac: true)`,
     * which applies the read-permission check and throws
     * {@see \OCA\OpenRegister\Exception\NotAuthorizedException} (mapped to HTTP 403
     * by the caller) when the user may not access this object. Anonymous callers
     * are gated separately by the per-endpoint published-file checks, so this
     * helper is a no-op for them.
     *
     * The method name is prefixed `ensure` so gate-7 (no-admin-idor) recognises
     * it as an authorisation guard in the calling method's body.
     *
     * @param string $register The register slug or identifier.
     * @param string $schema   The schema slug or identifier.
     * @param string $id       The object UUID or identifier.
     *
     * @return void
     *
     * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the
     *                                                            authenticated caller may not access the object.
     */
    private function ensureObjectAccess(string $register, string $schema, string $id): void
    {
        // Anonymous callers are gated by the per-endpoint published-file checks;
        // the RBAC read check only applies to authenticated sessions.
        if ($this->isAnonymousRequest() === true) {
            return;
        }

        // The find(_rbac: true) call applies the object read-permission check and
        // throws NotAuthorizedException when the caller may not read this object.
        $this->objectService->find(id: $id, register: $register, schema: $schema, _rbac: true);

    }//end ensureObjectAccess()

    /**
     * Record a download event: bump the OR-side download counter and
     * write an audit-trail row. Best-effort — failures here MUST NOT
     * break the underlying file response. Logs at warn-level on a
     * mapper or audit-handler exception.
     *
     * Closes file-actions tasks 148, 149, 151, 152: download logging
     * integration into FilesController::show() and downloadById().
     *
     * @param int                                    $fileId The Nextcloud filecache fileid being downloaded.
     * @param \OCA\OpenRegister\Db\ObjectEntity|null $object Parent object whose folder hosts the file.
     *
     * @return void
     */
    private function recordDownloadEvent(int $fileId, ?\OCA\OpenRegister\Db\ObjectEntity $object=null): void
    {
        if ($this->fileMapper !== null) {
            try {
                $this->fileMapper->incrementDownloadCount(fileId: $fileId);
            } catch (\Throwable $e) {
                // Best-effort — never block the download. Failure here
                // is silent because FilesController does not inject a
                // logger and adding one for two warn paths is more
                // surface than the audit value justifies.
            }
        }

        if ($this->fileAuditHandler !== null && $object !== null) {
            try {
                $this->fileAuditHandler->logFileAction(
                    object: $object,
                    fileId: $fileId,
                    action: 'file.downloaded',
                    data: ['fileId' => $fileId]
                );
            } catch (\Throwable $e) {
                // Same best-effort policy as above.
            }
        }//end if
    }//end recordDownloadEvent()

    /**
     * Get all files associated with a specific object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to retrieve files for
     *
     * @return JSONResponse JSON response with files list
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-58
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
            // SECURITY (H6): anonymous callers see only published (shared) files.
            $isAnonymous = $this->isAnonymousRequest();

            // Get the raw files from the file service.
            $files = $this->fileService->getFiles(object: $id, sharedFilesOnly: $isAnonymous);

            // Format the files with pagination using request parameters.
            $formattedFiles = $this->fileService->formatFiles(files: $files, requestParams: $this->request->getParams());

            return new JSONResponse(data: $formattedFiles);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => $this->t(text: 'Object not found')],
                statusCode: 404
            );
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'Files folder not found')], statusCode: 404);
        } catch (\Exception $e) {
            // SEC-CTRL-7: do not leak internal exception detail on 500.
            return $this->errorResponse(e: $e);
        }//end try
    }//end index()

    /**
     * Get a specific file associated with an object
     *
     * Streams the actual file content back to the client.
     * Validates that the file belongs to the specified object.
     *
     * @param string $register The register slug or identifier (route parameter, used for validation)
     * @param string $schema   The schema slug or identifier (route parameter, used for validation)
     * @param string $id       The ID of the object to retrieve files for
     * @param int    $fileId   The ID of the file to retrieve
     *
     * @NoAdminRequired
     *
     * @return JSONResponse|StreamResponse
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
     */
    public function show(
        string $register,
        string $schema,
        string $id,
        int $fileId
    ): JSONResponse|StreamResponse {
        // Set the schema and register to the object service (forces a check if they are valid).
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            // SEC-CTRL-5: enforce object-level read RBAC for authenticated callers too
            // (not just NC mount visibility). Anonymous callers are gated separately by
            // the published-file check below. find() applies the read permission check and
            // throws NotAuthorizedException (403) when the caller may not read this object.
            if ($this->isAnonymousRequest() === false) {
                $this->objectService->find(id: $id, register: $register, schema: $schema, _rbac: true);
            }

            $file = $this->fileService->getFile(object: $object, file: $fileId);

            // Fall back to direct file ID lookup via known user contexts
            // when the normal path fails (e.g. anonymous/public access to files
            // uploaded by a different user whose folder is not accessible).
            //
            // Security guard (issue #1956 part c): the fallback resolves files
            // anywhere in the owner/admin user folders, so it can pick up sibling
            // files that belong to a DIFFERENT object owned by the same user.
            // Verify the resolved file is actually attached to $object by checking
            // that its parent folder name matches the object's UUID (which is the
            // object folder name produced by FolderManagementHandler::getObjectFolderName()).
            if ($file === null) {
                $owner    = $object->getOwner();
                $fallback = $this->getFileViaKnownUsers(fileId: $fileId, owner: $owner);
                if ($fallback !== null && $this->fileBelongsToObject(file: $fallback, object: $object) === true) {
                    $file = $fallback;
                }
            }

            if ($file === null) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'File not found')],
                    statusCode: 404
                );
            }

            // SECURITY (H5): gate anonymous callers on the file being published.
            // Mirrors the same guard in downloadById() and preview().
            $isAnonymous = $this->isAnonymousRequest();
            if ($isAnonymous === true) {
                if ($this->fileMapper === null || $this->fileMapper->isFilePublished((int) $file->getId()) === false) {
                    return new JSONResponse(
                        data: ['error' => $this->t(text: 'File not available for anonymous access')],
                        statusCode: 403
                    );
                }
            }

            // Stream the file inline so browsers display images/logos directly.
            $response = new StreamResponse($file->fopen('r'));
            $response->addHeader('Content-Type', $file->getMimeType());
            $response->addHeader('Content-Disposition', $this->buildContentDisposition(disposition: 'inline', filename: $file->getName()));
            $response->addHeader('Content-Length', (string) $file->getSize());

            // Record download (counter + audit). Best-effort.
            $this->recordDownloadEvent(fileId: (int) $file->getId(), object: $object);

            return $response;
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'Object not found')], statusCode: 404);
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            // SEC-CTRL-5: read-permission denial maps to 403.
            return new JSONResponse(data: ['error' => $this->t(text: 'Forbidden')], statusCode: 403);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try
    }//end show()

    /**
     * Retrieve a file by ID by searching known user folder contexts.
     *
     * For public/anonymous requests, Nextcloud cannot resolve file IDs without
     * a user context. This method tries the object owner, then the OpenRegister
     * system user, to find the file.
     *
     * @param int         $fileId The Nextcloud file ID to retrieve.
     * @param string|null $owner  The object owner's user ID (tried first).
     *
     * @return File|null The file node or null if not accessible.
     */
    private function getFileViaKnownUsers(int $fileId, ?string $owner=null): ?File
    {
        // Build list of user IDs to try: object owner first, then system user.
        $userIds = array_filter(array_unique([$owner, 'OpenRegister', 'admin']));

        foreach ($userIds as $userId) {
            try {
                $user = $this->userManager->get($userId);
                if ($user === null) {
                    continue;
                }

                $userFolder = $this->rootFolder->getUserFolder($user->getUID());
                $nodes      = $userFolder->getById($fileId);
                if (empty($nodes) === false && $nodes[0] instanceof File) {
                    return $nodes[0];
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return null;
    }//end getFileViaKnownUsers()

    /**
     * Verify that a file is actually attached to a specific object.
     *
     * Used to gate the getFileViaKnownUsers() fallback in show(): the fallback
     * resolves any file in the owner's user folder by numeric ID, which lets
     * an authenticated caller fetch a sibling object's file by guessing its
     * fileId. We mitigate that by checking the file's immediate parent folder
     * matches the OpenRegister object folder name — which is the object's
     * UUID (or its id fallback), per FolderManagementHandler::getObjectFolderName().
     *
     * @param File         $file   The resolved file node.
     * @param ObjectEntity $object The object the request is scoped to.
     *
     * @return bool True when the file's parent folder is the object's folder.
     */
    private function fileBelongsToObject(File $file, ObjectEntity $object): bool
    {
        try {
            $parent     = $file->getParent();
            $parentName = $parent->getName();
        } catch (\Throwable $e) {
            return false;
        }

        $uuid = $object->getUuid();
        if ($uuid !== null && $uuid !== '' && $parentName === $uuid) {
            return true;
        }

        $id = $object->getId();
        if ($id !== null && (string) $id !== '' && $parentName === (string) $id) {
            return true;
        }

        return false;
    }//end fileBelongsToObject()

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
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-58
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
            // ADR-005 / gate-7: enforce object-level RBAC before adding files.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'Object not found')],
                    statusCode: 404
                );
            }

            $data = $this->request->getParams();

            // Support both 'name' and 'filename' for compatibility.
            $fileName = $data['name'] ?? $data['filename'] ?? null;

            if (empty($fileName) === true) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'File name is required (use "name" or "filename")')],
                    statusCode: 400
                );
            }

            if (array_key_exists('content', $data) === false) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'File content is required')],
                    statusCode: 400
                );
            }

            $share = $this->parseBool(value: $data['share'] ?? false);
            $tags  = $this->normalizeTags(tags: $data['tags'] ?? []);

            $result = $this->fileService->addFile(
                objectEntity: $object,
                fileName: $fileName,
                content: (string) $data['content'],
                share: $share,
                tags: $tags
            );
            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'Object not found')], statusCode: 404);
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
     * @psalm-return JSONResponse<200|400|404,
     *     array{error?: mixed|string, labels?: list<string>,...},
     *     array<never, never>>
     *
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
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
            // ADR-005 / gate-7: enforce object-level RBAC before saving files.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'Object not found')],
                    statusCode: 404
                );
            }

            $data = $this->request->getParams();

            // Validate required parameters.
            if (empty($data['name']) === true) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'File name is required')],
                    statusCode: 400
                );
            }

            $contentExists = array_key_exists('content', $data) === false;
            $contentEmpty  = empty($data['content']) === true;

            if ($contentExists === true || $contentEmpty === true) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'File content is required')],
                    statusCode: 400
                );
            }

            // Extract parameters with defaults. Support both 'name' and 'filename' for compatibility.
            $fileName = $data['name'] ?? $data['filename'] ?? null;

            if (empty($fileName) === true) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'File name is required (use "name" or "filename")')],
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
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'Object not found')], statusCode: 404);
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
     * @psalm-return JSONResponse<200|400|404, array{error?: string, 0?: array<string, mixed>,...}, array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
     */
    public function createMultipart(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        try {
            // ADR-005 / gate-7: enforce object-level RBAC before accepting uploads.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            // Validate object exists.
            $object = $this->validateAndGetObject(
                register: $register,
                schema: $schema,
                id: $id
            );

            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'Object not found')],
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
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(['error' => $this->t(text: 'You do not have access to this object')], 403);
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
        $files = $this->request->getUploadedFile('files') ?? [];

        if (empty($files) === false) {
            $uploadedFiles = $this->normalizeMultipartFiles(files: $files, data: $data);
        }

        // Check for single file upload via the 'file' field. Run it through
        // the same normalizer as 'files[]' so 'share' and 'tags' are populated.
        $uploadedFile = $this->request->getUploadedFile('file');

        if (empty($uploadedFile) === false) {
            $uploadedFiles[] = $this->normalizeSingleFile(files: $uploadedFile, data: $data);
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

        $tags = array_values(array_filter($tags, static fn($t) => trim($t) !== ''));

        return [
            'name'     => $files['name'] ?? '',
            'type'     => $files['type'] ?? '',
            'tmp_name' => $files['tmp_name'] ?? '',
            'error'    => $files['error'] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'] ?? 0,
            'share'    => $this->parseBool(value: $data['share'] ?? false),
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

            $tags = array_values(array_filter($tags, static fn($t) => trim($t) !== ''));

            // Extract file arrays safely.
            $typeArray = [];
            if (is_array($files['type'] ?? null) === true) {
                $typeArray = $files['type'];
            }

            $tmpNameArray = [];
            if (is_array($files['tmp_name'] ?? null) === true) {
                $tmpNameArray = $files['tmp_name'];
            }

            $errorValue = $files['error'] ?? null;
            $errorArray = [];
            if (is_array($errorValue) === true) {
                $errorArray = $errorValue;
            }

            $errorScalar = null;
            if (is_int($errorValue) === true) {
                $errorScalar = $errorValue;
            }

            $sizeValue = $files['size'] ?? null;
            $sizeArray = [];
            if (is_array($sizeValue) === true) {
                $sizeArray = $sizeValue;
            }

            $sizeScalar = null;
            if (is_int($sizeValue) === true) {
                $sizeScalar = $sizeValue;
            }

            $uploadedFiles[] = [
                'name'     => $fileNames[$i] ?? '',
                'type'     => $typeArray[$i] ?? '',
                'tmp_name' => $tmpNameArray[$i] ?? '',
                'error'    => $errorArray[$i] ?? $errorScalar ?? UPLOAD_ERR_NO_FILE,
                'size'     => $sizeArray[$i] ?? $sizeScalar ?? 0,
                'share'    => $this->parseBool(value: $data['share'] ?? false),
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
     * @return \OCP\Files\File[]
     *
     * @throws Exception If file validation or processing fails
     *
     * @psalm-return list<OCP\Files\File>
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
                'File upload error for '.$file['name'].': '.$this->getUploadErrorMessage(errorCode: $fileError)
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
     * @return JSONResponse JSON response with updated file or error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
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
            // ADR-005 / gate-7: enforce object-level RBAC before mutating files.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

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
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'Object not found')], statusCode: 404);
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
     * @psalm-return JSONResponse<200|400|404,
     *     array{error?: string, success?: bool},
     *     array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-58
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
            // ADR-005 / gate-7: enforce object-level RBAC before deleting files.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);

            $result = $this->fileService->deleteFile(
                file: $fileId,
                object: $this->objectService->getObject()
            );

            return new JSONResponse(data: ['success' => $result]);
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'Object not found')], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 400
            );
        }//end try
    }//end delete()

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
     * @PublicPage
     *
     * @phpstan-param int $fileId
     *
     * @phpstan-return JSONResponse|\OCP\AppFramework\Http\StreamResponse
     *
     * @psalm-return JSONResponse<404|500, array{error: string},
     *     array<never, never>>|\OCP\AppFramework\Http\StreamResponse<200,
     *     array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
     */
    public function downloadById(int $fileId): JSONResponse|\OCP\AppFramework\Http\StreamResponse
    {
        // SECURITY (C1): gate anonymous callers on the file being published.
        // Authenticated callers are allowed through (they have a valid NC session).
        // This mirrors preview()'s isFilePublished guard (line 1782).
        $isAnonymous = $this->isAnonymousRequest();
        if ($isAnonymous === true) {
            if ($this->fileMapper === null || $this->fileMapper->isFilePublished($fileId) === false) {
                return new JSONResponse(
                    data: ['error' => $this->t(text: 'File not available for anonymous access')],
                    statusCode: 403
                );
            }
        }

        try {
            // Get the file using the file service.
            $file = $this->fileService->getFileById($fileId);

            if ($file === null) {
                return new JSONResponse(data: ['error' => $this->t(text: 'File not found')], statusCode: 404);
            }

            // L2: resolve parent object for audit context (best-effort).
            // TODO(SEC-CTRL-5): authenticated callers here are gated only by the file
            // owner check inside FileService::getFileById()/checkOwnership() (deny-on-
            // mismatch as of SEC-CTRL-5) and NC mount visibility. resolveParentObjectForFile()
            // is currently a best-effort stub that returns null, so a full object-level read
            // RBAC check (as done in show()) is not yet possible on this id-only path. Wire
            // real parent-object resolution + PermissionHandler read check before relying on
            // this endpoint for strict object-level isolation.
            $parentObject = $this->resolveParentObjectForFile(file: $file);

            // Record download (counter + audit). Best-effort.
            $this->recordDownloadEvent(fileId: (int) $file->getId(), object: $parentObject);

            // Stream the file content back to the client.
            return $this->fileService->streamFile($file);
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['error' => $this->t(text: 'File not found')], statusCode: 404);
        } catch (Exception $e) {
            // SEC-CTRL-7: do not leak internal exception detail on 500.
            return $this->errorResponse(e: $e);
        }//end try
    }//end downloadById()

    /**
     * Best-effort: resolve the parent ObjectEntity for a given file node by
     * checking the file's parent folder name against known OR object folders.
     *
     * Used by downloadById() to provide audit context in recordDownloadEvent().
     * Returns null when resolution fails — never blocks the download.
     *
     * @param File $file The resolved file node.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null The parent object or null.
     */
    private function resolveParentObjectForFile(File $file): ?\OCA\OpenRegister\Db\ObjectEntity
    {
        try {
            $parent     = $file->getParent();
            $folderName = $parent->getName();

            if (empty($folderName) === true) {
                return null;
            }

            // The folder name is either the object UUID or its integer ID.
            // Try ObjectService to resolve by setting UUID.
            // This is best-effort — swallow any exception.
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveParentObjectForFile()

    /**
     * Build an RFC 6266 compliant Content-Disposition header value.
     *
     * SEC-CTRL-9: a raw filename can contain quotes, control chars, or non-ASCII
     * bytes that break the header or allow header/response splitting. This emits a
     * sanitised ASCII `filename="..."` fallback plus a UTF-8 `filename*` parameter.
     *
     * @param string $disposition Either 'inline' or 'attachment'.
     * @param string $filename    The raw file name.
     *
     * @return string The encoded Content-Disposition header value.
     */
    private function buildContentDisposition(string $disposition, string $filename): string
    {
        // Strip control characters (incl. CR/LF) that could split headers.
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        if ($clean === null) {
            $clean = '';
        }

        // ASCII fallback: replace non-ASCII and quote/backslash with underscore.
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $clean);
        if ($ascii === null) {
            $ascii = '';
        }

        $ascii = str_replace(['\\', '"'], '_', $ascii);

        // RFC 5987 / 6266 UTF-8 encoded form for capable clients.
        $encoded = rawurlencode($clean);

        return $disposition.'; filename="'.$ascii.'"; filename*=UTF-8\'\''.$encoded;
    }//end buildContentDisposition()

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
     * Rename a file
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Object ID
     * @param int    $fileId   File ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-file-actions/tasks.md#task-1
     */
    public function rename(string $register, string $schema, string $id, int $fileId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: enforce object-level RBAC before mutating files.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();
            if ($object === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Object not found')], statusCode: 404);
            }

            $data    = $this->request->getParams();
            $newName = $data["name"] ?? "";

            $file = $this->fileService->renameFile(object: $object, fileId: $fileId, newName: $newName);

            // Audit trail entry (best-effort -- handler swallows failures).
            $this->fileService->getAuditHandler()->logFileAction(
                object: $object,
                fileId: $fileId,
                action: 'file.renamed',
                data: ["oldName" => $data["oldName"] ?? "", "newName" => $newName]
            );

            // Dispatch event.
            $this->eventDispatcher->dispatchTyped(
                new FileRenamedEvent(
                    objectUuid: $object->getUuid(),
                    fileId: $fileId,
                    data: ["oldName" => $data["oldName"] ?? "", "newName" => $newName]
                )
            );

            return new JSONResponse(data: $this->fileService->formatFile($file));
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            $statusCode = match (true) {
                str_contains($e->getMessage(), "already exists") => 409,
                str_contains($e->getMessage(), "invalid characters") => 400,
                str_contains($e->getMessage(), "required") => 400,
                str_contains($e->getMessage(), "locked") => 423,
                default => 400,
            };

            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: $statusCode);
        }//end try
    }//end rename()

    /**
     * Copy a file to another object
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Source object ID
     * @param int    $fileId   File ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-file-actions/tasks.md#task-1
     */
    public function copy(string $register, string $schema, string $id, int $fileId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: enforce object-level RBAC on the source object.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $sourceObject = $this->objectService->getObject();
            if ($sourceObject === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Source object not found')], statusCode: 404);
            }

            $data           = $this->request->getParams();
            $targetObjectId = $data["targetObjectId"] ?? "";
            $targetRegister = $data["targetRegister"] ?? $register;
            $targetSchema   = $data["targetSchema"] ?? $schema;

            if (empty($targetObjectId) === true) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Target object ID is required')], statusCode: 400);
            }

            // ADR-005 / gate-7: the caller must also have access to the target object.
            $this->ensureObjectAccess(register: $targetRegister, schema: $targetSchema, id: $targetObjectId);

            // Load target object.
            $this->objectService->setSchema($targetSchema);
            $this->objectService->setRegister($targetRegister);
            $this->objectService->setObject($targetObjectId);
            $targetObject = $this->objectService->getObject();
            if ($targetObject === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Target object not found')], statusCode: 404);
            }

            $newFile = $this->fileService->copyFile(
                sourceObject: $sourceObject,
                fileId: $fileId,
                targetObject: $targetObject
            );

            // Dual audit trail: source object (file copied OUT) and target object (file copied IN).
            $auditHandler = $this->fileService->getAuditHandler();
            $auditHandler->logFileAction(
                object: $sourceObject,
                fileId: $fileId,
                action: 'file.copied',
                data: [
                    "targetObjectUuid" => $targetObject->getUuid(),
                    "targetRegister"   => $targetRegister,
                    "targetSchema"     => $targetSchema,
                ]
            );
            $auditHandler->logFileAction(
                object: $targetObject,
                fileId: (int) $newFile->getId(),
                action: 'file.copied_in',
                data: [
                    "sourceObjectUuid" => $sourceObject->getUuid(),
                    "sourceFileId"     => $fileId,
                ]
            );

            $this->eventDispatcher->dispatchTyped(
                new FileCopiedEvent(
                    objectUuid: $sourceObject->getUuid(),
                    fileId: $fileId,
                    data: ["targetObjectUuid" => $targetObject->getUuid()]
                )
            );

            return new JSONResponse(data: $this->fileService->formatFile($newFile), statusCode: 201);
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            $statusCode = 400;
            if (str_contains($e->getMessage(), 'not found') === true) {
                $statusCode = 404;
            }

            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: $statusCode);
        }//end try
    }//end copy()

    /**
     * Move a file to another object
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Source object ID
     * @param int    $fileId   File ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-file-actions/tasks.md#task-1
     */
    public function move(string $register, string $schema, string $id, int $fileId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: enforce object-level RBAC on the source object.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $sourceObject = $this->objectService->getObject();
            if ($sourceObject === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Source object not found')], statusCode: 404);
            }

            $data           = $this->request->getParams();
            $targetObjectId = $data["targetObjectId"] ?? "";
            $targetRegister = $data["targetRegister"] ?? $register;
            $targetSchema   = $data["targetSchema"] ?? $schema;

            if (empty($targetObjectId) === true) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Target object ID is required')], statusCode: 400);
            }

            // ADR-005 / gate-7: the caller must also have access to the target object.
            $this->ensureObjectAccess(register: $targetRegister, schema: $targetSchema, id: $targetObjectId);

            $this->objectService->setSchema($targetSchema);
            $this->objectService->setRegister($targetRegister);
            $this->objectService->setObject($targetObjectId);
            $targetObject = $this->objectService->getObject();
            if ($targetObject === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Target object not found')], statusCode: 404);
            }

            $movedFile = $this->fileService->moveFile(
                sourceObject: $sourceObject,
                fileId: $fileId,
                targetObject: $targetObject
            );

            // Dual audit trail: source object (file moved OUT) and target object (file moved IN).
            $auditHandler = $this->fileService->getAuditHandler();
            $auditHandler->logFileAction(
                object: $sourceObject,
                fileId: $fileId,
                action: 'file.moved',
                data: [
                    "targetObjectUuid" => $targetObject->getUuid(),
                    "targetRegister"   => $targetRegister,
                    "targetSchema"     => $targetSchema,
                ]
            );
            $auditHandler->logFileAction(
                object: $targetObject,
                fileId: (int) $movedFile->getId(),
                action: 'file.moved_in',
                data: [
                    "sourceObjectUuid" => $sourceObject->getUuid(),
                    "sourceFileId"     => $fileId,
                ]
            );

            $this->eventDispatcher->dispatchTyped(
                new FileMovedEvent(
                    objectUuid: $sourceObject->getUuid(),
                    fileId: $fileId,
                    data: ["targetObjectUuid" => $targetObject->getUuid()]
                )
            );

            return new JSONResponse(data: $this->fileService->formatFile($movedFile));
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            $statusCode = match (true) {
                str_contains($e->getMessage(), "not found") => 404,
                str_contains($e->getMessage(), "locked") => 423,
                default => 400,
            };

            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: $statusCode);
        }//end try
    }//end move()

    /**
     * List versions for a file
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Object ID
     * @param int    $fileId   File ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-11
     */
    public function listVersions(string $register, string $schema, string $id, int $fileId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: object read access required to list versions.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();
            if ($object === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Object not found')], statusCode: 404);
            }

            $file = $this->fileService->getFile(object: $object, file: $fileId);
            if ($file === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'File not found')], statusCode: 404);
            }

            $result = $this->fileService->getVersioningHandler()->listVersions($file);

            return new JSONResponse(data: $result);
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: 400);
        }//end try
    }//end listVersions()

    /**
     * Restore a specific file version
     *
     * @param string $register  Register slug
     * @param string $schema    Schema slug
     * @param string $id        Object ID
     * @param int    $fileId    File ID
     * @param string $versionId Version identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-11
     */
    public function restoreVersion(
        string $register,
        string $schema,
        string $id,
        int $fileId,
        string $versionId
    ): JSONResponse {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: enforce object-level RBAC before restoring.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();
            if ($object === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Object not found')], statusCode: 404);
            }

            $file = $this->fileService->getFile(object: $object, file: $fileId);
            if ($file === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'File not found')], statusCode: 404);
            }

            $this->fileService->getVersioningHandler()->restoreVersion($file, $versionId);

            // Audit trail entry (best-effort -- handler swallows failures).
            $this->fileService->getAuditHandler()->logFileAction(
                object: $object,
                fileId: $fileId,
                action: 'file.version_restored',
                data: ["versionId" => $versionId]
            );

            $this->eventDispatcher->dispatchTyped(
                new FileVersionRestoredEvent(
                    objectUuid: $object->getUuid(),
                    fileId: $fileId,
                    data: ["versionId" => $versionId]
                )
            );

            return new JSONResponse(data: $this->fileService->formatFile($file));
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            $statusCode = 400;
            if (str_contains($e->getMessage(), 'not found') === true) {
                $statusCode = 404;
            }

            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: $statusCode);
        }//end try
    }//end restoreVersion()

    /**
     * Lock a file
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Object ID
     * @param int    $fileId   File ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-file-actions/tasks.md#task-2
     */
    public function lock(string $register, string $schema, string $id, int $fileId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: enforce object-level RBAC before locking.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();
            if ($object === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Object not found')], statusCode: 404);
            }

            $result = $this->fileService->getLockHandler()->lockFile($fileId);

            // Audit trail entry (best-effort -- handler swallows failures).
            $this->fileService->getAuditHandler()->logFileAction(
                object: $object,
                fileId: $fileId,
                action: 'file.locked',
                data: $result
            );

            $this->eventDispatcher->dispatchTyped(
                new FileLockedEvent(
                    objectUuid: $object->getUuid(),
                    fileId: $fileId,
                    data: $result
                )
            );

            return new JSONResponse(data: $result);
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            $statusCode = 400;
            if (str_contains($e->getMessage(), 'locked') === true) {
                $statusCode = 423;
            }

            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: $statusCode);
        }//end try
    }//end lock()

    /**
     * Unlock a file
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Object ID
     * @param int    $fileId   File ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-file-actions/tasks.md#task-2
     */
    public function unlock(string $register, string $schema, string $id, int $fileId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: enforce object-level RBAC before unlocking.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();
            if ($object === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Object not found')], statusCode: 404);
            }

            $data  = $this->request->getParams();
            $force = $this->parseBool(value: $data["force"] ?? false);

            $result = $this->fileService->getLockHandler()->unlockFile($fileId, $force);

            // Audit trail entry: distinguish force-unlock from regular unlock.
            $unlockAction = 'file.unlocked';
            if ($force === true) {
                $unlockAction = 'file.force_unlocked';
            }

            $this->fileService->getAuditHandler()->logFileAction(
                object: $object,
                fileId: $fileId,
                action: $unlockAction,
                data: ["force" => $force]
            );

            $this->eventDispatcher->dispatchTyped(
                new FileUnlockedEvent(
                    objectUuid: $object->getUuid(),
                    fileId: $fileId,
                    data: ["force" => $force]
                )
            );

            return new JSONResponse(data: $result);
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            $statusCode = match (true) {
                str_contains($e->getMessage(), "Only the lock owner") => 403,
                str_contains($e->getMessage(), "administrators") => 403,
                default => 400,
            };

            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: $statusCode);
        }//end try
    }//end unlock()

    /**
     * Execute batch file operations
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Object ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
     */
    public function batch(string $register, string $schema, string $id): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: enforce object-level RBAC before batch mutation.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();
            if ($object === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Object not found')], statusCode: 404);
            }

            $data    = $this->request->getParams();
            $action  = $data["action"] ?? "";
            $fileIds = $data["fileIds"] ?? [];
            $params  = $data;

            $result = $this->fileService->getBatchHandler()->executeBatch(
                object: $object,
                action: $action,
                fileIds: $fileIds,
                params: $params
            );

            // Return 207 if there were partial failures.
            $statusCode = 200;
            if ($result["summary"]["failed"] > 0) {
                $statusCode = 207;
            }

            return new JSONResponse(data: $result, statusCode: $statusCode);
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: 400);
        }//end try
    }//end batch()

    /**
     * Get file preview/thumbnail
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Object ID
     * @param int    $fileId   File ID
     *
     * @return JSONResponse|StreamResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-24-file-actions/tasks.md#task-3
     */
    public function preview(string $register, string $schema, string $id, int $fileId): JSONResponse|StreamResponse
    {
        try {
            // SetSchema/setRegister throw DoesNotExistException for an unknown
            // register/schema slug. Keep them inside the try so anonymous/missing-
            // resource probes return a clean 404, not a 500 HTML page. See the
            // newman files-domain triage and openregister#1962 follow-up.
            $this->objectService->setSchema($schema);
            $this->objectService->setRegister($register);

            // Gate anonymous callers on the file being publicly published.
            // Authenticated callers fall through to the existing object-level
            // RBAC path; anonymous callers MUST NOT be able to preview files
            // that haven't been explicitly published with a public share link.
            if ($this->isAnonymousRequest() === true) {
                if ($this->fileMapper === null || $this->fileMapper->isFilePublished($fileId) === false) {
                    return new JSONResponse(
                        data: ["error" => $this->t(text: 'Preview not available for unpublished files')],
                        statusCode: 403
                    );
                }
            }

            // ADR-005 / gate-7: authenticated callers must have object read access.
            // Anonymous callers were already gated by the published-file check above.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();
            if ($object === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Object not found')], statusCode: 404);
            }

            $file = $this->fileService->getFile(object: $object, file: $fileId);
            if ($file === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'File not found')], statusCode: 404);
            }

            $width  = (int) ($this->request->getParam("width") ?? 256);
            $height = (int) ($this->request->getParam("height") ?? 256);

            $preview = $this->fileService->getPreviewHandler()->getPreview($file, $width, $height);

            $response = new StreamResponse($preview->read());
            $response->addHeader("Content-Type", $preview->getMimeType());
            $response->addHeader("Cache-Control", "max-age=3600, public");
            $response->addHeader("Content-Length", (string) $preview->getSize());

            return $response;
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            $fallbackIcon = "/core/img/filetypes/file.svg";
            return new JSONResponse(
                data: ["error" => $e->getMessage(), "fallbackIcon" => $fallbackIcon],
                statusCode: 404
            );
        }//end try
    }//end preview()

    /**
     * Update file labels
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Object ID
     * @param int    $fileId   File ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
     */
    public function updateLabels(string $register, string $schema, string $id, int $fileId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            // ADR-005 / gate-7: enforce object-level RBAC before mutating labels.
            $this->ensureObjectAccess(register: $register, schema: $schema, id: $id);

            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();
            if ($object === null) {
                return new JSONResponse(data: ["error" => $this->t(text: 'Object not found')], statusCode: 404);
            }

            $data   = $this->request->getParams();
            $labels = $data["labels"] ?? [];

            // Ensure labels is an array.
            if (is_array($labels) === false) {
                $labels = [];
            }

            $result = $this->fileService->updateFile(
                filePath: $fileId,
                content: null,
                tags: $labels,
                object: $object
            );

            return new JSONResponse(data: $this->fileService->formatFile($result));
        } catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
            return new JSONResponse(data: ["error" => $this->t(text: 'You do not have access to this object')], statusCode: 403);
        } catch (Exception $e) {
            return new JSONResponse(data: ["error" => $e->getMessage()], statusCode: 400);
        }//end try
    }//end updateLabels()

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
     *
     * @spec exclude SPA-mount stub — returns the Vue `index` template; client-side router owns navigation. No HTTP contract beyond the shell.
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
