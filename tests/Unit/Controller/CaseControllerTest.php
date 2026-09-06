<?php

/**
 * The case REST surface's HTTP translation, and the contract test for every
 * /api/cases endpoint (hydra gate-25): each route exists in appinfo/routes.php,
 * resolves to a public method, carries its auth posture, and the service's
 * exceptions map to the documented statuses. Also the two structural
 * invariants the spec states as absences: no CMMN endpoint exists, and
 * nothing under lib/Service/Flow references lib/Service/Case.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CaseController;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Exception\CaseCascadeBoundException;
use OCA\OpenRegister\Exception\CaseTransitionException;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * HTTP translation, route contract and structural absences.
 *
 * @covers \OCA\OpenRegister\Controller\CaseController
 */
class CaseControllerTest extends TestCase {

	/**
	 * The service, mocked.
	 *
	 * @var CasePlanService&MockObject
	 */
	private CasePlanService&MockObject $plans;

	/**
	 * The request, mocked.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->plans = $this->createMock(CasePlanService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn([]);
	}//end setUp()

	/**
	 * A controller with a session for `alice` (or none).
	 *
	 * @param string|null $uid The session user.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CaseController The controller.
	 */
	private function controller(?string $uid = 'alice', ?LoggerInterface $logger = null): CaseController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$authorization = $this->createMock(CasePlanAuthorizationService::class);
		$authorization->method('assertIdentified')->willReturnCallback(
			static function (?string $uid, string $verb): string {
				if ($uid === null) {
					throw new CaseAccessDeniedException("Verb '$verb' denied: no acting identity.");
				}

				return $uid;
			}
		);

