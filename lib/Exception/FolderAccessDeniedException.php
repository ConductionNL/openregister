<?php

/**
 * FolderAccessDeniedException
 *
 * Exception thrown when a user attempts to bind an object to a folder they
 * cannot access. This exception maps to HTTP 403 Forbidden at the controller
 * layer and is the canonical signal for folder-access denials in the
 * self.folder binding pipeline.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/validate-self-folder-access/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Exception for folder access denial in the self.folder binding pipeline.
 *
 * Thrown by FolderManagementHandler::createObjectFolderById() whenever a
 * caller-supplied numeric folder ID cannot be bound because:
 * - The node does not exist in the acting user's mount.
 * - The node exists but is not a Folder (e.g. it is a file).
 * - The Folder is not readable by the acting user (isReadable() returned false).
 * - No session user is available to perform the access check.
 *
 * Controllers catching this exception MUST return HTTP 403 with a structured
 * body containing at minimum:
 *   { "error": "folder_access_denied", "folder": "<requested-id>" }
 *
 * This class extends \Exception directly and deliberately does NOT extend
 * OCP\Files\NotPermittedException or any other Nextcloud exception, so that
 * catch-blocks written for Nextcloud primitives do not accidentally absorb it.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/validate-self-folder-access/tasks.md#task-1
 */
class FolderAccessDeniedException extends \Exception
{

    /**
     * The folder node ID that was requested but denied.
     *
     * @var string
     */
    private readonly string $folderId;

    /**
     * Constructor for FolderAccessDeniedException.
     *
     * @param string          $folderId The numeric node ID of the folder that was denied.
     * @param string          $message  Human-readable description of why access was denied.
     * @param int             $code     Exception code (defaults to 0).
     * @param \Throwable|null $previous Optional previous exception for chaining.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-1
     */
    public function __construct(
        string $folderId,
        string $message='Folder access denied',
        int $code=0,
        ?\Throwable $previous=null
    ) {
        $this->folderId = $folderId;

        // Include the folder ID in the message for traceability.
        parent::__construct(
            message: $message.' (folder: '.$folderId.')',
            code: $code,
            previous: $previous
        );
    }//end __construct()

    /**
     * Returns the folder node ID that was denied.
     *
     * Used by controllers to populate the structured HTTP 403 response body.
     *
     * @return string The numeric node ID of the denied folder.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-1
     */
    public function getFolderId(): string
    {
        return $this->folderId;
    }//end getFolderId()
}//end class
