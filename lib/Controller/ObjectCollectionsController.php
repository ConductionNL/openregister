<?php

/**
 * OpenRegister ObjectCollectionsController
 *
 * GET /api/objects/{register}/{schema}/{id}/collections: the Nextcloud
 * collections an object belongs to and the members the reader may access
 * (platform-collaboration-resources, task 1.5). POST to it and DELETE
 * .../collections/{collectionId} add the object to a collection or take it
 * out, for a person who may update it, on the audit trail (task 1.4).
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Collaboration\ObjectCollections;
use OCA\OpenRegister\Collaboration\ObjectMembership;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Lists an object's collections for the signed-in reader, and changes them for one who may update it.
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
 */
class ObjectCollectionsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     The request.
	 * @param ObjectOperationsInterface $objects     The object read path (RBAC and multitenancy on).
	 * @param ObjectCollections         $collections Reads the collections.
	 * @param IUserSession              $userSession The reader.
	 * @param ObjectMembership          $membership  Adds and removes the object, update right and audit included.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectOperationsInterface $objects,
		private readonly ObjectCollections $collections,
		private readonly IUserSession $userSession,
		private readonly ObjectMembership $membership,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The object's collections and the members the reader may access.
	 *
	 * The object is read first, as the reader, through the object read path: an
	 * object they may not read answers 404, like any unreadable object, and no
	 * collection is looked up for it.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id or uuid.
	 *
	 * @return JSONResponse `{results: [{id, name, members}]}`, 401 or 404.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		$reader = $this->userSession->getUser();
		if ($reader === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$object = $this->read(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['results' => $this->collections->forObject(object: $object, reader: $reader)]);
	}//end index()

	/**
	 * Add the object to a collection, for a person who may update it.
	 *
	 * The object is read as the caller first, so an object they may not read
	 * answers 404 like a missing one, before any right to update is asked.
	 *
	 * @param string $register     The register id or slug.
	 * @param string $schema       The schema id or slug.
	 * @param string $id           The object id or uuid.
	 * @param int    $collectionId The collection to add it to.
	 *
	 * @return JSONResponse The object's collections after the change, or 400, 401, 403 or 404.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-membership-is-changed-by-anyone-who-may-update-the-object-and-is-readable-from-it-req-pcr-002
	 */
	#[NoAdminRequired]
	public function create(string $register, string $schema, string $id, int $collectionId = 0): JSONResponse {
		return $this->changeMembership(register: $register, schema: $schema, id: $id, collectionId: $collectionId, adding: true);
	}//end create()

	/**
	 * Take the object out of a collection, for a person who may update it.
	 *
	 * @param string $register     The register id or slug.
	 * @param string $schema       The schema id or slug.
	 * @param string $id           The object id or uuid.
	 * @param int    $collectionId The collection to take it out of.
	 *
	 * @return JSONResponse The object's collections after the change, or 400, 401, 403 or 404.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-membership-is-changed-by-anyone-who-may-update-the-object-and-is-readable-from-it-req-pcr-002
	 */
	#[NoAdminRequired]
	public function destroy(string $register, string $schema, string $id, int $collectionId): JSONResponse {
		return $this->changeMembership(register: $register, schema: $schema, id: $id, collectionId: $collectionId, adding: false);
	}//end destroy()

	/**
	 * Read the object, change its membership, and answer with its collections.
	 *
	 * @param string $register     The register id or slug.
	 * @param string $schema       The schema id or slug.
	 * @param string $id           The object id or uuid.
	 * @param int    $collectionId The collection.
	 * @param bool   $adding       True to add, false to remove.
	 *
	 * @return JSONResponse
	 */
	private function changeMembership(string $register, string $schema, string $id, int $collectionId, bool $adding): JSONResponse {
		$actor = $this->userSession->getUser();
		if ($actor === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$object = $this->read(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		if ($collectionId <= 0) {
			return new JSONResponse(['message' => 'A collectionId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($adding === true) {
			$outcome = $this->membership->add(object: $object, collectionId: $collectionId, actor: $actor);
		} else {
			$outcome = $this->membership->remove(object: $object, collectionId: $collectionId, actor: $actor);
		}

		return match ($outcome) {
			ObjectMembership::NOT_JOINABLE => new JSONResponse(['message' => 'Objects of this schema do not join collections'], Http::STATUS_BAD_REQUEST),
			ObjectMembership::FORBIDDEN => new JSONResponse(
				['message' => 'Changing the collections of this object needs the right to update it'],
				Http::STATUS_FORBIDDEN
			),
			ObjectMembership::NO_COLLECTION => new JSONResponse(['message' => 'Collection not found'], Http::STATUS_NOT_FOUND),
			default => new JSONResponse(['results' => $this->collections->forObject(object: $object, reader: $actor)]),
		};
	}//end changeMembership()

	/**
	 * The object as the signed-in caller may read it, or null.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id or uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function read(string $register, string $schema, string $id): ?ObjectEntity {
		try {
			$object = $this->objects->find(
				id: $id,
				options: new ObjectFindOptions(register: $register, schema: $schema, render: false, audit: false),
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			return null;
		}

		return $object;
	}//end read()
}//end class
