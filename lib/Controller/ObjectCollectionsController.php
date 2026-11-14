<?php

/**
 * OpenRegister ObjectCollectionsController
 *
 * GET /api/objects/{register}/{schema}/{id}/collections: the Nextcloud
 * collections an object belongs to and the members the reader may access
 * (platform-collaboration-resources, task 1.5).
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

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Collaboration\ObjectCollections;
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
 * Lists an object's collections for the signed-in reader.
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
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectOperationsInterface $objects,
		private readonly ObjectCollections $collections,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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

		try {
			$object = $this->objects->find(
				id: $id,
				options: new ObjectFindOptions(register: $register, schema: $schema, render: false, audit: false),
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['results' => $this->collections->forObject(object: $object, reader: $reader)]);
	}//end index()
}//end class
