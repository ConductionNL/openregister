<?php
/**
 * The CLEAN arm for gate-7 (.github#353).
 *
 * Every `#[NoAdminRequired]` method here is guarded by a predicate spelled
 * VERB-OBJECT — `canUserAccessAgent()`, `hasOwnerPermissionForAgent()` — which is how
 * hermiq spells its predicates and which `_GUARD_HELPER_NAME_RE` used to reject,
 * because it required the auth token (Access / Admin / Permission / …) to be the
 * FINAL CamelCase segment.
 *
 * All three of hermiq's gate-7 findings were false positives from exactly this.
 *
 * ⚠️ NOT `canUserModifyAgent()`, although SHARED-LESSONS lists it beside
 * `canUserAccessAgent()` as one of hermiq's predicates. Measured against the
 * merged #353 regex: `canUserModifyAgent` is still NOT recognised, because
 * "Modify" is not one of the required auth tokens. #353 relaxed the token's
 * POSITION, not the token set. Using it here made the clean arm emit a finding
 * and would have been read as "the fix did not work".
 *
 * This file must produce ZERO findings. Under the pre-#353 regex it produces
 * THREE — that is asserted directly by the suite, because a fixture that passes
 * identically before and after the fix proves nothing about the fix. (The #353
 * author hit precisely this: their first "now passes" fixture also passed under
 * the old regex.)
 *
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

namespace OCA\ScopeFixture\Controller;

use OCA\ScopeFixture\Service\AgentAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AgentController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AgentAccessService $access,
		private readonly string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Shape 1 — the guard is called IN BODY, verb-object spelling.
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		$agent = $this->access->findAgent($id);
		if ($agent === null || !$this->canUserAccessAgent($agent, $this->userId)) {
			// The 404-style tenancy refusal this gate's own FAIL message endorses.
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($agent);
	}

	/**
	 * Shape 2 — a different verb, same verb-object spelling.
	 */
	#[NoAdminRequired]
	public function update(int $id, array $data): JSONResponse {
		$agent = $this->access->findAgent($id);
		if ($agent === null || !$this->hasOwnerPermissionForAgent($agent, $this->userId)) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($this->access->apply($agent, $data));
	}

	/**
	 * Shape 3 — the Pattern-4 transitive closure: the decision and the refusal
	 * are split across a loader and its caller, so neither half alone shows both.
	 */
	#[NoAdminRequired]
	public function diff(int $id): JSONResponse {
		$agent = $this->loadAccessibleAgent($id);
		if ($agent === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($this->access->diff($agent));
	}

	private function loadAccessibleAgent(int $id): ?array {
		$agent = $this->access->findAgent($id);
		if ($agent === null || !$this->canUserAccessAgent($agent, $this->userId)) {
			return null;
		}
		return $agent;
	}

	private function canUserAccessAgent(array $agent, string $userId): bool {
		return $agent['ownerId'] === $userId || $this->access->isShared($agent, $userId);
	}

	private function hasOwnerPermissionForAgent(array $agent, string $userId): bool {
		return $agent['ownerId'] === $userId;
	}
}
