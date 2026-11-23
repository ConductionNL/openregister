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

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Schema\PreviewUrlTemplate;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DraftConflictException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\Core\HeldChangeCodes;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Object\DraftPromoter;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkTerms;
use OCA\OpenRegister\Service\Sharing\LinkMintRequest;
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
	 * How long a preview link lives when the request names no expiry.
	 *
	 * @var string
	 */
	private const PREVIEW_LIFETIME = '+24 hours';

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     The request.
	 * @param RegisterMapperInterface   $registers   Resolves the register.
	 * @param SchemaMapperInterface     $schemas     Resolves the schema.
	 * @param ObjectOperationsInterface $objects     Reads the object under the caller's RBAC.
	 * @param DraftService              $drafts      Owns the drafts and who may touch them.
	 * @param IUserSession              $userSession The caller.
	 * @param DraftPromoter             $promoter    Makes a draft the published version.
	 * @param AccessLinkServiceInterface $links      Mints the preview link.
	 */
	public function __construct(
		IRequest $request,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly ObjectOperationsInterface $objects,
		private readonly DraftService $drafts,
		private readonly IUserSession $userSession,
		private readonly DraftPromoter $promoter,
		private readonly AccessLinkServiceInterface $links,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * Promote a draft: it becomes the published object and is deleted.
	 *
	 * `force=true` promotes over conflicting changes and is for administrators only.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id.
	 * @param string $key      The draft key.
	 *
	 * @return JSONResponse 200 with the saved object; 403 without the right; 409 with the conflicting fields; 422 when the save refuses.
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-drafts-must-be-promotable-to-published-version
	 */
	#[NoAdminRequired]
	public function promote(string $register, string $schema, string $id, string $key): JSONResponse {
		return $this->withDraft(
			route: [$register, $schema, $id, $key],
			action: function (DraftVersion $draft, ObjectEntity $object, Schema $schemaEntity, string $caller): JSONResponse {
				try {
					$saved = $this->promoter->promote(
						draft: $draft,
						object: $object,
						schema: $schemaEntity,
						caller: $caller,
						force: filter_var($this->request->getParam('force', false), FILTER_VALIDATE_BOOLEAN)
					);
				} catch (NotAuthorizedException $e) {
					return self::error(message: $e->getMessage(), status: Http::STATUS_FORBIDDEN);
				} catch (DraftConflictException $e) {
					return new JSONResponse(['error' => $e->getMessage(), 'conflicts' => $e->getConflicts()], Http::STATUS_CONFLICT);
				} catch (ValidationException | CustomValidationException $e) {
					return $this->objects->handleValidationException(exception: $e);
				} catch (HookStoppedException $e) {
					return self::stopped(exception: $e);
				}

				return new JSONResponse($saved->jsonSerialize());
			}
		);
	}//end promote()

	/**
	 * Make a preview link for a draft: read-only, scoped to this draft, expiring (24 hours unless `expiresAt` says otherwise).
	 *
	 * The answer is the link, plus `previewUrl` filled from the schema's
	 * template when the schema declares one, else null.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id.
	 * @param string $key      The draft key.
	 *
	 * @return JSONResponse 201 with the link; 400 for an unusable expiry; 404 for a draft the caller may not see.
	 *
	 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
	 */
	#[NoAdminRequired]
	public function preview(string $register, string $schema, string $id, string $key): JSONResponse {
		return $this->withDraft(
			route: [$register, $schema, $id, $key],
			action: function (DraftVersion $draft, ObjectEntity $object, Schema $schemaEntity, string $caller): JSONResponse {
				$expiresAt = trim((string)$this->request->getParam('expiresAt', ''));
				if ($expiresAt === '') {
					$expiresAt = (new DateTime(self::PREVIEW_LIFETIME))->format(DateTime::ATOM);
				}

				try {
					$link = $this->links->mint(
						new LinkMintRequest(
							minter: $caller,
							subjectType: AccessLink::SUBJECT_OBJECT_DRAFT,
							subjectId: (string)$draft->getUuid(),
							terms: new AccessLinkTerms(capabilities: [AccessLink::CAP_READ], expiresAt: $expiresAt, label: 'Preview ' . (string)$draft->getDraftKey())
						)
					);
				} catch (InvalidArgumentException $e) {
					return self::error(message: $e->getMessage(), status: Http::STATUS_BAD_REQUEST);
				}

				$link['previewUrl'] = null;
				$template = $schemaEntity->getPreviewUrl();
				if ($template !== null) {
					$link['previewUrl'] = PreviewUrlTemplate::fill(
						template: $template,
						uuid: (string)$object->getUuid(),
						version: (string)$draft->getDraftKey(),
						token: (string)($link['anchor'] ?? '')
					);
				}

				return new JSONResponse($link, Http::STATUS_CREATED);
			}
		);
	}//end preview()

	/**
	 * The answer to a promotion a hook stopped: 202 when a change gate holds it for approval, 422 otherwise.
	 *
	 * @param HookStoppedException $exception The refusal.
	 *
	 * @return JSONResponse
	 */
	private static function stopped(HookStoppedException $exception): JSONResponse {
		$errors = $exception->getErrors();
		if (($errors['code'] ?? null) === HeldChangeCodes::PENDING) {
			return new JSONResponse(
				['message' => $exception->getMessage(), 'pendingChange' => (string)($errors['pendingChange'] ?? '')],
				Http::STATUS_ACCEPTED
			);
		}

		return new JSONResponse(['error' => $exception->getMessage(), 'errors' => $errors], Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end stopped()

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
	 * A draft as listed: no delta, only what identifies it and the names of the fields it changes.
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
			'changedFields' => array_map('strval', array_keys($draft->getDelta() ?? [])),
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
