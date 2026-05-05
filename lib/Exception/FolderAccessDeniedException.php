<?php

/**
 * Class FolderAccessDeniedException
 *
 * Thrown when a `@self.folder` write attempts to bind an object to a folder
 * that the acting user cannot read.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Exception
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/validate-self-folder-access/specs/self-folder-access-control/spec.md
 */

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Exception thrown when a `@self.folder` bind is denied.
 *
 * Raised by `FolderManagementHandler::createObjectFolderById()` when:
 *  - the supplied folder ID does not resolve in the acting user's user-folder mount,
 *  - the resolved node is not a `Folder` (e.g. a file ID was supplied),
 *  - the resolved folder is not readable by the acting user (`Folder::isReadable() === false`).
 *
 * Controllers MUST catch this exception specifically (not generic `\Exception`)
 * and map it to HTTP 403 with a structured body of the form
 * `{"error": "folder_access_denied", "folder": "<requested-id>"}`.
 *
 * The class extends `\Exception` directly — NOT `OCP\Files\NotPermittedException`
 * or any other Nextcloud exception — so generic catch-blocks for those exceptions
 * do not silently absorb a denial.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @phpstan-consistent-constructor
 */
class FolderAccessDeniedException extends Exception
{

    /**
     * The folder ID the caller attempted to bind to.
     *
     * @var string
     */
    private string $attemptedFolderId;

    /**
     * FolderAccessDeniedException constructor.
     *
     * @param string         $attemptedFolderId The folder ID the caller attempted to bind to.
     * @param int            $code              HTTP status code (default: 403 Forbidden).
     * @param Exception|null $previous          The previous exception that caused this one, if any.
     */
    public function __construct(string $attemptedFolderId, int $code=403, ?Exception $previous=null)
    {
        $this->attemptedFolderId = $attemptedFolderId;

        $message = "Access to folder '".$attemptedFolderId."' is denied for the acting user.";
        parent::__construct(message: $message, code: $code, previous: $previous);

    }//end __construct()

    /**
     * Get the folder ID the caller attempted to bind to.
     *
     * Used by controller error handlers to populate the `folder` field of
     * the structured 403 response body.
     *
     * @return string
     */
    public function getAttemptedFolderId(): string
    {
        return $this->attemptedFolderId;

    }//end getAttemptedFolderId()
}//end class
