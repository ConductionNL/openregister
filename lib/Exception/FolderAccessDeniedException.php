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
 * @spec openspec/specs/self-folder-access-control/spec.md
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
 * and map it to HTTP 403 with the structured body `{"error": "folder_access_denied"}`.
 *
 * **The response body MUST NOT echo the attempted folder ID.** Including it
 * would re-create the enumeration oracle the `self-folder-access-control`
 * capability spec was written to close: a caller probing `@self.folder` with
 * sequential integers could distinguish "folder exists but I can't read it"
 * (403 + id) from "folder does not exist" (auto-create / no-op) just by
 * inspecting the response shape. The attempted id remains available via
 * `getAttemptedFolderId()` for server-side audit logging only — see
 * `ObjectsController::folderAccessDeniedResponse()` for the canonical
 * controller-side mapping.
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
class FolderAccessDeniedException extends Exception {

	/**
	 * The HTTP status code controllers MUST map this exception to.
	 *
	 * Exposed as a class constant rather than as the exception's `code`
	 * value because `Exception::getCode()` is an application-level error
	 * code, not an HTTP status — conflating the two is brittle (future
	 * callers using `getCode()` for routing decisions get an HTTP number
	 * instead of a domain error number). Controllers reading this constant
	 * remain explicit about the HTTP mapping happening at the controller
	 * layer; the exception itself stays HTTP-agnostic.
	 *
	 * @var int
	 */
	public const HTTP_STATUS = 403;

	/**
	 * The folder ID the caller attempted to bind to.
	 *
	 * @var string
	 */
	private string $attemptedFolderId;

	/**
	 * FolderAccessDeniedException constructor.
	 *
	 * @param string $attemptedFolderId The folder ID the caller attempted to bind to.
	 * @param int $code Application error code; 0 by default. Controllers
	 *                  MUST use `self::HTTP_STATUS` for the HTTP mapping,
	 *                  not this code.
	 * @param Exception|null $previous The previous exception that caused this one, if any.
	 */
	public function __construct(string $attemptedFolderId, int $code = 0, ?Exception $previous = null) {
		$this->attemptedFolderId = $attemptedFolderId;

		$message = "Access to folder '" . $attemptedFolderId . "' is denied for the acting user.";
		parent::__construct(message: $message, code: $code, previous: $previous);

	}//end __construct()

	/**
	 * Get the folder ID the caller attempted to bind to.
	 *
	 * **Server-side use only** — for audit-trail entries and structured
	 * log lines. MUST NOT be included in the HTTP response body (see the
	 * class docblock for the enumeration-oracle rationale).
	 *
	 * @return string
	 */
	public function getAttemptedFolderId(): string {
		return $this->attemptedFolderId;
	}//end getAttemptedFolderId()
}//end class
