<?php

/**
 * Who holds which right on one object, and how that set changed.
 *
 * The other direction of the `GET /api/scopes` read (design D-10).
 * `/api/scopes` answers one caller's question about themselves; this answers the
 * auditor's question about everybody: which principals hold which verbs on this
 * dossier, and which rule put each of them there.
 *
 * WHO MAY ASK. The object is resolved through `ObjectService`, so a caller who
 * cannot read it gets 404 and learns nothing, not even that it exists. Reading
 * WHO ELSE has access is a second question and takes a second right: the owner,
 * an administrator, or a caller holding `manage` through a rule somebody
 * actually wrote. An ordinary reader of a dossier has no business enumerating
 * the case workers on it.
 *
 * WHY THE WORK IS NOT IN HERE. The blocks in play, the role definitions and the
 * trail are assembled in {@see \OCA\OpenRegister\Service\Rbac\ObjectAccessReport}.
 * A controller that interpreted the rules itself would be a second reading of
 * them, and the two would only ever meet in a support ticket.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\AccessReviewGate;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The per-object access set and its history.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class ObjectPermissionsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string             $appName       Application identifier.
	 * @param IRequest           $request       Active HTTP request.
	 * @param ObjectService      $objectService Names the register and schema the request resolved.
	 * @param ObjectAccessReport $report        Assembles the answer.
	 * @param AccessReviewGate   $gate          Resolves the object and decides who may ask.
	 * @param LoggerInterface    $logger        Records why an access set could not be assembled.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly ObjectAccessReport $report,
		private readonly AccessReviewGate $gate,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$refusal = $this->requireAccessReview(object: $object);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse(
				$this->report->setFor(
					object: $object,
					registerRef: $this->objectService->getRegister(),
					schemaRef: $this->objectService->getSchema()
				)
			);
		} catch (\Throwable $e) {
			// An access set that could not be assembled is reported as exactly
			// that. Left to the framework it is a 500 carrying a stack trace, on
			// an endpoint a non-admin owner may call, and an empty set in its
			// place would read as "nobody holds any right on this", which is
			// the one answer an auditor must not be given by accident. The cause
			// goes to the log, so the caller's generic message is not the only
			// trace of it.
			$this->logger->error(
				message: '[ObjectPermissionsController] The access set for {uuid} could not be assembled: {error}',
				context: ['uuid' => $id, 'error' => $e->getMessage(), 'exception' => $e]
			);
			return new JSONResponse(
				['message' => 'The access set for this object could not be assembled'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
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
	 * @SuppressWarnings(PHPMD.ShortVariable)
	 * Reason: `at` is the query parameter's name, and Nextcloud binds a request
	 *         parameter to the method argument that shares it. Renaming the
	 *         argument renames the endpoint's contract.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function history(string $register, string $schema, string $id, ?string $at = null): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$refusal = $this->requireAccessReview(object: $object);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse($this->report->historyFor(object: $object, moment: $at));
		} catch (\Throwable $e) {
			// An unreadable trail is reported as unreadable. Answering "no
			// changes" would be the same shape as "nothing ever changed", which
			// is the one answer an auditor must not be given by accident.
			return new JSONResponse(
				['message' => 'The audit trail for this object could not be read'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end history()

	/**
	 * Refuse a caller who may read the object but not review its access.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may ask.
	 */
	private function requireAccessReview(ObjectEntity $object): ?JSONResponse {
		if ($this->gate->mayReviewAccess(object: $object) === true) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Reading who holds rights on this object needs the manage permission'],
			Http::STATUS_FORBIDDEN
		);
	}//end requireAccessReview()

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
		return ($this->gate->resolve(register: $register, schema: $schema, id: $id)
			?? new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND));
	}//end resolveObject()
}//end class
