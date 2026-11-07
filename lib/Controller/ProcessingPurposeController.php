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
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Closure;
use DateTime;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\GdprRegisterAccess;
use OCA\OpenRegister\Controller\Support\GdprRegisterReplies;
use OCA\OpenRegister\Controller\Support\GdprRequestParams;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\ProcessingPurposeMapper;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
class ProcessingPurposeController extends Controller {
	/**
	 * The caller and the admin check.
	 *
	 * @var GdprRegisterAccess
	 */
	private readonly GdprRegisterAccess $access;

	/**
	 * The refusal envelopes.
	 *
	 * @var GdprRegisterReplies
	 */
	private readonly GdprRegisterReplies $replies;

	/**
	 * The optional query parameters.
	 *
	 * @var GdprRequestParams
	 */
	private readonly GdprRequestParams $params;

	/**
	 * Constructor.
	 *
	 * @param string                    $appName    App identifier.
	 * @param IRequest                  $request    Active request.
	 * @param ProcessingPurposeMapper   $purposes   The administered list.
	 * @param PurposeRegistry           $registry   Resolves a purpose to its activity.
	 * @param AuditTrailMapperInterface $auditTrail Counts entries per purpose.
	 * @param ControllerSession         $session    Session, group manager and logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ProcessingPurposeMapper $purposes,
		private readonly PurposeRegistry $registry,
		private readonly AuditTrailMapperInterface $auditTrail,
		ControllerSession $session,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->access = new GdprRegisterAccess(session: $session);
		$this->replies = new GdprRegisterReplies();
		$this->params = new GdprRequestParams(request: $request);
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
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function index(): JSONResponse {
		if ($this->access->user() === null) {
			return $this->replies->unauthorized();
		}

		$rows = $this->purposes->findAll(
			status: $this->params->trimmed(key: 'status'),
			organisationId: $this->params->trimmed(key: 'organisation')
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
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function show(string $id): JSONResponse {
		if ($this->access->user() === null) {
			return $this->replies->unauthorized();
		}

		$purpose = $this->resolve(identifier: $id);
		if ($purpose === null) {
			return $this->replies->notFound(error: 'Not Found', identifier: $id);
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
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function create(): JSONResponse {
		if ($this->access->isAdmin() === false) {
			return $this->forbidden();
		}

		$code = trim((string)($this->request->getParam(key: 'code') ?? ''));
		$refusal = $this->refuseCode(code: $code);
		if ($refusal !== null) {
			return $refusal;
		}

		$purpose = new ProcessingPurpose();
		$purpose->setCode($code);
		$this->hydrate(purpose: $purpose);

		return $this->persist(write: fn () => $this->purposes->insert($purpose), status: Http::STATUS_CREATED);
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
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function update(string $id): JSONResponse {
		$purpose = $this->administered(identifier: $id);
		if ($purpose instanceof JSONResponse) {
			return $purpose;
		}

		$this->hydrate(purpose: $purpose);

		return $this->persist(write: fn () => $this->purposes->update($purpose), status: Http::STATUS_OK);
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
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function destroy(string $id): JSONResponse {
		$purpose = $this->administered(identifier: $id);
		if ($purpose instanceof JSONResponse) {
			return $purpose;
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
	 * Admin-only at the framework level (no @NoAdminRequired), with the body
	 * check as defence-in-depth: the counts cover every entry of the trail,
	 * whichever organisation it belongs to.
	 *
	 * @return JSONResponse The counts, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md#requirement-the-instance-wide-audit-reads-are-admin-only
	 */
	public function report(): JSONResponse {
		if ($this->access->user() === null) {
			return $this->replies->unauthorized();
		}

		if ($this->access->isAdmin() === false) {
			return $this->replies->forbidden(
				message: 'The purpose report counts the whole trail and requires the admin group'
			);
		}

		$counts = $this->auditTrail->countByPurpose(from: $this->dateParam(key: 'from'), to: $this->dateParam(key: 'to'));

		return new JSONResponse(
			data: ['counts' => $counts, 'total' => array_sum($counts), 'unattributed' => ($counts['null'] ?? 0)]
		);
	}//end report()

	/**
	 * The refusal for a code a new purpose may not take, or null.
	 *
	 * @param string $code The trimmed code.
	 *
	 * @return JSONResponse|null HTTP 422 when blank or taken, else null.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function refuseCode(string $code): ?JSONResponse {
		if ($code === '') {
			return $this->replies->unprocessable(message: 'code is required');
		}

		if ($this->purposes->findByCode(code: $code) !== null) {
			return new JSONResponse(
				data: ['error' => 'A purpose with that code already exists', 'code' => $code],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return null;
	}//end refuseCode()

	/**
	 * The purpose an administrator asked to change, or the refusal.
	 *
	 * The admin check runs before the purpose is looked up.
	 *
	 * @param string $identifier The path identifier.
	 *
	 * @return ProcessingPurpose|JSONResponse The purpose, or HTTP 403 / 404.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function administered(string $identifier): ProcessingPurpose|JSONResponse {
		if ($this->access->isAdmin() === false) {
			return $this->forbidden();
		}

		$purpose = $this->resolve(identifier: $identifier);
		if ($purpose === null) {
			return $this->replies->notFound(error: 'Not Found', identifier: $identifier);
		}

		return $purpose;
	}//end administered()

	/**
	 * Write a purpose and publish it, or answer 422 with the failure.
	 *
	 * @param Closure $write  Inserts or updates the purpose and returns it.
	 * @param int      $status The status of the success response.
	 *
	 * @return JSONResponse The persisted purpose, or HTTP 422.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function persist(Closure $write, int $status): JSONResponse {
		$persisted = $this->replies->attempt(write: $write, catch: Throwable::class);
		if ($persisted instanceof JSONResponse) {
			return $persisted;
		}

		return new JSONResponse(data: $this->serialize(purpose: $persisted), statusCode: $status);
	}//end persist()

	/**
	 * Write the mutable fields from the request onto a purpose.
	 *
	 * @param ProcessingPurpose $purpose The purpose to fill.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ProcessingPurpose::isValidStatus is the
	 *   entity's own vocabulary check, the same shape Verwerkingsactiviteit uses.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function hydrate(ProcessingPurpose $purpose): void {
		foreach (['name', 'description', 'activity', 'organisationId'] as $field) {
			$value = $this->params->trimmed(key: $field);
			if ($value !== null) {
				$purpose->{'set' . ucfirst($field)}($value);
			}
		}

		$status = $this->params->trimmed(key: 'status');
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
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
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
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
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
	 * Read an optional ISO 8601 date parameter.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return DateTime|null The parsed date, or null when absent or unparseable.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function dateParam(string $key): ?DateTime {
		return $this->params->date(value: $this->params->trimmed(key: $key));
	}//end dateParam()

	/**
	 * The non-admin response.
	 *
	 * @return JSONResponse HTTP 403.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function forbidden(): JSONResponse {
		return $this->replies->forbidden(message: 'Administering the purpose list requires the admin group');
	}//end forbidden()
}//end class
