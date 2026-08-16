<?php

declare(strict_types=1);

/*
 * DsarCaseController Unit Tests
 *
 * Verifies the case-management controller's auth posture (every method
 *
 * @NoAdminRequired, never @PublicPage, @NoCSRFRequired only on the download),
 * route↔method reachability (no orphan methods, no orphan routes), anonymous
 * rejection, and the case-level access guard (handler-own allowed, others
 * refused, fail-closed).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DsarCaseController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Export\SignedBundle;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;
use OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Test class for DsarCaseController.
 */
class DsarCaseControllerTest extends TestCase {

	/**
	 * The controller methods that back the case-management routes.
	 *
	 * @var array<int, string>
	 */
	private const ROUTED_METHODS = [
		'create',
		'transition',
		'evidence',
		'redact',
		'generateBundle',
		'downloadBundle',
		'dossier',
		'identityVerify',
		'escalate',
	];

	/**
	 * Whether a docblock declares the given annotation as an actual tag line
	 * (e.g. ` * @PublicPage`), not merely a prose mention inside a sentence.
	 * Mirrors the route-auth gate's line-anchored annotation match.
	 *
	 * @param string $doc The docblock text.
	 * @param string $annotation The annotation name without the leading `@`.
	 *
	 * @return bool True when the annotation appears as a tag line.
	 */
	private function hasAnnotation(string $doc, string $annotation): bool {
		return (bool)preg_match('/^\s*\*\s*@' . preg_quote($annotation, '/') . '\b/m', $doc);
	}//end hasAnnotation()

