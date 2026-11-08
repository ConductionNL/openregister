<?php

/**
 * Controller for the entity-relations decision-metadata PATCH endpoint.
 *
 * Exposes `PATCH /api/entity-relations/{id}` for operators to set the
 * two decision-metadata fields on an `EntityRelation` row:
 *
 *   - `bases`              (?array)  — legal grondslagen for redaction
 *   - `skipAnonymization`  (bool)    — opt-out from the next anonymise pass
 *
 * Per the `entity-relation-grondslagen` change, the post-hoc system fields
 * `anonymized` and `anonymizedValue` are intentionally NOT in the whitelist;
 * those are written by `EntityRelationMapper::markAsAnonymizedWithPlaceholders` during the
 * redaction code path.
 *
 * The endpoint is a thin wrapper over `EntityRelationMapper::updateDecisionMetadata`
 * — whitelist enforcement, shape validation, diff awareness, and audit-trail
 * emission all live in the mapper. This controller resolves the acting
 * `IUser`, runs the authorization check (write-access to the relation's
 * parent file or object), and maps thrown exceptions to HTTP responses.
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
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 *       "A PATCH /api/entity-relations/{id} endpoint MUST exist with a decision-only field whitelist"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\EntityRelationsControllerServices;
use OCA\OpenRegister\Controller\Trait\RequiresJsonBodyTrait;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Service\Decision\DecisionOwnedException;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles the PATCH endpoint for entity-relation decision metadata.
 */
class EntityRelationsController extends Controller {
	use RequiresJsonBodyTrait;

	/**
	 * Relation mapper.
	 *
	 * @var EntityRelationMapperInterface
	 */
	private readonly EntityRelationMapperInterface $entityRelationMapper;

	/**
	 * Session user accessor.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Nextcloud root folder (for file lookups).
	 *
	 * @var IRootFolder
	 */
	private readonly IRootFolder $rootFolder;

	/**
	 * RBAC verdict for object-bound relations.
	 *
	 * @var PermissionHandlerInterface
	 */
	private readonly PermissionHandlerInterface $permissionHandler;

	/**
	 * ObjectEntity loader (for object-bound authz).
	 *
	 * @var MagicObjectFinderInterface
	 */
	private readonly MagicObjectFinderInterface $magicMapper;

	/**
	 * Schema loader (for hasPermission inputs).
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register loader (for object lookup disambiguation).
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * EmailLink loader (for email-bound authz indirection).
	 *
	 * @var EmailLinkMapperInterface
	 */
	private readonly EmailLinkMapperInterface $emailLinkMapper;

	/**
	 * Structured log sink.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Takes the decision as its own object, under the ownership rule.
	 *
	 * @var RelationDecisionService
	 */
	private readonly RelationDecisionService $relationDecisions;

	/**
	 * Masks a decision the caller may not see (REQ-ROD-002).
	 *
	 * @var DecisionVisibility
	 */
	private readonly DecisionVisibility $decisionVisibility;

	/**
	 * Constructor.
	 *
	 * @param string $appName Nextcloud app name.
	 * @param IRequest $request Current request.
	 * @param EntityRelationsControllerServices $services The entity relations controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		EntityRelationsControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->entityRelationMapper = $services->mappers->entityRelationMapper;
		$this->userSession = $services->platform->userSession;
		$this->rootFolder = $services->platform->rootFolder;
		$this->permissionHandler = $services->domain->permissionHandler;
		$this->magicMapper = $services->mappers->magicMapper;
		$this->schemaMapper = $services->mappers->schemaMapper;
		$this->registerMapper = $services->mappers->registerMapper;
		$this->emailLinkMapper = $services->links->emailLinkMapper;
		$this->logger = $services->platform->logger;
		$this->relationDecisions = $services->domain->relationDecisions;
		$this->decisionVisibility = $services->domain->decisionVisibility;
	}//end __construct()

	/**
	 * PATCH /api/entity-relations/{id}.
	 *
	 * Body: { bases?: ?array<string>, skipAnonymization?: bool,
	 * decisionNote?: ?string } — any other key triggers HTTP 400.
	 *
	 * The decision is recorded as its own object (decision 197) that the
	 * relation links; under `decider` ownership a decision someone else took
	 * answers 403 `decision-owned` naming who decided and when (REQ-ROD-001).
	 *
	 * @param int $id Relation row id.
	 *
	 * @return JSONResponse Updated relation (200) or a structured error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function update(int $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'unauthenticated'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$relation = $this->findRelation(id: $id);
		if ($relation instanceof JSONResponse) {
			return $relation;
		}

		$refusal = $this->refusePatch(relation: $relation, user: $user, id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->decideRelation(relation: $relation, user: $user, id: $id);
	}//end update()

	/**
	 * Load the relation row, answering 404 when it does not exist.
	 *
	 * @param int $id Relation row id.
	 *
	 * @return EntityRelation|JSONResponse The relation, or the 404 answer.
	 */
	private function findRelation(int $id): EntityRelation|JSONResponse {
		try {
			return $this->entityRelationMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				data: ['error' => 'not_found', 'id' => $id],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}
	}//end findRelation()

