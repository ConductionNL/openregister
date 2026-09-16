<?php

/**
 * ConfigurationDeploymentController — the HTTP surface of the lifecycle.
 *
 * Open a set, draft values into it, read what it would change, approve it,
 * deploy it, read the history, roll one back, and ask why a setting has the
 * value it has.
 *
 * ADMINISTRATOR ONLY, and by the framework rather than by a check in a method
 * body. No route here carries `@NoAdminRequired`, so Nextcloud's middleware
 * refuses everybody else before the method runs. A body check beside a
 * `@NoAdminRequired` attribute is the exact mismatch the semantic-auth gate
 * exists to catch, and there is no case for an ordinary account deploying
 * instance configuration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Draft, preview, deploy, explain and roll back configuration.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller over the four
 * services the lifecycle is made of; each route reaches exactly one of them.
 */
class ConfigurationDeploymentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName    The app name.
	 * @param IRequest                  $request    The request.
	 * @param ConfigurationDraftService $drafts     The pending values.
	 * @param DeploymentPreviewService  $previews   The diff.
	 * @param DeploymentService         $deployments The apply and the rollback.
	 * @param ConfigurationExplainer    $explainer  The effective-configuration read.
	 * @param ConfigurationKeyRegistry  $registry   The draftable vocabulary.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConfigurationDraftService $drafts,
		private readonly DeploymentPreviewService $previews,
		private readonly DeploymentService $deployments,
		private readonly ConfigurationExplainer $explainer,
		private readonly ConfigurationKeyRegistry $registry,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * GET /api/configuration/draft-sets — the sets, newest first.
	 *
	 * @return JSONResponse The sets.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function index(): JSONResponse {
		$state = $this->optional(key: 'state');
		$sets = $this->drafts->listSets(state: $state);

		return new JSONResponse(
			data: [
				'results' => array_map(static fn ($set) => $set->jsonSerialize(), $sets),
				'total' => count($sets),
				'fourEyesRequired' => $this->drafts->requiresFourEyes(),
				// Which addresses may be drafted at all. Served here so a
				// caller reads the vocabulary once instead of discovering it
				// one refusal at a time.
				'vocabulary' => $this->registry->vocabulary(),
			]
		);

	}//end index()

	/**
	 * POST /api/configuration/draft-sets — open a set.
	 *
	 * Body: `name` (required), `description` (optional).
	 *
	 * @return JSONResponse The opened set, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function create(): JSONResponse {
		try {
			$set = $this->drafts->openSet(
				name: (string)($this->request->getParam(key: 'name') ?? ''),
				description: $this->optional(key: 'description')
			);
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(data: $set->jsonSerialize(), statusCode: Http::STATUS_CREATED);

	}//end create()

	/**
	 * GET /api/configuration/draft-sets/{id} — one set and its pending values.
	 *
	 * @param string $id The set uuid.
	 *
	 * @return JSONResponse The set, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function show(string $id): JSONResponse {
		try {
			$set = $this->drafts->loadSet(uuid: $id);
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(
			data: [
				'set' => $set->jsonSerialize(),
				'drafts' => array_map(
					static fn ($draft) => $draft->jsonSerialize(),
					$this->drafts->draftsIn(setUuid: $id)
				),
			]
		);

	}//end show()

	/**
	 * POST /api/configuration/draft-sets/{id}/values — draft one value.
	 *
	 * Body: `key` (required), `value`, `layer` (defaults to instance),
	 * `layerRef`, `removes`.
	 *
	 * The live value is unchanged: REQ-CAD-001's first scenario is exactly
	 * that reading the setting after this call still returns the old value.
	 *
	 * @param string $id The set uuid.
	 *
	 * @return JSONResponse The pending value, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function draftValue(string $id): JSONResponse {
		try {
			$draft = $this->drafts->draftValue(
				setUuid: $id,
				layer: (string)($this->request->getParam(key: 'layer') ?? ConfigurationLayer::INSTANCE),
				layerRef: $this->optional(key: 'layerRef'),
				configKey: (string)($this->request->getParam(key: 'key') ?? ''),
				value: $this->request->getParam(key: 'value'),
				removes: ((bool)($this->request->getParam(key: 'removes') ?? false))
			);
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(data: $draft->jsonSerialize(), statusCode: Http::STATUS_CREATED);

	}//end draftValue()

	/**
	 * GET /api/configuration/draft-sets/{id}/preview — what it would change.
	 *
	 * @param string $id The set uuid.
	 *
	 * @return JSONResponse The diff, the refusals and the counts.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function preview(string $id): JSONResponse {
		try {
			$set = $this->drafts->loadSet(uuid: $id);
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(data: $this->previews->preview(set: $set));

	}//end preview()

	/**
	 * POST /api/configuration/draft-sets/{id}/approve — clear it for deployment.
	 *
	 * @param string $id The set uuid.
	 *
	 * @return JSONResponse The approved set, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function approve(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->drafts->approveSet(setUuid: $id)->jsonSerialize());
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

	}//end approve()

	/**
	 * POST /api/configuration/draft-sets/{id}/deploy — publish it as one unit.
	 *
	 * Body: `name` (optional; the set's own name is used otherwise).
	 *
	 * @param string $id The set uuid.
	 *
	 * @return JSONResponse The recorded deployment, or the refusal naming the value.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function deploy(string $id): JSONResponse {
		try {
			$deployment = $this->deployments->deploy(setUuid: $id, name: $this->optional(key: 'name'));
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(data: $deployment->jsonSerialize(), statusCode: Http::STATUS_CREATED);

	}//end deploy()

	/**
	 * DELETE /api/configuration/draft-sets/{id} — abandon a set.
	 *
	 * @param string $id The set uuid.
	 *
	 * @return JSONResponse The discarded set, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function discard(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->drafts->discardSet(setUuid: $id)->jsonSerialize());
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

	}//end discard()

	/**
	 * GET /api/configuration/deployments — the history, newest first.
	 *
	 * @return JSONResponse The deployments.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function deployments(): JSONResponse {
		$history = $this->deployments->history();

		return new JSONResponse(
			data: [
				'results' => array_map(static fn ($item) => $item->jsonSerialize(), $history),
				'total' => count($history),
			]
		);

	}//end deployments()

	/**
	 * GET /api/configuration/deployments/{id} — one deployment, with its values.
	 *
	 * @param string $id The deployment uuid.
	 *
	 * @return JSONResponse The deployment, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function deployment(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->deployments->loadDeployment(uuid: $id)->jsonSerialize());
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

	}//end deployment()

	/**
	 * POST /api/configuration/deployments/{id}/rollback — restore its earlier values.
	 *
	 * Recorded as a NEW deployment naming the one it restores, so the history
	 * stays append-only and the broken weekend is still in it.
	 *
	 * @param string $id The deployment uuid to restore the values of.
	 *
	 * @return JSONResponse The recorded rollback, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function rollback(string $id): JSONResponse {
		try {
			$rollback = $this->deployments->rollback(
				deploymentUuid: $id,
				name: $this->optional(key: 'name')
			);
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(data: $rollback->jsonSerialize(), statusCode: Http::STATUS_CREATED);

	}//end rollback()

	/**
	 * GET /api/configuration/effective — which value is in effect, and why.
	 *
	 * Query: `key` (required), and optionally `register`, `bundle`, `subject`
	 * to read within a lower layer.
	 *
	 * @return JSONResponse The effective value, its layer and its deployment.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function effective(): JSONResponse {
		$configKey = trim((string)($this->request->getParam(key: 'key') ?? ''));
		if ($configKey === '') {
			return new JSONResponse(
				data: ['error' => 'invalid', 'message' => 'key is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: $this->explainer->explain(
				configKey: $configKey,
				register: $this->optional(key: 'register'),
				bundle: $this->optional(key: 'bundle'),
				subject: $this->optional(key: 'subject')
			)
		);

	}//end effective()

	/**
	 * A request parameter, normalised to null when absent or empty.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return string|null The value, or null.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function optional(string $key): ?string {
		$value = trim((string)($this->request->getParam(key: $key) ?? ''));
		if ($value === '') {
			return null;
		}

		return $value;

	}//end optional()
}//end class
