<?php

/**
 * The case-plan REST surface.
 *
 * Auth posture: every route is `#[NoAdminRequired]` (case work is for
 * caseworkers, not administrators) and NONE of those attributes is the
 * check. The real authorization on every verb is
 * `CasePlanAuthorizationService`, evaluated inside the service BEFORE any
 * write; reads require that the caller may read the anchoring object. A plan
 * the caller may not see answers 404 to every route, so neither its
 * existence nor its state leaks through a 403.
 *
 * CSRF, decided rather than inherited: mutating verbs carry
 * `#[NoCSRFRequired]` for the same reason `TaskController` does. These
 * routes are driven by leaf apps and agents with Basic auth or app
 * passwords, for which Nextcloud issues no CSRF token; the browser-session
 * guard is Nextcloud's SameSite cookie middleware.
 *
 * No route here accepts or produces CMMN XML. The definition format is the
 * system's own (`CasePlanDefinition`).
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Exception\CaseCascadeBoundException;
use OCA\OpenRegister\Exception\CaseTransitionException;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for case plans.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One route method per verb
 * the spec names plus the reads. Folding verbs into a mode parameter is how
 * per-verb authorization rules get lost.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller mediates
 * between HTTP, the service, the mapper and four exception shapes; that is
 * the whole of its job.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
class CaseController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param CasePlanService $plans The authorized case layer.
	 * @param ZaaktypeCaseSkeletonMapper $zaaktypes The pure zaaktype mapping.
	 * @param CasePlanAuthorizationService $authorization Identity checks for the anchorless route.
	 * @param IUserSession $userSession Names the acting identity.
	 * @param LoggerInterface|null $logger Where an unexpected failure's detail goes, INSTEAD of the response.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CasePlanService $plans,
		private readonly ZaaktypeCaseSkeletonMapper $zaaktypes,
		private readonly CasePlanAuthorizationService $authorization,
		private readonly IUserSession $userSession,
		private readonly ?LoggerInterface $logger = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Items across cases by type and state: "which cases are stuck where".
	 *
	 * @param string|null $type Plan-item type filter.
	 * @param string|null $state State filter.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return JSONResponse results, total, limit, offset.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function items(?string $type = null, ?string $state = null, int $limit = 25, int $offset = 0): JSONResponse {
		return $this->guard(action: fn (): array => $this->plans->findStuck(type: $type, state: $state, limit: $limit, offset: $offset, uid: $this->uid()));
	}//end items()

	/**
	 * A zaaktype document becomes a draft skeleton plus a report. Pure; writes nothing.
	 *
	 * @param array<string, mixed>|null $zaaktype The zaaktype document.
	 *
	 * @return JSONResponse draft, definition, report.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function skeletonFromZaaktype(?array $zaaktype = null): JSONResponse {
		return $this->guard(
			action: function () use ($zaaktype): array {
				$this->authorization->assertIdentified(uid: $this->uid(), verb: 'skeleton-from-zaaktype');
				if (is_array($zaaktype) === false || $zaaktype === []) {
					throw new CaseValidationException(message: 'A `zaaktype` document is required.');
				}

				return $this->zaaktypes->map(zaaktype: $zaaktype);
			}
		);
	}//end skeletonFromZaaktype()

	/**
	 * The plan of one object.
	 *
	 * @param string $objectUuid The anchoring object.
	 *
	 * @return JSONResponse The plan; 404 when absent OR invisible.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $objectUuid): JSONResponse {
		return $this->guard(action: fn (): array => $this->plans->getPlan(objectUuid: $objectUuid, uid: $this->uid()));
	}//end show()

	/**
	 * Create a plan on an object from a definition.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param int|null $register Its register id.
	 * @param int|null $schema Its schema id.
	 * @param array<string, mixed>|null $definition The definition (`settings`, `items`).
	 * @param string|null $flowUuid Definition provenance, when any.
	 * @param int|null $flowVersion Definition provenance, when any.
	 *
	 * @return JSONResponse The plan, 201.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(
		string $objectUuid,
		?int $register = null,
		?int $schema = null,
		?array $definition = null,
		?string $flowUuid = null,
		?int $flowVersion = null,
	): JSONResponse {
		return $this->guard(
			action: fn (): array => $this->plans->createPlan(
				objectUuid: $objectUuid,
				registerId: $register,
				schemaId: $schema,
				definition: ($definition ?? []),
				uid: $this->uid(),
				flowUuid: $flowUuid,
				flowVersion: $flowVersion
			),
			status: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Re-evaluate a plan.
	 *
	 * @param string $objectUuid The anchoring object.
	 *
	 * @return JSONResponse passes, transitions.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function evaluate(string $objectUuid): JSONResponse {
		return $this->guard(action: fn (): array => $this->plans->evaluate(objectUuid: $objectUuid, uid: $this->uid()));
	}//end evaluate()

	/**
	 * Which discretionary items may be enabled now.
	 *
	 * @param string $objectUuid The anchoring object.
	 *
	 * @return JSONResponse results.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function enableable(string $objectUuid): JSONResponse {
		return $this->guard(action: fn (): array => ['results' => $this->plans->enableableItems(objectUuid: $objectUuid, uid: $this->uid())]);
	}//end enableable()

	/**
	 * Attach an ad-hoc item.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $key The item key.
	 * @param string|null $type The plan-item type.
	 * @param string|null $name Human name.
	 * @param string|null $description Description.
	 * @param string|null $parent Parent stage uuid or key, or null for the root.
	 * @param array<int, mixed>|null $entryCriteria Entry sentries.
	 * @param array<int, mixed>|null $exitCriteria Exit sentries.
	 * @param array<int, string>|null $candidateUsers Candidate uids.
	 * @param array<int, string>|null $candidateGroups Candidate group ids.
	 * @param string|null $candidateRole Candidate role.
	 * @param string|null $dueAt Advisory deadline.
	 * @param string|null $expiresAt Enforcing deadline.
	 * @param bool $required Whether the parent waits for it.
	 *
	 * @return JSONResponse The item, 201.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One parameter per
	 * field the route accepts; Nextcloud binds them by name from the body.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `required` is a stored
	 * boolean field of the item, bound from the body like the others, not a
	 * behaviour switch of this method.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function attach(
		string $objectUuid,
		?string $key = null,
		?string $type = null,
		?string $name = null,
		?string $description = null,
		?string $parent = null,
		?array $entryCriteria = null,
		?array $exitCriteria = null,
		?array $candidateUsers = null,
		?array $candidateGroups = null,
		?string $candidateRole = null,
		?string $dueAt = null,
		?string $expiresAt = null,
		bool $required = true,
	): JSONResponse {
		$data = array_filter(
			[
				'key' => $key,
				'type' => $type,
				'name' => $name,
				'description' => $description,
				'parent' => $parent,
				'entryCriteria' => $entryCriteria,
				'exitCriteria' => $exitCriteria,
				'candidateUsers' => $candidateUsers,
				'candidateGroups' => $candidateGroups,
				'candidateRole' => $candidateRole,
				'dueAt' => $dueAt,
				'expiresAt' => $expiresAt,
			],
			static fn (mixed $value): bool => $value !== null
		);
		$data['required'] = $required;
		// An ad-hoc item may not declare its own authorization; the body key,
		// if sent, is refused inside the service, so it is forwarded as-is.
		$body = $this->request->getParams();
		if (array_key_exists('authorization', $body) === true) {
			$data['authorization'] = $body['authorization'];
		}

		return $this->guard(
			action: fn (): array => $this->plans->attachAdHoc(objectUuid: $objectUuid, data: $data, uid: $this->uid())->jsonSerialize(),
			status: Http::STATUS_CREATED
		);
	}//end attach()

	/**
	 * Finish the case with a result.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $result The result.
	 *
	 * @return JSONResponse The plan plus `result`.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function complete(string $objectUuid, ?string $result = null): JSONResponse {
		return $this->guard(
			action: function () use ($objectUuid, $result): array {
				if ($result === null || trim($result) === '') {
					throw new CaseValidationException(message: 'A `result` is required to finish a case.');
				}

				return $this->plans->completeCase(objectUuid: $objectUuid, result: $result, uid: $this->uid());
			}
		);
	}//end complete()

	/**
	 * Delete a plan's items. The audit and the mirrored business state stay.
	 *
	 * @param string $objectUuid The anchoring object.
	 *
	 * @return JSONResponse deleted.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(string $objectUuid): JSONResponse {
		return $this->guard(action: fn (): array => ['deleted' => $this->plans->deletePlan(objectUuid: $objectUuid, uid: $this->uid())]);
	}//end destroy()

	/**
	 * Transition one item by hand.
	 *
	 * @param string $uuid The item.
	 * @param string|null $to The target state.
	 * @param string|null $reason Free text.
	 *
	 * @return JSONResponse The item.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function transition(string $uuid, ?string $to = null, ?string $reason = null): JSONResponse {
		return $this->guard(
			action: function () use ($uuid, $to, $reason): array {
				if ($to === null || trim($to) === '') {
					throw new CaseValidationException(message: 'A target state `to` is required.');
				}

				return $this->plans->transition(itemUuid: $uuid, to: $to, uid: $this->uid(), reason: $reason)->jsonSerialize();
			}
		);
	}//end transition()

	/**
	 * Enable a discretionary item.
	 *
	 * @param string $uuid The item.
	 *
	 * @return JSONResponse The item.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function enable(string $uuid): JSONResponse {
		return $this->guard(action: fn (): array => $this->plans->enableDiscretionary(itemUuid: $uuid, uid: $this->uid())->jsonSerialize());
	}//end enable()

	/**
	 * The acting identity, or null without a session.
	 *
	 * @return string|null The uid.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end uid()

	/**
	 * Translate the service's exceptions to HTTP, once.
	 *
	 * Absent and invisible are the same 404; a denial is 403 only when the
	 * plan itself was visible (the service throws DoesNotExist otherwise);
	 * a refused value is 400; an illegal transition or a lost race is 409;
	 * the cascade bound is 422; anything else is 500 with the detail logged
	 * and NOT echoed.
	 *
	 * @param callable(): array $action The work.
	 * @param int $status The success status.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function guard(callable $action, int $status = Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($action(), $status);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		} catch (CaseAccessDeniedException $denied) {
			return new JSONResponse(['error' => $denied->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (CaseValidationException $refused) {
			return new JSONResponse(['error' => $refused->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (CaseTransitionException $conflict) {
			return new JSONResponse(['error' => $conflict->getMessage()], Http::STATUS_CONFLICT);
		} catch (CaseCascadeBoundException $bound) {
			return new JSONResponse(['error' => $bound->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $failure) {
			$this->logger?->error('[CaseController] ' . $failure->getMessage(), ['exception' => $failure]);

			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end guard()
}//end class
