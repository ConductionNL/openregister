<?php

/**
 * OpenRegister RedactionCustodyController
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Service\Anonymisation\OriginalCustodyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use Throwable;

/**
 * The custody statement of an anonymised file's original: where it lives,
 * the retention applied, and when it is deleted.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */
class RedactionCustodyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                 $appName    The app name.
	 * @param IRequest               $request    The request.
	 * @param CallerFileAccess       $fileAccess Whether the caller may read the file.
	 * @param IRootFolder            $rootFolder Resolves the file once the caller may read it.
	 * @param OriginalCustodyService $custody    The custody statement.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CallerFileAccess $fileAccess,
		private readonly IRootFolder $rootFolder,
		private readonly OriginalCustodyService $custody,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The custody statement of a file, given as the original or as the redacted copy.
	 *
	 * The caller must be able to read the file: the same check as opening it.
	 *
	 * @param int $fileId The file.
	 *
	 * @return JSONResponse 200 with the statement, 403 when the caller cannot read the file, 404 when it is not a file.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	#[NoAdminRequired]
	public function show(int $fileId): JSONResponse {
		if ($this->mayReadFile(fileId: $fileId) === false) {
			return new JSONResponse(
				data: ['error' => 'forbidden', 'message' => 'You cannot read this file.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		try {
			$file = ($this->rootFolder->getById($fileId)[0] ?? null);
		} catch (Throwable $unreachable) {
			$file = null;
		}

		if (($file instanceof File) === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $this->custody->statement(file: $file));
	}//end show()

	/**
	 * Whether the caller may read the file: the check that opening it applies.
	 *
	 * @param int $fileId The file.
	 *
	 * @return bool
	 */
	private function mayReadFile(int $fileId): bool {
		return $this->fileAccess->mayOpen(fileId: $fileId);
	}//end mayReadFile()
}//end class
