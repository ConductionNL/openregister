<?php

/**
 * ConfigurationBundleController — the HTTP surface of a bundle and its bindings.
 *
 * List the bundles, read what one carries and who follows it, bind a schema to
 * one, unbind it, and copy a matrix onto another subject as a draft.
 *
 * Its own controller rather than five more methods on
 * `ConfigurationDeploymentController`. That one already carries twelve routes
 * over the draft, deployment and explainer surfaces; a reader looking for the
 * bundle half would have to read past all of them.
 *
 * ADMINISTRATOR ONLY, and by the framework rather than by a check in a method
 * body. No route here carries `@NoAdminRequired`, so Nextcloud's middleware
 * refuses everybody else before the method runs.
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

use OCA\OpenRegister\Db\ConfigurationBinding;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationBundleService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Bundles, their bindings and the matrix copy.
 */
class ConfigurationBundleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                     $appName The app name.
	 * @param IRequest                   $request The request.
	 * @param ConfigurationBundleService $bundles The bindings and the copy.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConfigurationBundleService $bundles,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * GET /api/configuration/bundles — every bundle, with what it carries.
	 *
	 * @return JSONResponse The bundles.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function index(): JSONResponse {
		$bundles = $this->bundles->listBundles();

		return new JSONResponse(data: ['results' => $bundles, 'total' => count($bundles)]);

	}//end index()

	/**
	 * GET /api/configuration/bundles/{id} — one bundle and its bindings.
	 *
	 * Each bound subject says whether it overrides the bundle, and which value
	 * it overrides. An exception that is not visible as an exception is the
	 * drift a bundle exists to prevent.
	 *
	 * @param string $id The bundle name.
	 *
	 * @return JSONResponse The bundle.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function show(string $id): JSONResponse {
		return new JSONResponse(
			data: [
				'name' => $id,
				'values' => $this->bundles->valuesOf(bundle: $id),
				'bindings' => $this->bundles->bindingsOf(bundle: $id),
			]
		);

	}//end show()

	/**
	 * POST /api/configuration/bundles/{id}/bindings — bind a subject.
	 *
	 * Body: `subject` (required), `subjectType` (optional, schema or role).
	 *
	 * @param string $id The bundle name.
	 *
	 * @return JSONResponse The binding, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function bind(string $id): JSONResponse {
		try {
			$binding = $this->bundles->bind(
				bundle: $id,
				subject: (string)($this->request->getParam(key: 'subject') ?? ''),
				subjectType: ($this->optional(key: 'subjectType') ?? ConfigurationBinding::TYPE_SCHEMA)
			);
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(data: $binding->jsonSerialize(), statusCode: Http::STATUS_CREATED);

	}//end bind()

	/**
	 * DELETE /api/configuration/bundles/{id}/bindings/{subject} — unbind it.
	 *
	 * The subject's own values stay where they are: unbinding is not a way to
	 * delete configuration.
	 *
	 * @param string $id      The bundle name.
	 * @param string $subject The subject to unbind.
	 *
	 * @return JSONResponse Whether a binding was removed.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function unbind(string $id, string $subject): JSONResponse {
		$removed = $this->bundles->unbind(subject: $subject);

		if ($removed === false) {
			return new JSONResponse(
				data: [
					'error' => 'unknown',
					'message' => sprintf('"%s" follows no bundle', $subject),
				],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: ['bundle' => $id, 'subject' => $subject, 'bound' => false]);

	}//end unbind()

	/**
	 * POST /api/configuration/draft-sets/{id}/copy — copy a matrix as drafts.
	 *
	 * Body: `prefix` (required, ending in a dot), `from` and `to` (required),
	 * `layer` (optional, subject by default).
	 *
	 * @param string $id The draft set the copy lands in.
	 *
	 * @return JSONResponse What was drafted, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function copy(string $id): JSONResponse {
		try {
			$copied = $this->bundles->copyMatrix(
				setUuid: $id,
				prefix: (string)($this->request->getParam(key: 'prefix') ?? ''),
				fromRef: (string)($this->request->getParam(key: 'from') ?? ''),
				toRef: (string)($this->request->getParam(key: 'to') ?? ''),
				layer: ($this->optional(key: 'layer') ?? ConfigurationLayer::SUBJECT)
			);
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(
			data: [
				'set' => $id,
				'copied' => $copied,
				'total' => count($copied),
				'message' => 'the copy is a draft, so the live matrix is unchanged until the set is deployed',
			],
			statusCode: Http::STATUS_CREATED
		);

	}//end copy()

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
