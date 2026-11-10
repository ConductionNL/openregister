<?php

/**
 * OpenRegister DSAR case-management controller.
 *
 * HTTP surface for the imperative DSAR case engine (dsar-case-engine): create a
 * case, run a lifecycle transition (including `draftDenial` / `finaliseDenial`,
 * the latter through the {@see DenialFinaliseGuard}), trigger evidence harvest,
 * apply a field-level redaction, generate the signed export bundle, download it
 * once via a one-time token, and assemble the regulator dossier.
 *
 * All endpoints are authenticated steward endpoints (`@NoAdminRequired`), never
 * `@PublicPage`; `@NoCSRFRequired` is added ONLY to the download route (a
 * browser navigation that cannot supply a CSRF token). Every method that acts on
 * an existing case loads it through {@see CaseObjectAccessorInterface} (ObjectService,
 * RBAC + multitenancy) and gates it with {@see CaseAccessControlInterface} (handler-
 * scopes-own + officer-override, fail closed) BEFORE any effect — avoiding IDOR
 * (ADR-023 / OWASP A01).
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\DsarCaseControllerServices;
use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControlInterface;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessorInterface;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistryInterface;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolverInterface;
use OCA\OpenRegister\Service\Gdpr\Redaction\FieldRedaction;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistryInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * RBAC + case-level access controlled DSAR case-management endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Transport controller wiring the case-engine services + object store.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor-injected case-engine collaborators + framework appName/request.
 */
class DsarCaseController extends Controller {
	/**
	 * RBAC + tenant scoped object store (case creation).
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * RBAC-scoped case load helper.
	 *
	 * @var CaseObjectAccessorInterface
	 */
	private readonly CaseObjectAccessorInterface $accessor;

	/**
	 * Case-level access-control check (fail closed).
	 *
	 * @var CaseAccessControlInterface
	 */
	private readonly CaseAccessControlInterface $accessControl;

	/**
	 * Lifecycle transition driver (runs the guard on save).
	 *
	 * @var TransitionEngine
	 */
	private readonly TransitionEngine $transitionEngine;

	/**
	 * Evidence-collection service.
	 *
	 * @var EvidenceHarvestServiceInterface
	 */
	private readonly EvidenceHarvestServiceInterface $harvestService;

	/**
	 * Field-level redaction write path.
	 *
	 * @var RedactionWriteServiceInterface
	 */
	private readonly RedactionWriteServiceInterface $redactionService;

	/**
	 * Export-bundle + dossier service.
	 *
	 * @var ExportBundleServiceInterface
	 */
	private readonly ExportBundleServiceInterface $bundleService;

	/**
	 * Current caller (anonymous rejection).
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Resolves the case's active pack seam selectors.
	 *
	 * @var DsarPolicyPackResolverInterface
	 */
	private readonly DsarPolicyPackResolverInterface $packResolver;

	/**
	 * Identity-verify seam registry (pack-selector-driven, fail-closed).
	 *
	 * @var IdentityVerifyRegistryInterface
	 */
	private readonly IdentityVerifyRegistryInterface $identityRegistry;

