<?php
/**
 * The PLANTED arm for gate-7.
 *
 * Byte-for-byte the clean arm's structure, with the guard REMOVED from
 * `show()` and `update()`. Any authenticated user can read or overwrite any
 * agent by id — textbook IDOR (OWASP A01:2021, ADR-005 Rule 3).
 *
 * `diff()` KEEPS its verb-object guard through `loadAccessibleAgent()`. It is
 * here so the planted arm is not uniformly guilty: a checker that simply
 * flagged every `#[NoAdminRequired]` method would score 3/3 here and look
 * correct. It must find exactly the two that lost their guard.
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
	 * PLANTED IDOR — no per-object guard of any kind.
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		$agent = $this->access->findAgent($id);
		return new JSONResponse($agent);
	}

	/**
	 * PLANTED IDOR — writes to an arbitrary id.
	 */
	#[NoAdminRequired]
	public function update(int $id, array $data): JSONResponse {
		$agent = $this->access->findAgent($id);
		return new JSONResponse($this->access->apply($agent, $data));
	}

	/**
	 * NOT planted — keeps the verb-object guard via the loader.
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
}
