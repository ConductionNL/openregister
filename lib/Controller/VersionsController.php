<?php

/**
 * Versions Controller: named drafts of an object.
 *
 * Create, list, read, update and discard the named drafts of one object under
 * `/api/objects/{register}/{schema}/{id}/versions` (records-draft-versions
 * task 2.2). A draft is a delta beside the published object; reading one
 * answers the object with the delta applied. Every decision about who may see
 * or change a draft is DraftService's.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/records-draft-versions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Object\DraftService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The versions endpoints. A record the caller may not read, and a draft the
 * caller may not see, both answer 404, so the routes cannot be used to probe
 * which ids or keys exist.
 *
 * @spec openspec/changes/records-draft-versions
 */
class VersionsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     The request.
	 * @param RegisterMapperInterface   $registers   Resolves the register.
	 * @param SchemaMapperInterface     $schemas     Resolves the schema.
	 * @param ObjectOperationsInterface $objects     Reads the object under the caller's RBAC.
	 * @param DraftService              $drafts      Owns the drafts and who may touch them.
	 * @param IUserSession              $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly ObjectOperationsInterface $objects,
		private readonly DraftService $drafts,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The drafts of an object the caller may see.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/records-draft-versions
	 */
	#[NoAdminRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->withObject(
			route: [$register, $schema, $id],
			action: fn (ObjectEntity $object, Schema $schemaEntity, string $caller): JSONResponse => new JSONResponse(
				[
					'results' => array_map(
						[self::class, 'summary'],
						$this->drafts->visibleDrafts(object: $object, schema: $schemaEntity, caller: $caller)
					),
				]
			)
		);
	}//end index()

	/**
	 * Create a draft: `key`, optional `name`, and the changed fields in `object`.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id.
	 *
	 * @return JSONResponse 201 with the draft; 403 without the right to change the object; 422 for a bad or taken key.
	 *
	 * @spec openspec/changes/records-draft-versions
	 */
	#[NoAdminRequired]
	public function create(string $register, string $schema, string $id): JSONResponse {
		return $this->withObject(
			route: [$register, $schema, $id],
			action: function (ObjectEntity $object, Schema $schemaEntity, string $caller): JSONResponse {
				if ($this->drafts->mayCreate(object: $object, schema: $schemaEntity, caller: $caller) === false) {
					return self::error(message: 'Creating a draft needs the right to change this object.', status: Http::STATUS_FORBIDDEN);
				}

				try {
					$draft = $this->drafts->create(
						object: $object,
						key: (string)$this->request->getParam('key', ''),
						name: (string)$this->request->getParam('name', ''),
						changes: $this->changes(),
						actor: $caller
					);
				} catch (InvalidArgumentException $e) {
					return self::error(message: $e->getMessage(), status: Http::STATUS_UNPROCESSABLE_ENTITY);
				}

				return new JSONResponse(self::summary(draft: $draft), Http::STATUS_CREATED);
			}
		);
	}//end create()

	/**
	 * The object as the draft would make it, marked with the draft key.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id.
	 * @param string $key      The draft key.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/records-draft-versions
	 */
	#[NoAdminRequired]
	public function show(string $register, string $schema, string $id, string $key): JSONResponse {
		return $this->withDraft(
			route: [$register, $schema, $id, $key],
			action: fn (DraftVersion $draft, ObjectEntity $object): JSONResponse => new JSONResponse(
				$this->drafts->merged(object: $object, draft: $draft) + ['@self' => ['id' => (string)$object->getUuid(), '_version' => $key]]
			)
		);
	}//end show()

	/**
	 * Add changes to a draft; only the fields that differ from the published object are kept.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id.
	 * @param string $key      The draft key.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/records-draft-versions
	 */
	#[NoAdminRequired]
	public function update(string $register, string $schema, string $id, string $key): JSONResponse {
		return $this->withDraft(
			route: [$register, $schema, $id, $key],
			action: function (DraftVersion $draft, ObjectEntity $object, Schema $schemaEntity, string $caller): JSONResponse {
				$saved = $this->drafts->update(draft: $draft, object: $object, schema: $schemaEntity, changes: $this->changes(), caller: $caller);

				return new JSONResponse(self::summary(draft: $saved));
			}
		);
	}//end update()

	/**
	 * Discard a draft. The published object is not touched.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id.
	 * @param string $key      The draft key.
	 *
	 * @return JSONResponse 204.
	 *
	 * @spec openspec/changes/records-draft-versions
	 */
	#[NoAdminRequired]
	public function destroy(string $register, string $schema, string $id, string $key): JSONResponse {
		return $this->withDraft(
			route: [$register, $schema, $id, $key],
			action: function (DraftVersion $draft, ObjectEntity $object, Schema $schemaEntity, string $caller): JSONResponse {
				$this->drafts->discard(draft: $draft, object: $object, schema: $schemaEntity, caller: $caller);

				return new JSONResponse(null, Http::STATUS_NO_CONTENT);
			}
		);
	}//end destroy()

	/**
	 * Run an action on a draft the caller may see; 404 otherwise.
	 *
	 * @param array{0: string, 1: string, 2: string, 3: string} $route  Register, schema, object id and draft key.
	 * @param callable                                           $action The action.
	 *
	 * @return JSONResponse
	 */
	private function withDraft(array $route, callable $action): JSONResponse {
		return $this->withObject(
			route: [$route[0], $route[1], $route[2]],
			action: function (ObjectEntity $object, Schema $schemaEntity, string $caller) use ($route, $action): JSONResponse {
				$draft = $this->drafts->find(object: $object, schema: $schemaEntity, key: $route[3], caller: $caller);
				if ($draft === null) {
					return self::error(message: 'This draft does not exist.', status: Http::STATUS_NOT_FOUND);
				}

				return $action($draft, $object, $schemaEntity, $caller);
			}
		);
	}//end withDraft()

	/**
	 * Run an action on an object the signed-in caller may read; 401 or 404 otherwise.
	 *
	 * @param array{0: string, 1: string, 2: string} $route  Register, schema and object id.
	 * @param callable                                $action The action.
	 *
	 * @return JSONResponse
	 */
	private function withObject(array $route, callable $action): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return self::error(message: 'Please sign in.', status: Http::STATUS_UNAUTHORIZED);
		}

		$schemaEntity = null;
		try {
			$registerEntity = $this->registers->find(id: $route[0], _rbac: false, _multitenancy: false);
			$schemaEntity = $this->schemas->find(id: $route[1], _rbac: false, _multitenancy: false);
			$object = $this->objects->find(
				id: $route[2],
				options: new ObjectFindOptions(register: $registerEntity, schema: $schemaEntity, render: false, audit: false)
			);
		} catch (Throwable) {
			$object = null;
		}

		if (($object instanceof ObjectEntity) === false || $schemaEntity === null) {
			return self::error(message: 'This object does not exist.', status: Http::STATUS_NOT_FOUND);
		}

		return $action($object, $schemaEntity, $user->getUID());
	}//end withObject()

	/**
	 * The changed fields the request carries in `object`.
	 *
	 * @return array<string, mixed>
	 */
	private function changes(): array {
		$changes = $this->request->getParam('object');
		if (is_array($changes) === false) {
			return [];
		}

		return $changes;
	}//end changes()

	/**
	 * A draft as listed: no delta, only what identifies it.
	 *
	 * @param DraftVersion $draft The draft.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary(DraftVersion $draft): array {
		return [
			'key' => $draft->getDraftKey(),
			'name' => $draft->getName(),
			'createdBy' => $draft->getCreatedBy(),
			'baseVersion' => $draft->getBaseVersion(),
			'created' => $draft->getCreated()?->format('c'),
			'updated' => $draft->getUpdated()?->format('c'),
		];
	}//end summary()

	/**
	 * An error answer.
	 *
	 * @param string $message The message.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private static function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['error' => $message], $status);
	}//end error()
}//end class
