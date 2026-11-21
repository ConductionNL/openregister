<?php

/**
 * OpenRegister RedactionReviewMarkController
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
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\RedactionReviewMark;
use OCA\OpenRegister\Db\RedactionReviewMarkMapper;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Service\Anonymisation\RedactionRunFingerprint;
use OCA\OpenRegister\Service\Audit\TokenResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use Throwable;

/**
 * Records that a person checked a file's detection run.
 *
 * Only a signed-in person may: a call without a user session, or one made
 * with an app password, is refused. The mark names them, the moment and the
 * fingerprint of the run they checked.
 */
class RedactionReviewMarkController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName    The app name.
	 * @param IRequest                  $request    The request.
	 * @param IUserSession              $userSession The signed-in person.
	 * @param ISession                  $session    The session, to tell an app-password login apart.
	 * @param CallerFileAccess          $fileAccess Whether the caller may read the file.
	 * @param EntityRelationMapperInterface $relations  The file's detections.
	 * @param RedactionReviewMarkMapper $marks      Where marks are kept.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ISession $session,
		private readonly CallerFileAccess $fileAccess,
		private readonly EntityRelationMapperInterface $relations,
		private readonly RedactionReviewMarkMapper $marks,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Record a review mark on a file.
	 *
	 * @param int $fileId The file.
	 *
	 * @return JSONResponse 201 with the mark, 403 for a caller that is not a signed-in person, 404 when the file cannot be read.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
	 */
	#[NoAdminRequired]
	public function create(int $fileId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->isTokenLogin() === true) {
			return new JSONResponse(
				data: ['error' => 'person-required', 'message' => 'Only a signed-in person can check a document.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if ($this->fileAccess->mayOpen(fileId: $fileId) === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$mark = new RedactionReviewMark();
		$mark->setFileId($fileId);
		$mark->setRunFingerprint(RedactionRunFingerprint::of(detections: $this->relations->findDetectionsForReview(fileId: $fileId)));
		$mark->setCheckedBy($user->getUID());
		$mark->setCheckedAt(new DateTime());

		$stored = $this->marks->insert(entity: $mark);

		return new JSONResponse(data: $stored->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Whether this request was signed in with an app password rather than by a person in a session.
	 *
	 * A session that cannot be read counts as one: the mark names a person, so doubt refuses.
	 *
	 * @return bool True for an app-password (machine) login.
	 */
	private function isTokenLogin(): bool {
		try {
			$password = $this->session->get(TokenResolver::SESSION_KEY);
		} catch (Throwable $unreadable) {
			return true;
		}

		return (is_string($password) === true && $password !== '');
	}//end isTokenLogin()
}//end class