	/**
	 * Build a controller wired with mocks + an optional session user.
	 *
	 * @param IUserSession|null $userSession Session (null → build one with a
	 *                                       user).
	 * @param CaseObjectAccessor|null $accessor Accessor mock.
	 * @param CaseAccessControl|null $access Access-control mock.
	 *
	 * @return DsarCaseController
	 */
	private function build(
		?IUserSession $userSession = null,
		?CaseObjectAccessor $accessor = null,
		?CaseAccessControl $access = null,
	): DsarCaseController {
		if ($userSession === null) {
			$userSession = $this->createMock(IUserSession::class);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('handler1');
			$userSession->method('getUser')->willReturn($user);
		}

		return new DsarCaseController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(ObjectService::class),
			($accessor ?? $this->createMock(CaseObjectAccessor::class)),
			($access ?? $this->createMock(CaseAccessControl::class)),
			$this->createMock(TransitionEngine::class),
			$this->createMock(EvidenceHarvestService::class),
			$this->createMock(RedactionWriteService::class),
			$this->createMock(ExportBundleService::class),
			$userSession,
			$this->createMock(DsarPolicyPackResolver::class),
			$this->createMock(IdentityVerifyRegistry::class),
			$this->createMock(RegulatorEscalateRegistry::class)
		);

	}//end build()

	/**
	 * Every routed method declares @NoAdminRequired and never @PublicPage.
	 *
	 * @return void
	 */
	public function testEveryMethodIsNoAdminRequiredAndNeverPublic(): void {
		$reflection = new \ReflectionClass(DsarCaseController::class);
		foreach (self::ROUTED_METHODS as $method) {
			$doc = (string)$reflection->getMethod($method)->getDocComment();
			$this->assertTrue(
				$this->hasAnnotation($doc, 'NoAdminRequired'),
				$method . ' must be @NoAdminRequired'
			);
			$this->assertFalse(
				$this->hasAnnotation($doc, 'PublicPage'),
				$method . ' must never be @PublicPage'
			);
		}

	}//end testEveryMethodIsNoAdminRequiredAndNeverPublic()

	/**
	 * @NoCSRFRequired appears ONLY on the download method.
	 *
	 * @return void
	 */
	public function testNoCsrfOnlyOnDownload(): void {
		$reflection = new \ReflectionClass(DsarCaseController::class);
		foreach (self::ROUTED_METHODS as $method) {
			$doc = (string)$reflection->getMethod($method)->getDocComment();
			$hasNoCsrf = $this->hasAnnotation($doc, 'NoCSRFRequired');
			if ($method === 'downloadBundle') {
				$this->assertTrue($hasNoCsrf, 'download must be @NoCSRFRequired');
			} else {
				$this->assertFalse($hasNoCsrf, $method . ' must NOT be @NoCSRFRequired');
			}
		}

	}//end testNoCsrfOnlyOnDownload()

	/**
	 * Route↔method reachability: every case route targets an existing method,
	 * and every routed method has a route (no orphans, ADR-016/029).
	 *
	 * @return void
	 */
	public function testRouteMethodReachability(): void {
		$routes = (include __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$mapped = [];
		foreach ($routes as $route) {
			if (str_starts_with((string)$route['name'], 'dsarCase#') === true) {
				$mapped[] = substr((string)$route['name'], strlen('dsarCase#'));
			}
		}

		$reflection = new \ReflectionClass(DsarCaseController::class);

		// No orphan route: every mapped method exists on the controller.
		foreach ($mapped as $method) {
			$this->assertTrue($reflection->hasMethod($method), 'route targets missing method ' . $method);
		}

		// No orphan method: every routed method is mapped.
		sort($mapped);
		$expected = self::ROUTED_METHODS;
		sort($expected);
		$this->assertSame($expected, $mapped);

	}//end testRouteMethodReachability()

	/**
	 * Anonymous callers are rejected before any case is touched (401).
	 *
	 * @return void
	 */
	public function testAnonymousRejected(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->expects($this->never())->method('load');

		$controller = $this->build($userSession, $accessor);

		$response = $controller->evidence('case-1');
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousRejected()

	/**
	 * The guard refuses when case-level access control denies (403), and does
	 * not proceed to the harvest.
	 *
	 * @return void
	 */
	public function testAccessDeniedRefusesWithForbidden(): void {
		$case = new ObjectEntity();
		$case->setObject(['handler' => 'handler2']);
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn($case);

		$access = $this->createMock(CaseAccessControl::class);
		$access->method('mayAct')->willReturn(false);

		$harvest = $this->createMock(EvidenceHarvestService::class);
		$harvest->expects($this->never())->method('harvest');

		$controller = new DsarCaseController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(ObjectService::class),
			$accessor,
			$access,
			$this->createMock(TransitionEngine::class),
			$harvest,
			$this->createMock(RedactionWriteService::class),
			$this->createMock(ExportBundleService::class),
			$this->userSessionWith('handler1'),
			$this->createMock(DsarPolicyPackResolver::class),
			$this->createMock(IdentityVerifyRegistry::class),
			$this->createMock(RegulatorEscalateRegistry::class)
		);

		$response = $controller->evidence('case-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAccessDeniedRefusesWithForbidden()

	/**
	 * A missing/unauthorised case yields 404 (no enumeration oracle).
	 *
	 * @return void
	 */
	public function testMissingCaseYields404(): void {
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn(null);

		$controller = $this->build($this->userSessionWith('handler1'), $accessor);

		$response = $controller->dossier('case-x');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testMissingCaseYields404()

	/**
	 * identityVerify resolves the seam through the registry from the pack
	 * selector and, with no leaf provider bound, fails closed (needs-more).
	 *
	 * @return void
	 */
	public function testIdentityVerifyFailsClosedThroughRegistry(): void {
		$case = new ObjectEntity();
		$case->setObject(['handler' => 'handler1', 'jurisdiction' => 'default']);

		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn($case);
		$accessor->method('save')->willReturn($case);

		$access = $this->createMock(CaseAccessControl::class);
		$access->method('mayAct')->willReturn(true);

		// The pack selects the OR default id; the registry resolves it to the
		// fail-closed default (exercised via the real Null provider).
		$resolver = $this->createMock(DsarPolicyPackResolver::class);
		$resolver->method('identityVerifyProviderId')->willReturn('or.default.identity-verify.null');

		$identityRegistry = $this->createMock(IdentityVerifyRegistry::class);
		$identityRegistry->expects($this->once())
			->method('resolve')
			->with('or.default.identity-verify.null')
			->willReturn(new NullIdentityVerifyProvider());

		$controller = $this->buildWithSeams(
			accessor: $accessor,
			access: $access,
			resolver: $resolver,
			identityRegistry: $identityRegistry,
			regulatorRegistry: $this->createMock(RegulatorEscalateRegistry::class)
		);

		$response = $controller->identityVerify('case-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('needs-more', $response->getData()['status']);
	}//end testIdentityVerifyFailsClosedThroughRegistry()

	/**
	 * escalate resolves the seam through the registry and, with no leaf
	 * provider bound, refuses — never claims success, never mints a reference.
	 *
	 * @return void
	 */
	public function testEscalateFailsClosedThroughRegistry(): void {
		$case = new ObjectEntity();
		$case->setObject(['handler' => 'handler1', 'jurisdiction' => 'default']);

		$captured = null;
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn($case);
		$accessor->method('save')->willReturnCallback(
			function ($case, $data) use (&$captured) {
				$captured = $data;
				return $case;
			}
		);

		$access = $this->createMock(CaseAccessControl::class);
		$access->method('mayAct')->willReturn(true);

		$resolver = $this->createMock(DsarPolicyPackResolver::class);
		$resolver->method('regulatorEscalateProviderId')->willReturn('or.default.regulator-escalate.null');

		$regulatorRegistry = $this->createMock(RegulatorEscalateRegistry::class);
		$regulatorRegistry->expects($this->once())
			->method('resolve')
			->with('or.default.regulator-escalate.null')
			->willReturn(new NullRegulatorEscalateProvider());

		$controller = $this->buildWithSeams(
			accessor: $accessor,
			access: $access,
			resolver: $resolver,
			identityRegistry: $this->createMock(IdentityVerifyRegistry::class),
			regulatorRegistry: $regulatorRegistry
		);

		$response = $controller->escalate('case-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('refused', $response->getData()['status']);
		// A refusal MUST NOT write a regulatorReference on the case.
		$this->assertArrayNotHasKey('regulatorReference', (array)$captured);
	}//end testEscalateFailsClosedThroughRegistry()

	/**
	 * Build a controller with explicit seam collaborators for the seam tests.
	 *
	 * @param CaseObjectAccessor $accessor Case load/save mock.
	 * @param CaseAccessControl $access Access-control mock.
	 * @param DsarPolicyPackResolver $resolver Pack-selector resolver mock.
	 * @param IdentityVerifyRegistry $identityRegistry Identity seam registry mock.
	 * @param RegulatorEscalateRegistry $regulatorRegistry Regulator seam registry mock.
	 *
	 * @return DsarCaseController
	 */
	private function buildWithSeams(
		CaseObjectAccessor $accessor,
		CaseAccessControl $access,
		DsarPolicyPackResolver $resolver,
		IdentityVerifyRegistry $identityRegistry,
		RegulatorEscalateRegistry $regulatorRegistry,
	): DsarCaseController {
		return new DsarCaseController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(ObjectService::class),
			$accessor,
			$access,
			$this->createMock(TransitionEngine::class),
			$this->createMock(EvidenceHarvestService::class),
			$this->createMock(RedactionWriteService::class),
			$this->createMock(ExportBundleService::class),
			$this->userSessionWith('handler1'),
			$resolver,
			$identityRegistry,
			$regulatorRegistry
		);
	}//end buildWithSeams()

	/**
	 * Build a user session for a uid.
	 *
	 * @param string $uid The caller uid.
	 *
	 * @return IUserSession
	 */
	private function userSessionWith(string $uid): IUserSession {
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession->method('getUser')->willReturn($user);
		return $userSession;
	}//end userSessionWith()

	/**
	 * Build a controller for the redaction / export-bundle surface.
	 *
	 * The case guard is satisfied by default (a loaded case the handler may act
	 * on) so the tests below assert the endpoint's own contract rather than the
	 * guard, which is already covered above.
	 *
	 * @param IRequest $request The request carrying the body params.
	 * @param RedactionWriteService $redaction Redaction write path.
	 * @param ExportBundleService $bundles Export-bundle service.
	 * @param bool $mayAct Whether case-level access control allows the caller.
	 *
	 * @return DsarCaseController
	 */
	private function buildForBundleSurface(
		IRequest $request,
		RedactionWriteService $redaction,
		ExportBundleService $bundles,
		bool $mayAct = true,
	): DsarCaseController {
		$case = new ObjectEntity();
		$case->setObject(['handler' => 'handler1']);

		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn($case);

		$access = $this->createMock(CaseAccessControl::class);
		$access->method('mayAct')->willReturn($mayAct);

		return new DsarCaseController(
			'openregister',
			$request,
			$this->createMock(ObjectService::class),
			$accessor,
			$access,
			$this->createMock(TransitionEngine::class),
			$this->createMock(EvidenceHarvestService::class),
			$redaction,
			$bundles,
			$this->userSessionWith('handler1'),
			$this->createMock(DsarPolicyPackResolver::class),
			$this->createMock(IdentityVerifyRegistry::class),
			$this->createMock(RegulatorEscalateRegistry::class)
		);
	}//end buildForBundleSurface()

	/**
	 * A request whose body params come from a simple map.
	 *
	 * @param array<string,mixed> $params The body params.
	 *
	 * @return IRequest
	 */
	private function requestWith(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
		return $request;
	}//end requestWith()

	/**
	 * redact() forwards the field, replacement and legal ground to the write
	 * service and returns its result.
	 *
	 * @return void
	 */
	public function testRedactAppliesTheFieldLevelRedaction(): void {
		$redaction = $this->createMock(RedactionWriteService::class);
		$redaction->expects($this->once())
			->method('applyRedaction')
			->with('case-1', 'evidence.0.bsn', '[REDACTED]', 'art-15-4')
			->willReturn(['field' => 'evidence.0.bsn', 'ground' => 'art-15-4', 'applied' => true]);

		$controller = $this->buildForBundleSurface(
			$this->requestWith(
				['field' => 'evidence.0.bsn', 'after' => '[REDACTED]', 'ground' => 'art-15-4']
			),
			$redaction,
			$this->createMock(ExportBundleService::class)
		);

		$response = $controller->redact('case-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['applied']);
	}//end testRedactAppliesTheFieldLevelRedaction()

	/**
	 * A redaction without a legal ground is refused with 400 — the ground is
	 * what makes the redaction defensible, so it is never optional.
	 *
	 * @return void
	 */
	public function testRedactRequiresAFieldAndAGround(): void {
		$redaction = $this->createMock(RedactionWriteService::class);
		$redaction->expects($this->never())->method('applyRedaction');

		$controller = $this->buildForBundleSurface(
			$this->requestWith(['field' => 'evidence.0.bsn']),
			$redaction,
			$this->createMock(ExportBundleService::class)
		);

		$response = $controller->redact('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}//end testRedactRequiresAFieldAndAGround()

	/**
	 * generateBundle() answers 201 with the bundle metadata + one-time token —
	 * never the bytes.
	 *
	 * @return void
	 */
	public function testGenerateBundleReturns201WithTheDownloadToken(): void {
		$bundles = $this->createMock(ExportBundleService::class);
		$bundles->expects($this->once())
			->method('generate')
			->with('case-1')
			->willReturn(
				[
					'contentHash' => 'sha256:abc',
					'signatureState' => 'unsigned',
					'downloadToken' => 'tok-1',
				]
			);

		$controller = $this->buildForBundleSurface(
			$this->requestWith([]),
			$this->createMock(RedactionWriteService::class),
			$bundles
		);

		$response = $controller->generateBundle('case-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('tok-1', $response->getData()['downloadToken']);
		$this->assertArrayNotHasKey('bytes', $response->getData());
	}//end testGenerateBundleReturns201WithTheDownloadToken()

	/**
	 * A refusal from the bundle service is a 400 carrying the reason, not a
	 * fatal.
	 *
	 * @return void
	 */
	public function testGenerateBundleReports400WhenTheServiceRefuses(): void {
		$bundles = $this->createMock(ExportBundleService::class);
		$bundles->method('generate')
			->willThrowException(new \RuntimeException('Case "case-1" is not in a releasable state.'));

		$controller = $this->buildForBundleSurface(
			$this->requestWith([]),
			$this->createMock(RedactionWriteService::class),
			$bundles
		);

		$response = $controller->generateBundle('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('releasable', $response->getData()['error']);
	}//end testGenerateBundleReports400WhenTheServiceRefuses()

	/**
	 * downloadBundle() streams the signed bytes once the one-time token is
	 * redeemed.
	 *
	 * @return void
	 */
	public function testDownloadBundleReturnsTheSignedBytes(): void {
		$bundle = new SignedBundle('%PDF-1.7 bytes', 'sha256:abc', false, 'unsigned', 'application/pdf');

		$bundles = $this->createMock(ExportBundleService::class);
		$bundles->expects($this->once())
			->method('download')
			->with('case-1', 'tok-1')
			->willReturn($bundle);

		$controller = $this->buildForBundleSurface(
			$this->requestWith(['token' => 'tok-1']),
			$this->createMock(RedactionWriteService::class),
			$bundles
		);

		$response = $controller->downloadBundle('case-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('%PDF-1.7 bytes', $response->render());
	}//end testDownloadBundleReturnsTheSignedBytes()

	/**
	 * A replayed / expired / unknown token is refused with 403 and no bytes.
	 *
	 * @return void
	 */
	public function testDownloadBundleRefusesAnUnredeemableToken(): void {
		$bundles = $this->createMock(ExportBundleService::class);
		$bundles->method('download')->willReturn(null);

		$controller = $this->buildForBundleSurface(
			$this->requestWith(['token' => 'already-used']),
			$this->createMock(RedactionWriteService::class),
			$bundles
		);

		$response = $controller->downloadBundle('case-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testDownloadBundleRefusesAnUnredeemableToken()

	/**
	 * The download is case-scoped: a caller the case-level access control
	 * refuses gets 404, and the token is never even looked at.
	 *
	 * @return void
	 */
	public function testDownloadBundleReturns404WhenCaseAccessIsDenied(): void {
		$bundles = $this->createMock(ExportBundleService::class);
		$bundles->expects($this->never())->method('download');

		$controller = $this->buildForBundleSurface(
			$this->requestWith(['token' => 'tok-1']),
			$this->createMock(RedactionWriteService::class),
			$bundles,
			false
		);

		$response = $controller->downloadBundle('case-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testDownloadBundleReturns404WhenCaseAccessIsDenied()
}//end class