	/**
	 * Regulator-escalate seam registry (pack-selector-driven, fail-closed).
	 *
	 * @var RegulatorEscalateRegistryInterface
	 */
	private readonly RegulatorEscalateRegistryInterface $regulatorRegistry;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param DsarCaseControllerServices $services The dsar case controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		DsarCaseControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->objectService = $services->cases->objectService;
		$this->accessor = $services->cases->accessor;
		$this->accessControl = $services->cases->accessControl;
		$this->transitionEngine = $services->cases->transitionEngine;
		$this->harvestService = $services->disclosure->harvestService;
		$this->redactionService = $services->disclosure->redactionService;
		$this->bundleService = $services->disclosure->bundleService;
		$this->userSession = $services->platform->userSession;
		$this->packResolver = $services->disclosure->packResolver;
		$this->identityRegistry = $services->registries->identityRegistry;
		$this->regulatorRegistry = $services->registries->regulatorRegistry;

	}//end __construct()

	/**
	 * POST /api/gdpr/cases — create a data-subject-request case.
	 *
	 * The body is persisted through ObjectService (RBAC + multitenancy) under
	 * the case register/schema. There is no pre-existing object to case-scope
	 * here; object RBAC on the write governs creation.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function create(): JSONResponse {
		$denied = $this->requireAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = (array)$this->request->getParams();
		// Strip framework-injected keys so only the case payload is saved, and
		// the caller's IDENTITY with them. `saveObject()` resolves its target
		// from the payload (`@self.id` first, then `id`) and the write is
		// PUT-semantic, so a create carrying either would overwrite an existing
		// case and null every field it omitted. This endpoint creates.
		$self = (array)($body['@self'] ?? []);
		unset($self['id'], $self['uuid']);
		unset($body['_route'], $body['id'], $body['uuid'], $body['@self']);
		if ($self !== []) {
			$body['@self'] = $self;
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $body,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: CaseObjectAccessorInterface::REGISTER_SLUG, schema: CaseObjectAccessorInterface::SCHEMA_SLUG)
				),
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $saved->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * POST /api/gdpr/cases/{id}/transition — run a declared lifecycle transition.
	 *
	 * The `action` param names the transition (e.g. `assign`, `collectEvidence`,
	 * `draftDenial`, `finaliseDenial`). `finaliseDenial` passes through the
	 * denial-finalise guard on save. Case-scoped + object-RBAC gated.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function transition(string $id): JSONResponse {
		$guard = $this->guardCase(caseUuid: $id);
		if ($guard !== null) {
			return $guard;
		}

		$action = (string)($this->request->getParam(key: 'action') ?? '');
		if ($action === '') {
			return new JSONResponse(
				data: ['error' => 'A transition "action" is required.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$saved = $this->transitionEngine->transition(objectId: $id, action: $action);
		} catch (Throwable $e) {
			// Guard denial / illegal transition surface as 403.
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(data: $saved->jsonSerialize());
	}//end transition()

	/**
	 * POST /api/gdpr/cases/{id}/evidence — trigger evidence harvest for a case.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function evidence(string $id): JSONResponse {
		$guard = $this->guardCase(caseUuid: $id);
		if ($guard !== null) {
			return $guard;
		}

		try {
			$result = $this->harvestService->harvest(caseUuid: $id);
		} catch (Throwable $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result);
	}//end evidence()

	/**
	 * POST /api/gdpr/cases/{id}/redactions — apply a field-level redaction.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/dsar-redaction-write/spec.md
	 */
	public function redact(string $id): JSONResponse {
		$guard = $this->guardCase(caseUuid: $id);
		if ($guard !== null) {
			return $guard;
		}

		$field = (string)($this->request->getParam(key: 'field') ?? '');
		$after = (string)($this->request->getParam(key: 'after') ?? '');
		$ground = (string)($this->request->getParam(key: 'ground') ?? '');
		if ($field === '' || $ground === '') {
			return new JSONResponse(
				data: ['error' => 'Both "field" and "ground" are required.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->redactionService->applyRedaction(
				caseUuid: $id,
				redaction: new FieldRedaction(field: $field, after: $after, ground: $ground)
			);
		} catch (Throwable $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result);
	}//end redact()

	/**
	 * POST /api/gdpr/cases/{id}/bundle — generate the signed export bundle.
	 *
	 * Returns the bundle metadata + a one-time download token (not the bytes).
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function generateBundle(string $id): JSONResponse {
		$guard = $this->guardCase(caseUuid: $id);
		if ($guard !== null) {
			return $guard;
		}

		try {
			$result = $this->bundleService->generate(caseUuid: $id);
		} catch (Throwable $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
	}//end generateBundle()

	/**
	 * GET /api/gdpr/cases/{id}/bundle/download — one-time secure download.
	 *
	 * Requires the one-time `token` param; the token is burned on first success
	 * so a replay is refused. Authenticated + case-scoped (never `@PublicPage`).
	 * `@NoCSRFRequired` because this is a browser navigation download that
	 * cannot carry a CSRF token — auth + case scope + one-time token remain.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function downloadBundle(string $id) {
		$denied = $this->requireAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		// Case-scope the download: the token is bound to this case AND the
		// caller must pass the case-level access control.
		$case = $this->accessor->load(caseUuid: $id);
		if ($case === null || $this->accessControl->mayAct(case: $case->getObject()) === false) {
			return new JSONResponse(
				data: ['error' => 'Not found or not authorised.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$token = (string)($this->request->getParam(key: 'token') ?? '');
		$bundle = $this->bundleService->download(caseUuid: $id, token: $token);
		if ($bundle === null) {
			return new JSONResponse(
				data: ['error' => 'Invalid, expired, or already-used download token.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new DataDownloadResponse(
			$bundle->getBytes(),
			'dsar-case-' . $id . '.pdf',
			$bundle->getMimeType()
		);
	}//end downloadBundle()

	/**
	 * GET /api/gdpr/cases/{id}/dossier — assemble the regulator dossier.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function dossier(string $id): JSONResponse {
		$guard = $this->guardCase(caseUuid: $id);
		if ($guard !== null) {
			return $guard;
		}

		try {
			$result = $this->bundleService->assembleRegulatorDossier(caseUuid: $id);
		} catch (Throwable $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result);
	}//end dossier()

	/**
	 * POST /api/gdpr/cases/{id}/verify-identity — verify the data-subject's
	 * identity via the pack-selected identity-verify seam provider.
	 *
	 * Resolves the provider named by the case's active `dsarPolicyPack`
	 * `identityVerifyProvider` selector through {@see IdentityVerifyRegistryInterface}
	 * (never a hardcoded provider) and runs it. Resolution is FAIL-CLOSED: an
	 * unset/unknown selector resolves to the OR default that returns `needs-more`
	 * (never `verified`), so a missing provider can never pass identity
	 * verification (ADR-005 / CWE-863). The three-state result is recorded on the
	 * case and returned to the caller so the case flow can drive its transition.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function identityVerify(string $id): JSONResponse {
		$guard = $this->guardCase(caseUuid: $id);
		if ($guard !== null) {
			return $guard;
		}

		$case = $this->accessor->load(caseUuid: $id);
		if ($case === null) {
			return new JSONResponse(
				data: ['error' => 'Not found or not authorised.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$data = $case->getObject();

		// Resolve the provider from the active pack selector through the
		// registry — never a hardcoded provider. resolve() ALWAYS returns a
		// provider (the fail-closed default when unset/unknown), so there is no
		// null branch to (mis)treat as "verified".
		$selectorId = $this->packResolver->identityVerifyProviderId(case: $data);
		$provider = $this->identityRegistry->resolve(selectorId: $selectorId);
		$result = $provider->verify(caseUuid: $id, case: $data);

		// Record the three-state outcome on the case (audited via the accessor).
		$data['identityVerification'] = $result->toArray();
		try {
			$this->accessor->save(case: $case, data: $data);
		} catch (Throwable $e) {
			// Persisting the record failed, but the verification DECISION stands
			// — surface it as a 400 rather than pretend the case verified.
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'status' => $result->getStatus()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result->toArray());
	}//end identityVerify()

	/**
	 * POST /api/gdpr/cases/{id}/escalate — escalate the case to a supervisory
	 * authority via the pack-selected regulator-escalate seam provider.
	 *
	 * Resolves the provider named by the case's active `dsarPolicyPack`
	 * `regulatorEscalateProvider` selector through {@see RegulatorEscalateRegistryInterface}
	 * (never a hardcoded provider) and runs it. Resolution is FAIL-CLOSED: an
	 * unset/unknown selector resolves to the OR default that REFUSES (never a
	 * silent success), so a case is never recorded as escalated when the seam is
	 * unbound (ADR-005 / CWE-863). Only an `escalated` outcome writes the
	 * `regulatorReference` that the dossier/denial-finalise gate reads.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function escalate(string $id): JSONResponse {
		$guard = $this->guardCase(caseUuid: $id);
		if ($guard !== null) {
			return $guard;
		}

		$case = $this->accessor->load(caseUuid: $id);
		if ($case === null) {
			return new JSONResponse(
				data: ['error' => 'Not found or not authorised.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$data = $case->getObject();

		$selectorId = $this->packResolver->regulatorEscalateProviderId(case: $data);
		$provider = $this->regulatorRegistry->resolve(selectorId: $selectorId);
		$result = $provider->escalate(caseUuid: $id, case: $data);

		// Record the outcome. Only a positive escalation writes the
		// regulatorReference the denial-finalise gate + dossier consume — a
		// refusal MUST NOT mint or claim a reference.
		$data['regulatorEscalation'] = $result->toArray();
		if ($result->isEscalated() === true && $result->getReference() !== '') {
			$data['regulatorReference'] = $result->getReference();
		}

		try {
			$this->accessor->save(case: $case, data: $data);
		} catch (Throwable $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'status' => $result->getStatus()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result->toArray());
	}//end escalate()

	/**
	 * Reject anonymous callers before any case is read or written.
	 *
	 * @return JSONResponse|null A 401 response when unauthenticated, else null.
	 */
	private function requireAuthenticated(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Authentication required.'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		return null;
	}//end requireAuthenticated()

	/**
	 * Per-object RBAC + case-level access guard shared by every acting method.
	 *
	 * Loads the case under the caller's object RBAC (null when absent OR
	 * unauthorised — no enumeration oracle) and then applies the case-level
	 * access-control check (handler-scopes-own + officer-override, fail closed).
	 * Returns a response to short-circuit on denial, or null to proceed.
	 *
	 * @param string $caseUuid The case uuid from the route.
	 *
	 * @return JSONResponse|null Denial response, or null when the caller may act.
	 */
	private function guardCase(string $caseUuid): ?JSONResponse {
		$denied = $this->requireAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$case = $this->accessor->load(caseUuid: $caseUuid);
		if ($case === null) {
			// Absent OR object-RBAC-denied — indistinguishable (no oracle).
			return new JSONResponse(
				data: ['error' => 'Not found or not authorised.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		if ($this->accessControl->mayAct(case: $case->getObject()) === false) {
			return new JSONResponse(
				data: ['error' => 'You are not authorised to act on this case.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end guardCase()
}//end class
