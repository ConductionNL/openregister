<?php

/**
 * Who holds which right on one object, and how that set changed.
 *
 * The other direction of the index `GET /api/scopes` reads (design D-10).
 * `/api/scopes` answers one caller's question about themselves; this answers the
 * auditor's question about everybody: which principals hold which verbs on this
 * dossier, and which rule put each of them there.
 *
 * WHO MAY ASK. The object is resolved through `ObjectService`, so a caller who
 * cannot read it gets 404 and learns nothing, not even that it exists. Reading
 * WHO ELSE has access is a second question and takes a second right: the owner,
 * an administrator, or a caller holding `manage` on the schema. An ordinary
 * reader of a dossier has no business enumerating the case workers on it.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The per-object access set and its history.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class ObjectPermissionsController extends Controller {

	/**
	 * How many trail entries the history reads at most.
	 *
	 * @var integer
	 */
	private const HISTORY_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param string                    $appName          Application identifier.
	 * @param IRequest                  $request          Active HTTP request.
	 * @param ObjectService             $objectService    Resolves the object through the RBAC boundary.
	 * @param RegisterMapper            $registerMapper   Register lookup.
	 * @param SchemaMapper              $schemaMapper     Schema lookup.
	 * @param AuditTrailMapper          $auditTrailMapper The trail the history is read from.
	 * @param ObjectPermissionsResolver $resolver         Reads the access set out of the rules.
	 * @param DenyEnforcementMode       $enforcement      The staging switch.
	 * @param IUserSession              $userSession      The calling principal.
	 * @param IGroupManager             $groupManager     Administrator detection.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly ObjectPermissionsResolver $resolver,
		private readonly DenyEnforcementMode $enforcement,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The principals holding rights on one object, each with the rule behind it.
	 *
	 * Response shape:
	 *
	 *   {
	 *     "object": "<uuid>",
	 *     "holders": [
	 *       {"principal": "behandelaars", "verbs": ["read", "update"],
	 *        "rules": [{"action": "read", "level": "schema", "role": null, "rule": ["behandelaars"]}]}
	 *     ],
	 *     "denied": [{"principal": "waarnemers", "action": "read", "level": "object", ...}],
	 *     "denyEnforcement": "staging"
	 *   }
	 *
	 * The denies are reported beside the grants rather than subtracted from
	 * them, and the enforcement mode is reported with them, because below
	 * `enforcing` a deny is a rule that has not started biting yet and an
	 * auditor reading a subtraction that has not happened would be misled in the
	 * one direction that matters.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return JSONResponse The access set.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$schemaEntity = $this->schemaEntity();
		$refusal = $this->requireAccessReview(object: $object, schema: $schemaEntity);
		if ($refusal !== null) {
			return $refusal;
		}

		$registerEntity = $this->registerEntity();
		$set = $this->resolver->holders(
			blocks: [
				'object' => $object->getAuthorization(),
				'schema' => $schemaEntity?->getAuthorization(),
				'register' => $registerEntity?->getAuthorization(),
			],
			roleDefinitions: $this->roleDefinitionsOf(register: $registerEntity)
		);

		return new JSONResponse(
			[
				'object' => $object->getUuid(),
				'register' => $register,
				'schema' => $schema,
				'owner' => $object->getOwner(),
				'holders' => $set['holders'],
				'denied' => $set['denied'],
				'denyEnforcement' => $this->enforcement->current(),
			]
		);
	}//end index()

	/**
	 * How this object's access set changed, and what it was at a past moment.
	 *
	 * The `at` query parameter asks the auditor's actual question: not "what
	 * changed" but "who could open this in March", which a list of changes
	 * answers only after somebody replays it by hand.
	 *
	 * @param string      $register Register slug or id.
	 * @param string      $schema   Schema slug or id.
	 * @param string      $id       Object uuid.
	 * @param string|null $at       An ISO-8601 moment to report the set as of.
	 *
	 * @return JSONResponse The history.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function history(string $register, string $schema, string $id, ?string $at = null): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$refusal = $this->requireAccessReview(object: $object, schema: $this->schemaEntity());
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$entries = $this->auditTrailMapper->findForObjectByAction(
				objectUuid: (string)$object->getUuid(),
				actions: [],
				limit: self::HISTORY_LIMIT
			);
		} catch (\Throwable $e) {
			// An unreadable trail is reported as unreadable. Answering "no
			// changes" would be the same shape as "nothing ever changed", which
			// is the one answer an auditor must not be given by accident.
			return new JSONResponse(
				['message' => 'The audit trail for this object could not be read'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$history = $this->resolver->history(entries: $entries, at: $at);

		return new JSONResponse(
			[
				'object' => $object->getUuid(),
				'at' => $at,
				'changes' => $history['changes'],
				'asOf' => $history['asOf'],
				'entriesRead' => count($entries),
				'limit' => self::HISTORY_LIMIT,
			]
		);
	}//end history()

	/**
	 * Refuse a caller who may read the object but not review its access.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema|null  $schema The schema it belongs to.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may ask.
	 */
	private function requireAccessReview(ObjectEntity $object, ?Schema $schema): ?JSONResponse {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID();

		if ($userId !== null && $userId === $object->getOwner()) {
			return null;
		}

		if ($user !== null && $this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		if ($schema !== null
			&& $this->objectService->getPermissionHandler()->hasPermission(
				schema: $schema,
				action: 'manage',
				userId: $userId
			) === true
		) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Reading who holds rights on this object needs the manage permission'],
			Http::STATUS_FORBIDDEN
		);
	}//end requireAccessReview()

	/**
	 * The register the object belongs to, or null when it cannot be resolved.
	 *
	 * @return Register|null The register.
	 */
	private function registerEntity(): ?Register {
		try {
			return $this->registerMapper->find($this->objectService->getRegister());
		} catch (\Throwable $e) {
			return null;
		}
	}//end registerEntity()

	/**
	 * The schema the object belongs to, or null when it cannot be resolved.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaEntity(): ?Schema {
		try {
			return $this->schemaMapper->find($this->objectService->getSchema());
		} catch (\Throwable $e) {
			return null;
		}
	}//end schemaEntity()

	/**
	 * The role definitions a register declares.
	 *
	 * @param Register|null $register The register.
	 *
	 * @return mixed The definitions, or null when there are none.
	 */
	private function roleDefinitionsOf(?Register $register): mixed {
		if ($register === null) {
			return null;
		}

		$configuration = $register->getConfiguration();
		if (is_array($configuration) === false) {
			return null;
		}

		return ($configuration['roles'] ?? null);
	}//end roleDefinitionsOf()

	/**
	 * Resolve the object through the RBAC boundary.
	 *
	 * An object the caller cannot read resolves to nothing and answers 404, so
	 * existence is not leaked. Same guard, same reason, as the sharing endpoints
	 * beside this one.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the refusal.
	 */
	private function resolveObject(string $register, string $schema, string $id): ObjectEntity|JSONResponse {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		try {
			$object = $this->objectService->getObject();
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		if (($object instanceof ObjectEntity) === false) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		return $object;
	}//end resolveObject()
}//end class
