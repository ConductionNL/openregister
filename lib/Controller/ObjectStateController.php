<?php

/**
 * Class ObjectStateController
 *
 * The four state verbs an object answers to: archive, restore, freeze and
 * unfreeze.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\ArchiveNotOfferedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\ArchiveHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Class ObjectStateController
 *
 * A separate controller rather than four more methods on ObjectsController,
 * which is already past two and a half thousand lines and is the file every
 * object-shaped change edits at once.
 *
 * Every method here refuses an anonymous caller before anything else. The
 * per-object `update` check lives in {@see ArchiveHandler}, so the two doors
 * to the same state cannot disagree about who may open them.
 *
 * @psalm-suppress UnusedClass
 */
class ObjectStateController extends Controller {

	/**
	 * Constructor for the ObjectStateController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param ArchiveHandler $archiveHandler The archive and freeze verbs.
	 * @param IUserSession $userSession The user session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ArchiveHandler $archiveHandler,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Archive an object.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The id or uuid of the object to archive.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The stored archive marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function archive(string $register, string $schema, string $id): JSONResponse {
		$refusal = $this->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$result = $this->archiveHandler->archive(
				identifier: $id,
				reason: $this->reasonFromRequest(),
				register: $register,
				schema: $schema
			);

			return new JSONResponse(data: $result);
		} catch (\Throwable $e) {
			return $this->refusalResponse(exception: $e);
		}
	}//end archive()

	/**
	 * Restore an object from the archive.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The id or uuid of the object to restore.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The cleared archive marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unarchive(string $register, string $schema, string $id): JSONResponse {
		$refusal = $this->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$result = $this->archiveHandler->unarchive(
				identifier: $id,
				reason: $this->reasonFromRequest(),
				register: $register,
				schema: $schema
			);

			return new JSONResponse(data: $result);
		} catch (\Throwable $e) {
			return $this->refusalResponse(exception: $e);
		}
	}//end unarchive()

	/**
	 * Freeze an object.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The id or uuid of the object to freeze.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The stored freeze marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function freeze(string $register, string $schema, string $id): JSONResponse {
		$refusal = $this->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$result = $this->archiveHandler->freeze(
				identifier: $id,
				reason: $this->reasonFromRequest(),
				register: $register,
				schema: $schema
			);

			return new JSONResponse(data: $result);
		} catch (\Throwable $e) {
			return $this->refusalResponse(exception: $e);
		}
	}//end freeze()

	/**
	 * Unfreeze an object.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The id or uuid of the object to unfreeze.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The cleared freeze marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unfreeze(string $register, string $schema, string $id): JSONResponse {
		$refusal = $this->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$result = $this->archiveHandler->unfreeze(
				identifier: $id,
				reason: $this->reasonFromRequest(),
				register: $register,
				schema: $schema
			);

			return new JSONResponse(data: $result);
		} catch (\Throwable $e) {
			return $this->refusalResponse(exception: $e);
		}
	}//end unfreeze()

	/**
	 * Refuse an anonymous caller.
	 *
	 * `#[NoAdminRequired]` means "not only admins", never "no account", and
	 * reading it the other way is how an endpoint ends up reachable by nobody
	 * in particular. All four verbs write, so all four need a name to write.
	 *
	 * @return JSONResponse|null A 401, or null when a user is signed in.
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() !== null) {
			return null;
		}

		return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
	}//end requireUser()

	/**
	 * Read the optional reason from the request body.
	 *
	 * @return string|null The reason, or null when none was sent.
	 */
	private function reasonFromRequest(): ?string {
		$reason = $this->request->getParam('reason');

		if (is_string($reason) === false || trim($reason) === '') {
			return null;
		}

		return trim($reason);
	}//end reasonFromRequest()

	/**
	 * Map a refusal to its status.
	 *
	 * Each refusal keeps its own status rather than collapsing into a 500:
	 * 422 when the schema does not offer archiving, 403 when the caller lacks
	 * `update`, 404 when there is no such object. A caller cannot act on
	 * "something went wrong", and the three are fixed in three different
	 * places.
	 *
	 * @param \Throwable $exception The refusal.
	 *
	 * @return JSONResponse The mapped response.
	 */
	private function refusalResponse(\Throwable $exception): JSONResponse {
		if ($exception instanceof ArchiveNotOfferedException) {
			return new JSONResponse(
				data: ['error' => $exception->getMessage()],
				statusCode: ArchiveNotOfferedException::HTTP_STATUS
			);
		}

		if ($exception instanceof NotAuthorizedException) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		}

		if ($exception instanceof DoesNotExistException) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		// SEC-CTRL-7: never leak internal exception detail on a 500.
		return new JSONResponse(data: ['error' => 'Internal server error'], statusCode: 500);
	}//end refusalResponse()
}//end class
