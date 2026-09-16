<?php

/**
 * The administered purpose list, as an API.
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
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\ProcessingPurposeMapper;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Read, administer and report on the purposes a read may be made under.
 *
 * Reading the list is open to any authenticated caller, because a client that
 * cannot see the purposes cannot name one, and a refusal that offers codes the
 * caller may not read is a refusal nobody can act on. Changing the list is
 * admin only: a purpose is the legal basis a read is recorded under, and the
 * bar for adding one is the bar for adding a processing activity.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */
class ProcessingPurposeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                  $appName      App identifier.
	 * @param IRequest                $request      Active request.
	 * @param ProcessingPurposeMapper $purposes     The administered list.
	 * @param PurposeRegistry         $registry     Resolves a purpose to its activity.
	 * @param AuditTrailMapper        $auditTrail   Counts entries per purpose.
	 * @param IUserSession            $userSession  Current user session.
	 * @param IGroupManager           $groupManager Group manager, for the admin gate.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ProcessingPurposeMapper $purposes,
		private readonly PurposeRegistry $registry,
		private readonly AuditTrailMapper $auditTrail,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/avg/purposes — the purposes a caller may name.
	 *
	 * Optional query parameters: `status`, `organisation`.
	 *
	 * Each entry carries `bound`, resolved live against the processing
	 * register, so a client can tell an unusable purpose from a usable one
	 * without having to make a query and be refused.
	 *
	 * @return JSONResponse The list envelope.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		$rows = $this->purposes->findAll(
			status: $this->optionalParam(key: 'status'),
			organisationId: $this->optionalParam(key: 'organisation')
		);

		$results = [];
		foreach ($rows as $row) {
			$results[] = $this->serialize(purpose: $row);
		}

		return new JSONResponse(data: ['count' => count($results), 'results' => $results]);
	}//end index()

	/**
	 * GET /api/avg/purposes/{id} — one purpose, by id, uuid or code.
	 *
	 * @param string $id The identifier.
	 *
	 * @return JSONResponse The purpose, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt A purpose is instance-wide configuration, not a record about
	 *   anybody: a code, a name, and the verwerkingsactiviteit it names. There is no
	 *   per-object owner to guard against, and the whole list is already readable by any
	 *   authenticated caller through index() BY DESIGN, because a client that cannot see
	 *   the purposes cannot name one and a refusal offering codes it may not read is a
	 *   refusal nobody can act on. Guarding this id while leaving the list open would
	 *   protect nothing and hide the gap. Writes are admin-gated in create/update/destroy.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function show(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		$purpose = $this->resolve(identifier: $id);
		if ($purpose === null) {
			return new JSONResponse(
				data: ['error' => 'Not Found', 'identifier' => $id],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: $this->serialize(purpose: $purpose));
	}//end show()

	/**
	 * POST /api/avg/purposes — administer a new purpose.
	 *
	 * @return JSONResponse The persisted purpose, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function create(): JSONResponse {
		if ($this->isAdmin() === false) {
			return $this->forbidden();
		}

		$code = trim((string)($this->request->getParam(key: 'code') ?? ''));
		if ($code === '') {
			return new JSONResponse(
				data: ['error' => 'code is required'],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		if ($this->purposes->findByCode(code: $code) !== null) {
			return new JSONResponse(
				data: ['error' => 'A purpose with that code already exists', 'code' => $code],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$purpose = new ProcessingPurpose();
		$purpose->setCode($code);
		$this->hydrate(purpose: $purpose);

		try {
			$persisted = $this->purposes->insert($purpose);
		} catch (Throwable $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			data: $this->serialize(purpose: $persisted),
			statusCode: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * PUT /api/avg/purposes/{id} — change an administered purpose.
	 *
	 * @param string $id The identifier.
	 *
	 * @return JSONResponse The persisted purpose, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function update(string $id): JSONResponse {
		if ($this->isAdmin() === false) {
			return $this->forbidden();
		}

		$purpose = $this->resolve(identifier: $id);
		if ($purpose === null) {
			return new JSONResponse(
				data: ['error' => 'Not Found', 'identifier' => $id],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$this->hydrate(purpose: $purpose);

		try {
			$persisted = $this->purposes->update($purpose);
		} catch (Throwable $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(data: $this->serialize(purpose: $persisted));
	}//end update()

	/**
	 * DELETE /api/avg/purposes/{id} — withdraw a purpose.
	 *
	 * Never a hard delete. Audit rows written under a purpose name it by code,
	 * and removing the row would leave those rows naming a purpose nobody can
	 * look up. The purpose is retired instead, which stops new queries from
	 * running under it and keeps the old ones readable.
	 *
	 * @param string $id The identifier.
	 *
	 * @return JSONResponse Empty, with 204.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function destroy(string $id): JSONResponse {
		if ($this->isAdmin() === false) {
			return $this->forbidden();
		}

		$purpose = $this->resolve(identifier: $id);
		if ($purpose === null) {
			return new JSONResponse(
				data: ['error' => 'Not Found', 'identifier' => $id],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$purpose->setStatus(ProcessingPurpose::STATUS_RETIRED);
		$this->purposes->update($purpose);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroy()

	/**
	 * GET /api/avg/purposes/report — how many entries ran under each purpose.
	 *
	 * Optional query parameters `from` and `to`, ISO 8601.
	 *
	 * Unattributed rows are reported under `null` rather than dropped. A report
	 * that silently omits the reads nobody declared a purpose for is a report
	 * that says doelbinding is complete when it is not.
	 *
	 * @return JSONResponse The counts.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function report(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		$counts = $this->auditTrail->countByPurpose(
			from: $this->optionalDate(key: 'from'),
			to: $this->optionalDate(key: 'to')
		);

		return new JSONResponse(
			data: [
				'counts' => $counts,
				'total' => array_sum($counts),
				'unattributed' => ($counts['null'] ?? 0),
			]
		);
	}//end report()

	/**
	 * Write the mutable fields from the request onto a purpose.
	 *
	 * @param ProcessingPurpose $purpose The purpose to fill.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function hydrate(ProcessingPurpose $purpose): void {
		foreach (['name', 'description', 'activity', 'organisationId'] as $field) {
			$value = $this->optionalParam(key: $field);
			if ($value !== null) {
				$purpose->{'set' . ucfirst($field)}($value);
			}
		}

		$status = $this->optionalParam(key: 'status');
		if ($status !== null && ProcessingPurpose::isValidStatus(status: $status) === true) {
			$purpose->setStatus($status);
		}

		// The binding is resolved on write as well as on read. Writing it here
		// is what lets an administrator see immediately whether the reference
		// they typed names anything, instead of finding out when a query is
		// refused in production.
		$activity = $this->registry->boundActivity(purpose: $purpose);
		$purpose->setActivityUuid($activity?->getUuid());
	}//end hydrate()

	/**
	 * Resolve a path identifier that may be an id, a uuid or a code.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return ProcessingPurpose|null The purpose, or null.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function resolve(string $identifier): ?ProcessingPurpose {
		if (ctype_digit($identifier) === true) {
			try {
				return $this->purposes->find((int)$identifier);
			} catch (Throwable $e) {
				return null;
			}
		}

		return $this->purposes->resolveReference(reference: $identifier);
	}//end resolve()

	/**
	 * The purpose as the API publishes it, with the binding resolved live.
	 *
	 * @param ProcessingPurpose $purpose The purpose.
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function serialize(ProcessingPurpose $purpose): array {
		$activity = $this->registry->boundActivity(purpose: $purpose);

		$body = $purpose->jsonSerialize();
		$body['bound'] = ($activity !== null);
		$body['activityUuid'] = $activity?->getUuid();
		$body['activityName'] = $activity?->getName();
		$body['usable'] = ($activity !== null && $purpose->getStatus() === ProcessingPurpose::STATUS_ACTIVE);

		return $body;
	}//end serialize()

	/**
	 * Read an optional string parameter.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return string|null The trimmed value, or null when absent or empty.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function optionalParam(string $key): ?string {
		$value = $this->request->getParam(key: $key);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end optionalParam()

	/**
	 * Read an optional ISO 8601 date parameter.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return DateTime|null The parsed date, or null when absent or unparseable.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function optionalDate(string $key): ?DateTime {
		$value = $this->optionalParam(key: $key);
		if ($value === null) {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end optionalDate()

	/**
	 * Whether the caller is an instance administrator.
	 *
	 * @return bool True when the caller is in the admin group.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return in_array(
			needle: 'admin',
			haystack: $this->groupManager->getUserGroupIds($user),
			strict: true
		);
	}//end isAdmin()

	/**
	 * The unauthenticated response.
	 *
	 * @return JSONResponse HTTP 401.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Authentication required'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
	}//end unauthorized()

	/**
	 * The non-admin response.
	 *
	 * @return JSONResponse HTTP 403.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Administering the purpose list requires the admin group'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end forbidden()
}//end class