		return new CaseController(
			appName: 'openregister',
			request: $this->request,
			plans: $this->plans,
			zaaktypes: new ZaaktypeCaseSkeletonMapper(),
			authorization: $authorization,
			userSession: $session,
			logger: $logger
		);
	}//end controller()

	/**
	 * The routes this controller serves, from appinfo/routes.php.
	 *
	 * @return array<int, array<string, mixed>> The case# routes.
	 */
	private static function caseRoutes(): array {
		$routes = include __DIR__ . '/../../../appinfo/routes.php';
		$all = ($routes['routes'] ?? []);

		return array_values(array_filter($all, static fn (array $route): bool => str_starts_with((string)($route['name'] ?? ''), 'case#')));
	}//end caseRoutes()

	/**
	 * gate-25: every case route resolves to a public method carrying both attributes.
	 *
	 * @return void
	 */
	public function testEveryCaseRouteResolvesToAnAttributedPublicMethod(): void {
		$routes = self::caseRoutes();
		$this->assertCount(11, $routes);
		$reflection = new ReflectionClass(CaseController::class);
		foreach ($routes as $route) {
			$method = substr((string)$route['name'], strlen('case#'));
			$this->assertTrue($reflection->hasMethod($method), "route {$route['name']} names a method");
			$target = $reflection->getMethod($method);
			$this->assertTrue($target->isPublic());
			$this->assertNotEmpty($target->getAttributes(NoAdminRequired::class), "$method declares its posture");
			$this->assertNotEmpty($target->getAttributes(NoCSRFRequired::class), "$method decides CSRF explicitly");
			$this->assertStringStartsWith('/api/cases', (string)$route['url']);
		}

		// The literal routes precede the {objectUuid} route, or they are swallowed.
		$urls = array_column($routes, 'url');
		$this->assertLessThan(array_search('/api/cases/{objectUuid}', $urls, true), array_search('/api/cases/items', $urls, true));
		$this->assertLessThan(array_search('/api/cases/{objectUuid}', $urls, true), array_search('/api/cases/skeleton-from-zaaktype', $urls, true));
	}//end testEveryCaseRouteResolvesToAnAttributedPublicMethod()

	/**
	 * No CMMN endpoint exists anywhere in the route table, and no case-layer
	 * file mentions CMMN XML as something it parses or emits.
	 *
	 * @return void
	 */
	public function testNoCmmnEndpointExists(): void {
		$routes = include __DIR__ . '/../../../appinfo/routes.php';
		foreach (($routes['routes'] ?? []) as $route) {
			$this->assertStringNotContainsStringIgnoringCase('cmmn', (string)($route['url'] ?? ''));
			$this->assertStringNotContainsStringIgnoringCase('cmmn', (string)($route['name'] ?? ''));
		}

		foreach (glob(__DIR__ . '/../../../lib/Service/Case/*.php') as $file) {
			$source = (string)file_get_contents($file);
			$this->assertDoesNotMatchRegularExpression('/simplexml|DOMDocument|xml_parse|<cmmn|CMMNDI/i', $source, basename($file) . ' handles no CMMN XML');
		}
	}//end testNoCmmnEndpointExists()

	/**
	 * Dependency direction: nothing under lib/Service/Flow depends on lib/Service/Case.
	 *
	 * @return void
	 */
	public function testTheEngineNeverReferencesTheCaseLayer(): void {
		$files = glob(__DIR__ . '/../../../lib/Service/Flow/{,*/}*.php', GLOB_BRACE);
		$this->assertNotEmpty($files);
		foreach ($files as $file) {
			$this->assertStringNotContainsString('Service\\Case\\', (string)file_get_contents($file), basename($file));
		}

		// And the case layer never touches marking-writing surfaces.
		foreach (glob(__DIR__ . '/../../../lib/Service/Case/*.php') as $file) {
			$source = (string)file_get_contents($file);
			$this->assertDoesNotMatchRegularExpression('/setMarking|setStatus\(|setLog\(|FlowRunAdvancer|FlowEngine\b/', $source, basename($file));
		}
	}//end testTheEngineNeverReferencesTheCaseLayer()

	/**
	 * Reads and the success statuses.
	 *
	 * @return void
	 */
	public function testSuccessfulRoutesTranslateTheServiceResult(): void {
		$item = new CaseItem();
		$item->setUuid('i-1');
		$item->setState(CaseItem::STATE_ACTIVE);
		$this->plans->method('getPlan')->willReturn(['objectUuid' => 'o', 'items' => []]);
		$this->plans->method('createPlan')->willReturn(['objectUuid' => 'o', 'items' => [1]]);
		$this->plans->method('evaluate')->willReturn(['passes' => 2, 'transitions' => 3, 'skipped' => false]);
		$this->plans->method('enableableItems')->willReturn([['key' => 'advice']]);
		$this->plans->method('attachAdHoc')->willReturn($item);
		$this->plans->method('completeCase')->willReturn(['result' => 'verleend']);
		$this->plans->method('deletePlan')->willReturn(4);
		$this->plans->method('transition')->willReturn($item);
		$this->plans->method('enableDiscretionary')->willReturn($item);
		$this->plans->method('findStuck')->willReturn(['results' => [], 'total' => 0, 'limit' => 25, 'offset' => 0]);
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_OK, $controller->show('o')->getStatus());
		$this->assertSame(Http::STATUS_CREATED, $controller->create('o', 1, 1, ['items' => []])->getStatus());
		$this->assertSame(3, $controller->evaluate('o')->getData()['transitions']);
		$this->assertSame([['key' => 'advice']], $controller->enableable('o')->getData()['results']);
		$this->assertSame(Http::STATUS_CREATED, $controller->attach('o', 'k', 'humanTask')->getStatus());
		$this->assertSame('verleend', $controller->complete('o', 'verleend')->getData()['result']);
		$this->assertSame(4, $controller->destroy('o')->getData()['deleted']);
		$this->assertSame('i-1', $controller->transition('i-1', 'completed')->getData()['uuid']);
		$this->assertSame('active', $controller->enable('i-1')->getData()['state']);
		$this->assertSame(0, $controller->items()->getData()['total']);

		$skeleton = $controller->skeletonFromZaaktype(['statustypen' => [['volgnummer' => 1, 'omschrijving' => 'A']]]);
		$this->assertSame(Http::STATUS_OK, $skeleton->getStatus());
		$this->assertTrue($skeleton->getData()['draft']);
	}//end testSuccessfulRoutesTranslateTheServiceResult()

	/**
	 * Missing required body fields are 400 before the service is asked.
	 *
	 * @return void
	 */
	public function testMissingFieldsAreRefusedBeforeTheService(): void {
		$this->plans->expects($this->never())->method($this->anything());
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->transition('i-1', null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->transition('i-1', ' ')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->complete('o', null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->skeletonFromZaaktype(null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->skeletonFromZaaktype([])->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: null)->skeletonFromZaaktype(['a' => 1])->getStatus());
	}//end testMissingFieldsAreRefusedBeforeTheService()

	/**
	 * The exception-to-status table, and the 500 that logs instead of echoing.
	 *
	 * @return void
	 */
	public function testServiceExceptionsMapToStatuses(): void {
		$map = [
			[new DoesNotExistException('x'), Http::STATUS_NOT_FOUND, 'Not found'],
			[new CaseAccessDeniedException('denied'), Http::STATUS_FORBIDDEN, 'denied'],
			[new CaseValidationException('refused'), Http::STATUS_BAD_REQUEST, 'refused'],
			[new CaseTransitionException('illegal'), Http::STATUS_CONFLICT, 'illegal'],
			[new CaseCascadeBoundException('bound'), Http::STATUS_UNPROCESSABLE_ENTITY, 'bound'],
		];
		foreach ($map as [$exception, $status, $message]) {
			$this->plans = $this->createMock(CasePlanService::class);
			$this->plans->method('getPlan')->willThrowException($exception);
			$response = $this->controller()->show('o');
			$this->assertSame($status, $response->getStatus(), get_class($exception));
			$this->assertSame($message, $response->getData()['error']);
		}

		$this->plans = $this->createMock(CasePlanService::class);
		$this->plans->method('getPlan')->willThrowException(new RuntimeException('secret detail'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('secret detail'));
		$response = $this->controller(logger: $logger)->show('o');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Internal error', $response->getData()['error'], 'The detail is logged, not echoed.');
	}//end testServiceExceptionsMapToStatuses()

	/**
	 * attach() forwards a body `authorization` key so the service can refuse it.
	 *
	 * @return void
	 */
	public function testAttachForwardsASelfDeclaredAuthorizationForRefusal(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['authorization' => ['me']]);
		$this->plans->expects($this->once())->method('attachAdHoc')->with(
			'o',
			$this->callback(static fn (array $data): bool => $data['authorization'] === ['me'] && $data['required'] === false && $data['key'] === 'k' && array_key_exists('name', $data) === false),
			'alice'
		)->willThrowException(new CaseValidationException('An ad-hoc item cannot declare its own authorization'));

		$response = $this->controller()->attach('o', 'k', 'humanTask', null, null, 'intake', null, null, null, null, null, null, null, false);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAttachForwardsASelfDeclaredAuthorizationForRefusal()

	/**
	 * Every public route method returns a JSONResponse.
	 *
	 * @return void
	 */
	public function testEveryRouteMethodReturnsJson(): void {
		foreach ((new ReflectionClass(CaseController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() === true || $method->getDeclaringClass()->getName() !== CaseController::class) {
				continue;
			}

			$this->assertSame('OCP\AppFramework\Http\JSONResponse', (string)$method->getReturnType(), $method->getName());
		}
	}//end testEveryRouteMethodReturnsJson()
}//end class
