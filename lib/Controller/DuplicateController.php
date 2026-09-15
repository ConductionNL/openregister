<?php

/**
 * OpenRegister DuplicateController
 *
 * HTTP entry point for the read-only MDM duplicate-candidate surface.
 * {@see index()} delegates entirely to
 * {@see \OCA\OpenRegister\Service\Quality\DuplicateDetectionService::findDuplicates()}
 * — no dedup logic lives here, and the endpoint performs no merge or write of
 * any kind. Candidate pairs are RBAC- and tenant-scoped because
 * `DuplicateDetectionService` reads via `ObjectService::findAll`.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Quality\DuplicateDetectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

class DuplicateController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The current request.
	 * @param DuplicateDetectionService $duplicates Duplicate-candidate detection service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DuplicateDetectionService $duplicates,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Paginated duplicate-candidate pairs for a register/schema, descending
	 * by similarity score. Read-only: no merge, no write, no side effects.
	 * Accepts an optional `threshold` (falls back to the schema's
	 * `x-openregister-dedup` annotation, then the service default) and
	 * `limit`/`offset` pagination params.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse JSON response with the paginated candidate pairs.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
	 */
	public function index(string $register, string $schema): JSONResponse {
		$thresholdParam = $this->request->getParam('threshold');
		$threshold = null;
		if ($thresholdParam !== null && (string)$thresholdParam !== '' && is_numeric($thresholdParam) === true) {
			$threshold = (float)$thresholdParam;
		}

		$limit = (int)$this->request->getParam('limit', 20);
		$offset = (int)$this->request->getParam('offset', 0);

		if ($limit <= 0) {
			$limit = 20;
		}

		if ($offset < 0) {
			$offset = 0;
		}

		try {
			$pairs = $this->duplicates->findDuplicates(
				register: $register,
				schema: $schema,
				matchRules: null,
				threshold: $threshold
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$total = count($pairs);
		$page = array_slice($pairs, $offset, $limit);

		return new JSONResponse(
			[
				'items' => $page,
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
			]
		);
	}//end index()

	/**
	 * Score an unsaved candidate against the stored objects of a
	 * register/schema and return what it would pair with.
	 *
	 * Read-only in the strongest sense: the body is scored and discarded. It
	 * is never given a uuid, never validated against the schema, and never
	 * reaches a write path, so a form may ask this on every keystroke without
	 * leaving anything behind.
	 *
	 * The candidate is the request body minus the reserved keys the object
	 * API uses for metadata, so a form can post the same shape it would POST
	 * to create and get an answer about it.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse The matches, with score, matched fields and matched rules.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function check(string $register, string $schema): JSONResponse {
		$candidate = $this->request->getParams();
		foreach (['register', 'schema', '_route', '@self', 'id', 'threshold'] as $reserved) {
			unset($candidate[$reserved]);
		}

		$thresholdParam = $this->request->getParam('threshold');
		$threshold = null;
		if ($thresholdParam !== null && (string)$thresholdParam !== '' && is_numeric($thresholdParam) === true) {
			$threshold = (float)$thresholdParam;
		}

		try {
			$matches = $this->duplicates->checkCandidate(
				register: $register,
				schema: $schema,
				candidate: $candidate,
				matchRules: null,
				threshold: $threshold
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			[
				'matches' => $matches,
				'total' => count($matches),
				'threshold' => $this->duplicates->effectiveThreshold(
					register: $register,
					schema: $schema,
					threshold: $threshold
				),
			]
		);
	}//end check()
}//end class
