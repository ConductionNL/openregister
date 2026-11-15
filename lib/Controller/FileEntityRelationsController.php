<?php

/**
 * Lists the entity relations (the findings) of one file.
 *
 * Read by opencatalogi's redaction page (its change woo-document-assessment-screen).
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
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Service\Decision\DecisionVisibilityInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Answers `GET /api/entity-relations?fileId=` with the file's relations as the caller may see them.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
class FileEntityRelationsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request            The request.
	 * @param EntityRelationMapperInterface $relations          The relation store.
	 * @param CallerFileAccess              $fileAccess         Whether the caller may open the file.
	 * @param DecisionVisibilityInterface   $decisionVisibility Masks a decision the caller may not see.
	 * @param IUserSession                  $userSession        The caller's session.
	 */
	public function __construct(
		IRequest $request,
		private readonly EntityRelationMapperInterface $relations,
		private readonly CallerFileAccess $fileAccess,
		private readonly DecisionVisibilityInterface $decisionVisibility,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The relations of a file the caller may open, with kept and rejected counts.
	 *
	 * Kept is a relation that will be (or was) redacted, rejected one a person
	 * chose to skip; a relation whose decision the caller may not see counts as
	 * neither. The access check runs before the store is read.
	 *
	 * @param int $fileId The Nextcloud file id, from the query string.
	 *
	 * @return JSONResponse 200 with `fileId`, `results`, `kept`, `rejected`; 400 without a file id; 403 when the caller may not open the file.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(int $fileId=0): JSONResponse {
		if ($fileId <= 0) {
			return new JSONResponse(
				data: ['error' => 'The query parameter fileId is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->fileAccess->mayOpen(fileId: $fileId) === false) {
			return new JSONResponse(
				data: ['error' => 'You may not read this file'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$userId = (string)$this->userSession->getUser()?->getUID();
		$results = $this->decisionVisibility->presentAll(
			relations: $this->relations->findByFileId($fileId),
			userId: $userId
		);

		return new JSONResponse(
			data: [
				'fileId' => $fileId,
				'results' => $results,
				'kept' => $this->countSkipped(results: $results, skipped: false),
				'rejected' => $this->countSkipped(results: $results, skipped: true),
			]
		);
	}//end index()

	/**
	 * Count the relations whose `skipAnonymization` is exactly the given value.
	 *
	 * @param list<array<string, mixed>> $results The presented relations.
	 * @param bool                       $skipped The value to count.
	 *
	 * @return int
	 */
	private function countSkipped(array $results, bool $skipped): int {
		$count = 0;
		foreach ($results as $result) {
			if (($result['skipAnonymization'] ?? null) === $skipped) {
				$count++;
			}
		}

		return $count;
	}//end countSkipped()
}//end class