	/**
	 * Refuse the PATCH when the actor may not write the subject, or sends no JSON.
	 *
	 * The write check runs first: a caller without write access learns nothing
	 * about the content-type rules.
	 *
	 * @param EntityRelation $relation The relation.
	 * @param IUser          $user     The acting user.
	 * @param int            $id       Relation row id.
	 *
	 * @return JSONResponse|null The 403 or 415 answer, or null when the PATCH may proceed.
	 */
	private function refusePatch(EntityRelation $relation, IUser $user, int $id): ?JSONResponse {
		if ($this->actorCanWriteRelationSubject(relation: $relation, userId: $user->getUID()) === false) {
			$this->logger->info(
				message: '[EntityRelationsController] PATCH denied — no write-access to relation subject',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'relation_id' => $id,
					'actor' => $user->getUID(),
				]
			);
			return new JSONResponse(
				data: ['error' => 'forbidden', 'reason' => 'no_write_access_to_relation_subject'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		// Require explicit application/json — anything else and Nextcloud's
		// body parser may silently drop unknown content-types or coerce
		// form-encoded array values inconsistently. Decision-time
		// mutations on legal grondslagen must not depend on content-type
		// heuristics; clients send JSON or get 415.
		return $this->refuseNonJsonBody(route: 'PATCH /api/entity-relations/{id}');
	}//end refusePatch()

	/**
	 * Record the decision the body carries and answer with the updated relation.
	 *
	 * @param EntityRelation $relation The relation.
	 * @param IUser          $user     The acting user.
	 * @param int            $id       Relation row id.
	 *
	 * @return JSONResponse 200 with the relation, or 403 / 400 / 500.
	 */
	private function decideRelation(EntityRelation $relation, IUser $user, int $id): JSONResponse {
		$body = $this->request->getParams();
		// Strip framework-injected keys; only the JSON body fields remain candidates.
		unset($body['id'], $body['_route']);

		try {
			$decided = $this->relationDecisions->decide(relation: $relation, body: $body, actor: $user);
		} catch (DecisionOwnedException $e) {
			return new JSONResponse(data: $e->getRefusal(), statusCode: Http::STATUS_FORBIDDEN);
		} catch (CustomValidationException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'details' => $e->getErrors()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[EntityRelationsController] PATCH failed: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'relation_id' => $id,
					'error' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: ['error' => 'internal_error'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		$data = $decided['relation']->jsonSerialize();
		$data['decisionRecord'] = $decided['decision']?->jsonSerialize();
		// The caller just took this decision, so it is theirs to see; masked
		// all the same so no relation leaves OpenRegister unfiltered.
		$data = $this->decisionVisibility->present(relation: $decided['relation'], userId: $user->getUID(), serialised: $data);

		return new JSONResponse(
			data: $data,
			statusCode: Http::STATUS_OK
		);
	}//end decideRelation()

	/**
	 * Check that the acting user can write the relation's parent file or object.
	 *
	 * Resolution order (per `entity-relation-grondslagen` design.md §authz):
	 *   1. If the relation has `fileId` — the file MUST be present in the
	 *      acting user's user-folder and `isUpdateable()` MUST return true.
	 *   2. Else if the relation has `objectId` — the caller MUST hold
	 *      `update` on the underlying ObjectEntity per the same
	 *      PermissionHandlerInterface verdict the rest of OR uses for object writes.
	 *   3. Else if the relation has `emailId` — the email's parent
	 *      ObjectEntity is looked up via EmailLink, then the same
	 *      object-update verdict applies. Email links without a parent
	 *      object cannot satisfy an authz check and are denied.
	 *   4. Otherwise — deny.
	 *
	 * Every denial path is logged at `info` (expected operator denials)
	 * or `warning` (lookup failures) so unexpected drift is visible.
	 *
	 * @param EntityRelation $relation Relation row.
	 * @param string $userId Acting user UID.
	 *
	 * @return bool True when the acting user may write.
	 */
	private function actorCanWriteRelationSubject(EntityRelation $relation, string $userId): bool {
		$fileId = $relation->getFileId();
		if ($this->isPositiveId(id: $fileId) === true) {
			return $this->canWriteFile(fileId: $fileId, userId: $userId);
		}

		$objectId = $relation->getObjectId();
		if ($this->isPositiveId(id: $objectId) === true) {
			return $this->canUpdateObject(
				objectId: $objectId,
				registerId: $relation->getRegisterId(),
				schemaId: $relation->getSchemaId(),
				userId: $userId
			);
		}

		$emailId = $relation->getEmailId();
		if ($this->isPositiveId(id: $emailId) === true) {
			return $this->canUpdateEmailParentObject(emailId: $emailId, userId: $userId);
		}

		return false;
	}//end actorCanWriteRelationSubject()

	/**
	 * Whether a subject id is set: not null and above zero.
	 *
	 * @param int|null $id The id.
	 *
	 * @return bool
	 */
	private function isPositiveId(?int $id): bool {
		return $id !== null && $id > 0;
	}//end isPositiveId()

	/**
	 * File-bound authz: the file MUST be reachable in the acting user's
	 * user-folder and writable (isUpdateable). Mirrors how the rest of
	 * OR's per-file authz reads.
	 *
	 * @param int $fileId Nextcloud file node id.
	 * @param string $userId Acting user UID.
	 *
	 * @return bool
	 */
	private function canWriteFile(int $fileId, string $userId): bool {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($fileId);
			if (empty($nodes) === true) {
				return false;
			}

			$node = $nodes[0];
			if (($node instanceof File) === false) {
				return false;
			}

			return $node->isUpdateable();
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[EntityRelationsController] File-bound auth check raised exception, denying',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'file_id' => $fileId,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}//end try
	}//end canWriteFile()

	/**
	 * Object-bound authz: caller MUST hold `update` permission on the
	 * specific ObjectEntity. Reuses `PermissionHandlerInterface::hasPermission`
	 * — the same verdict the rest of OR runs for object writes, so this
	 * PATCH cannot grant access the saveObject path would have denied.
	 *
	 * Lookup uses register + schema disambiguation when both are
	 * available (the multi-table magic schema means object_id is not
	 * globally unique). When either is missing on the relation, falls
	 * back to the magic-mapper's cross-table find, which yields
	 * `DoesNotExistException` if no match — denied.
	 *
	 * @param int $objectId Object row id on its magic table.
	 * @param string|null $registerId Register identifier (uuid or slug).
	 * @param string|null $schemaId Schema identifier (uuid or slug).
	 * @param string $userId Acting user UID.
	 *
	 * @return bool
	 */
	private function canUpdateObject(
		int $objectId,
		string|null $registerId,
		string|null $schemaId,
		string $userId,
	): bool {
		$object = $this->resolveRelationObject(objectId: $objectId, registerId: $registerId, schemaId: $schemaId);
		if ($object === null) {
			return false;
		}

		$resolvedSchemaId = (string)$object->getSchema();
		if ($schemaId !== null) {
			$resolvedSchemaId = $schemaId;
		}

		$resolvedSchema = $this->loadSchemaOrDeny(
			schemaId: $resolvedSchemaId,
			message: '[EntityRelationsController] Object-bound auth check could not load schema, denying',
			context: ['object_id' => $objectId, 'schema_id' => $resolvedSchemaId]
		);
		if ($resolvedSchema === null) {
			return false;
		}

		return $this->permissionHandler->hasPermission(
			schema: $resolvedSchema,
			action: 'update',
			userId: $userId,
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end canUpdateObject()

	/**
	 * Resolve the relation's object, disambiguated by register and schema when given.
	 *
	 * @param int         $objectId   Object row id on its magic table.
	 * @param string|null $registerId Register identifier (uuid or slug).
	 * @param string|null $schemaId   Schema identifier (uuid or slug).
	 *
	 * @return ObjectEntity|null The object, or null (logged) when any lookup fails.
	 */
	private function resolveRelationObject(int $objectId, string|null $registerId, string|null $schemaId): ObjectEntity|null {
		try {
			$register = null;
			if ($registerId !== null) {
				$register = $this->registerMapper->find($registerId);
			}

			$schema = null;
			if ($schemaId !== null) {
				$schema = $this->schemaMapper->find($schemaId);
			}

			return $this->magicMapper->find(
				identifier: $objectId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[EntityRelationsController] Object-bound auth check could not resolve object, denying',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'object_id' => $objectId,
					'register_id' => $registerId,
					'schema_id' => $schemaId,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end resolveRelationObject()

	/**
	 * Load the schema the permission verdict is taken against.
	 *
	 * @param string $schemaId The schema identifier.
	 * @param string $message  The warning logged when it does not load.
	 * @param array  $context  The subject keys logged with that warning.
	 *
	 * @return Schema|null The schema, or null (logged) when it does not load.
	 */
	private function loadSchemaOrDeny(string $schemaId, string $message, array $context): ?Schema {
		try {
			return $this->schemaMapper->find($schemaId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: $message,
				context: array_merge(
					['file' => __FILE__, 'line' => __LINE__],
					$context,
					['error' => $e->getMessage()]
				)
			);
			return null;
		}
	}//end loadSchemaOrDeny()

	/**
	 * Email-bound authz: emails are linked to an ObjectEntity via
	 * EmailLink, so the operator must hold `update` on the parent
	 * object. Email links without a parent object cannot satisfy
	 * authz and are denied (orphan/system-generated links).
	 *
	 * @param int $emailId EmailLink row id.
	 * @param string $userId Acting user UID.
	 *
	 * @return bool
	 */
	private function canUpdateEmailParentObject(int $emailId, string $userId): bool {
		$emailLink = $this->loadEmailLink(emailId: $emailId);
		if ($emailLink === null) {
			return false;
		}

		$parentObjectUuid = $emailLink->getObjectUuid();
		$parentRegisterId = $emailLink->getRegisterId();
		// EmailLink's `@method string getObjectUuid()` claims non-nullable,
		// but the underlying column is `?string`. Guard via empty() so we
		// catch both null and empty-string parents.
		if (empty($parentObjectUuid) === true) {
			$this->logger->info(
				message: '[EntityRelationsController] Email-bound PATCH denied — EmailLink has no parent object',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'email_id' => $emailId,
					'actor' => $userId,
				]
			);
			return false;
		}

		$object = $this->resolveEmailParent(
			emailId: $emailId,
			parentObjectUuid: $parentObjectUuid,
			parentRegisterId: $parentRegisterId
		);
		if ($object === null) {
			return false;
		}

		$schema = $this->loadSchemaOrDeny(
			schemaId: (string)$object->getSchema(),
			message: '[EntityRelationsController] Email-bound auth check could not load parent schema, denying',
			context: ['email_id' => $emailId, 'schema_id' => $object->getSchema()]
		);
		if ($schema === null) {
			return false;
		}

		return $this->permissionHandler->hasPermission(
			schema: $schema,
			action: 'update',
			userId: $userId,
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end canUpdateEmailParentObject()

	/**
	 * Load the email link a relation hangs off.
	 *
	 * @param int $emailId EmailLink row id.
	 *
	 * @return EmailLink|null The link, or null (logged) when it does not load.
	 */
	private function loadEmailLink(int $emailId): ?EmailLink {
		try {
			return $this->emailLinkMapper->find($emailId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[EntityRelationsController] Email-bound auth check could not load EmailLink, denying',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'email_id' => $emailId,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end loadEmailLink()

	/**
	 * Resolve the email's parent object, in its register when the link names one.
	 *
	 * @param int    $emailId          EmailLink row id.
	 * @param string $parentObjectUuid The parent object's uuid.
	 * @param mixed  $parentRegisterId The parent's register id as the link holds it.
	 *
	 * @return ObjectEntity|null The parent, or null (logged) when it does not resolve.
	 */
	private function resolveEmailParent(int $emailId, string $parentObjectUuid, mixed $parentRegisterId): ?ObjectEntity {
		try {
			// EmailLink's `@method int getRegisterId()` is non-nullable in
			// the docblock; defensively coerce via empty() in case the
			// column is unexpectedly null at runtime.
			$register = null;
			if (empty($parentRegisterId) === false) {
				$register = $this->registerMapper->find((string)$parentRegisterId);
			}

			return $this->magicMapper->find(
				identifier: $parentObjectUuid,
				register: $register
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[EntityRelationsController] Email-bound auth check could not resolve parent object, denying',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'email_id' => $emailId,
					'object_uuid' => $parentObjectUuid,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end resolveEmailParent()
}//end class
