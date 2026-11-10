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
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ActionParameters;
use OCA\OpenRegister\Controller\Support\CaseControllerServices;
use OCA\OpenRegister\Controller\Support\CasePlanRequest;
use OCA\OpenRegister\Controller\Support\CaseTransitionCommand;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Exception\ActionParameterTypeException;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Exception\CaseCascadeBoundException;
use OCA\OpenRegister\Exception\CaseTransitionException;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CaseAdHocRequest;
use OCA\OpenRegister\Service\Case\CaseAnchor;
use OCA\OpenRegister\Service\Case\CaseCompletion;
use OCA\OpenRegister\Service\Case\CaseItemTransition;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationServiceInterface;
use OCA\OpenRegister\Service\Case\CasePlanCreation;
use OCA\OpenRegister\Service\Case\CasePlanProvenance;
use OCA\OpenRegister\Service\Case\CasePlanServiceInterface;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapperInterface;
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
class CaseController extends Controller {

	/**
	 * The authorized case layer.
	 *
	 * @var CasePlanServiceInterface
	 */
	private readonly CasePlanServiceInterface $plans;

	/**
	 * The pure zaaktype mapping.
	 *
	 * @var ZaaktypeCaseSkeletonMapperInterface
	 */
	private readonly ZaaktypeCaseSkeletonMapperInterface $zaaktypes;

	/**
	 * Identity checks for the anchorless route.
	 *
	 * @var CasePlanAuthorizationServiceInterface
	 */
	private readonly CasePlanAuthorizationServiceInterface $authorization;

