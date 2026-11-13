<?php

/**
 * Lists where a file's text was sent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\Db\TextDisclosure;
use OCA\OpenRegister\Files\Db\TextDisclosureMapper;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The text disclosures of one file, for a caller who may read the file.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */
class FileDisclosuresController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest             $request    The request.
	 * @param TextDisclosureMapper $mapper     The disclosure store.
	 * @param CallerFileAccess     $fileAccess Whether the caller may open a file.
	 */
	public function __construct(
		IRequest $request,
		private readonly TextDisclosureMapper $mapper,
		private readonly CallerFileAccess $fileAccess,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The external services a file's text was sent to, newest first.
	 *
	 * Any signed-in user may call it; the file read check runs first, and a
	 * caller who may not read the file gets 403 and nothing else.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return JSONResponse 200 with the disclosures, or 403.
	 *
	 * @psalm-return JSONResponse<200|403, array, array<never, never>>
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(int $fileId): JSONResponse {
		if ($this->fileAccess->mayOpen(fileId: $fileId) === false) {
			return new JSONResponse(
				data: ['error' => 'You may not read this file'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$disclosures = array_map(
			static fn (TextDisclosure $disclosure): array => [
				'service' => $disclosure->getService(),
				'host' => $disclosure->getHost(),
				'at' => $disclosure->getAt()->format(DATE_ATOM),
				'bytes' => $disclosure->getBytes(),
			],
			$this->mapper->findByFileId(fileId: $fileId)
		);

		return new JSONResponse(data: ['fileId' => $fileId, 'disclosures' => $disclosures]);
	}//end index()
}//end class