	/**
	 * Names the acting identity.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CaseControllerServices $services The case controller services.
	 * @param LoggerInterface|null $logger Where an unexpected failure's detail goes, INSTEAD of the response.
	 */
	public function __construct(
		IRequest $request,
		CaseControllerServices $services,
		private readonly ?LoggerInterface $logger = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->plans = $services->plans;
		$this->zaaktypes = $services->zaaktypes;
		$this->authorization = $services->authorization;
		$this->userSession = $services->userSession;

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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function items(?string $type = null, ?string $state = null, int $limit = 25, int $offset = 0): JSONResponse {
		$query = new ListQuery(limit: $limit, offset: $offset, filters: ['type' => $type, 'state' => $state]);

		return $this->guard(action: fn (): array => $this->plans->findStuck(query: $query, uid: $this->uid()));
	}//end items()

	/**
	 * A zaaktype document becomes a draft skeleton plus a report. Pure; writes nothing.
	 *
	 * @param array<string, mixed>|null $zaaktype The zaaktype document.
	 *
	 * @return JSONResponse draft, definition, report.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
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
		$anchor = new CaseAnchor(objectUuid: $objectUuid, registerId: $register, schemaId: $schema);
		$provenance = new CasePlanProvenance(flowUuid: $flowUuid, flowVersion: $flowVersion);

		return $this->createPlan(request: new CasePlanRequest(anchor: $anchor, definition: ($definition ?? []), provenance: $provenance));
	}//end create()

	/**
	 * Create the requested plan for the acting user.
	 *
	 * @param CasePlanRequest $request The anchor, definition and provenance.
	 *
	 * @return JSONResponse The plan, 201.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function createPlan(CasePlanRequest $request): JSONResponse {
		return $this->guard(
			action: fn (): array => $this->plans->createPlan(
				creation: new CasePlanCreation(anchor: $request->anchor, definition: $request->definition, actor: $this->uid(), provenance: $request->provenance)
			),
			status: Http::STATUS_CREATED
		);
	}//end createPlan()

	/**
	 * Re-evaluate a plan.
	 *
	 * @param string $objectUuid The anchoring object.
	 *
	 * @return JSONResponse passes, transitions.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function enableable(string $objectUuid): JSONResponse {
		return $this->guard(action: fn (): array => ['results' => $this->plans->enableableItems(objectUuid: $objectUuid, uid: $this->uid())]);
	}//end enableable()

	/**
	 * Attach an ad-hoc item.
	 *
	 * Reads the item fields from the request body: the strings `key`, `type`,
	 * `name`, `description`, `parent` (a stage uuid or key, absent for the
	 * root), `candidateRole`, `dueAt` (advisory deadline) and `expiresAt`
	 * (enforcing deadline), the lists `entryCriteria`, `exitCriteria`,
	 * `candidateUsers` and `candidateGroups`, and the boolean `required`
	 * (whether the parent waits for it, default true). Absent fields are
	 * not sent on.
	 *
	 * @param string $objectUuid The anchoring object.
	 *
	 * @return JSONResponse The item, 201; 400 for a field of the wrong type.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function attach(string $objectUuid): JSONResponse {
		try {
			$data = $this->adHocFields(read: new ActionParameters(params: $this->request->getParams()));
		} catch (ActionParameterTypeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return $this->attachItem(objectUuid: $objectUuid, data: $data);
	}//end attach()

	/**
	 * The fields of an ad-hoc item that were sent, with `required` last.
	 *
	 * @param ActionParameters $read The request parameters.
	 *
	 * @return array<string, mixed> The fields that are not null, then `required`.
	 *
	 * @throws ActionParameterTypeException When a field has the wrong type.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function adHocFields(ActionParameters $read): array {
		$fields = [];
		foreach (['key', 'type', 'name', 'description', 'parent'] as $key) {
			$fields[$key] = $read->string(key: $key);
		}

		foreach (['entryCriteria', 'exitCriteria', 'candidateUsers', 'candidateGroups'] as $key) {
			$fields[$key] = $read->list(key: $key);
		}

		foreach (['candidateRole', 'dueAt', 'expiresAt'] as $key) {
			$fields[$key] = $read->string(key: $key);
		}

		$data = array_filter($fields, static fn (mixed $value): bool => $value !== null);

		return $data + ['required' => $read->bool(key: 'required', default: true)];
	}//end adHocFields()

	/**
	 * Attach an ad-hoc item with the given fields to the plan of an object.
	 *
	 * An ad-hoc item may not declare its own authorization; the body key,
	 * if sent, is refused inside the service, so it is forwarded as-is.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param array<string, mixed> $data The item fields that were sent, with `required`.
	 *
	 * @return JSONResponse The item, 201.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function attachItem(string $objectUuid, array $data): JSONResponse {
		$body = $this->request->getParams();
		if (array_key_exists('authorization', $body) === true) {
			$data['authorization'] = $body['authorization'];
		}

		$request = new CaseAdHocRequest(objectUuid: $objectUuid, data: $data, actor: $this->uid());

		return $this->guard(
			action: fn (): array => $this->plans->attachAdHoc(request: $request)->jsonSerialize(),
			status: Http::STATUS_CREATED
		);
	}//end attachItem()

	/**
	 * Finish the case with a result.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $result The result.
	 *
	 * @return JSONResponse The plan plus `result`.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function complete(string $objectUuid, ?string $result = null): JSONResponse {
		return $this->guard(
			action: function () use ($objectUuid, $result): array {
				if ($result === null || trim($result) === '') {
					throw new CaseValidationException(message: 'A `result` is required to finish a case.');
				}

				return $this->plans->completeCase(completion: new CaseCompletion(objectUuid: $objectUuid, result: $result, actor: $this->uid()));
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function transition(string $uuid, ?string $to = null, ?string $reason = null): JSONResponse {
		$command = new CaseTransitionCommand(itemUuid: $uuid, to: $to, reason: $reason);

		return $this->guard(action: fn (): array => $this->transitioned(command: $command));
	}//end transition()

	/**
	 * Transition one item by hand; a missing target state is refused first.
	 *
	 * @param CaseTransitionCommand $command The item, target state and reason.
	 *
	 * @return array<string, mixed> The item.
	 *
	 * @throws CaseValidationException Without a target state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function transitioned(CaseTransitionCommand $command): array {
		$to = $command->to;
		if ($to === null || trim($to) === '') {
			throw new CaseValidationException(message: 'A target state `to` is required.');
		}

		$transition = new CaseItemTransition(itemUuid: $command->itemUuid, to: $to, actor: $this->uid(), reason: $command->reason);

		return $this->plans->transition(transition: $transition)->jsonSerialize();
	}//end transitioned()

	/**
	 * Enable a discretionary item.
	 *
	 * @param string $uuid The item.
	 *
	 * @return JSONResponse The item.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
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
