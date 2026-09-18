<?php

/**
 * HardeningControllerTest — the contract test for the four hardening reads and
 * writes (gate 25).
 *
 * The status code is the thing to get wrong quietly. A refused weakening is a
 * well-formed request the instance will not carry out, so it answers 409. A
 * client that reads 400 goes looking for a typo in its own payload and never
 * finds the floor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\HardeningController;
use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\HardeningFloorException;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCA\OpenRegister\Service\Hardening\StatementService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\HardeningController
 */
class HardeningControllerTest extends TestCase {

	/**
	 * The stubbed settings service.
	 *
	 * @var HardeningSettingsService&MockObject
	 */
	private HardeningSettingsService&MockObject $settings;

	/**
	 * The stubbed report service.
	 *
	 * @var HardeningReportService&MockObject
	 */
	private HardeningReportService&MockObject $reportService;

	/**
	 * Build the controller over a stubbed body.
	 *
	 * @param array<string, mixed> $body The request parameters.
	 *
	 * @return HardeningController The controller.
	 */
	/**
	 * The stubbed statement service.
	 *
	 * @var StatementService&MockObject
	 */
	private StatementService&MockObject $statements;

	/**
	 * The stubbed elevation guard.
	 *
	 * @var ElevationService&MockObject
	 */
	private ElevationService&MockObject $elevation;

	private function controller(array $body = [], bool $elevated = true, string $uid = 'admin'): HardeningController {
		$this->reportService = $this->createMock(HardeningReportService::class);
		$this->reportService->method('report')->willReturn(
			['meetsAllFloors' => true, 'failing' => [], 'controls' => []]
		);

		$this->settings = $this->createMock(HardeningSettingsService::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => $default
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($body[$key] ?? $default)
		);

		$this->statements = $this->createMock(StatementService::class);

		// The guard is a double of the real class, with `onlyMethods`, so it
		// cannot grow a method ElevationService does not have.
		$this->elevation = $this->getMockBuilder(ElevationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireElevated', 'elevate', 'periodSeconds', 'remainingSeconds', 'isElevated', 'drop'])
			->getMock();
		$this->elevation->method('periodSeconds')->willReturn(900);
		$this->elevation->method('remainingSeconds')->willReturn(($elevated === true) ? 600 : 0);
		$this->elevation->method('isElevated')->willReturn($elevated);
		if ($elevated === false) {
			$this->elevation->method('requireElevated')
				->willThrowException(new \OCA\OpenRegister\Service\Hardening\ElevationRequiredException(periodSeconds: 900));
		}

		$userSession = $this->createMock(IUserSession::class);
		if ($uid !== '') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		return new HardeningController(
			'openregister',
			$request,
			$this->reportService,
			$this->settings,
			new HardeningPolicy($appConfig),
			$this->statements,
			$this->elevation,
			$userSession,
		);
	}

	public function testTheReportIsServedAsItIsBuilt(): void {
		$response = $this->controller()->report();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['meetsAllFloors']);
	}

	public function testTheFloorsAnswerCarriesTheBaselineAndTheDirection(): void {
		$response = $this->controller()->floors();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('auth.rateLimit.lockoutSeconds', $data['floors']);
		$this->assertSame(900, $data['baselines']['auth.rateLimit.lockoutSeconds']);
		$this->assertSame('atLeast', $data['comparators']['auth.rateLimit.lockoutSeconds']);
		$this->assertSame('atMost', $data['comparators']['session.lifetimeSeconds']);
		$this->assertSame([], $data['declared']);
	}

	public function testAnAcceptedChangeAnswersWithWhatIsNowInForce(): void {
		$controller = $this->controller(body: ['controls' => ['auth.rateLimit.lockoutSeconds' => 3600]]);
		$this->settings->method('setControl')->willReturn(3600);

		$response = $controller->updateControls();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3600, $response->getData()['controls']['auth.rateLimit.lockoutSeconds']);
	}

	public function testARefusedWeakeningAnswers409AndNamesTheFloor(): void {
		$controller = $this->controller(body: ['controls' => ['auth.rateLimit.lockoutSeconds' => 60]]);
		$this->settings->method('setControl')->willThrowException(
			new HardeningFloorException(
				control: 'auth.rateLimit.lockoutSeconds',
				floor: 900,
				proposed: 60,
				comparator: 'atLeast',
				message: 'This instance declared a floor of at least 900 for auth.rateLimit.lockoutSeconds.',
			)
		);

		$response = $controller->updateControls();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('auth.rateLimit.lockoutSeconds', $data['control']);
		$this->assertSame(900, $data['floor']);
		$this->assertSame(60, $data['proposed']);
	}

	public function testAnUnknownControlAnswers400(): void {
		$controller = $this->controller(body: ['controls' => ['invented.control' => 1]]);
		$this->settings->method('setControl')->willThrowException(
			new InvalidArgumentException('This instance does not administer invented.control.')
		);

		$response = $controller->updateControls();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('invented.control', $response->getData()['error']);
	}

	public function testAnAllowlistIsAnsweredBackNormalised(): void {
		$controller = $this->controller(body: ['allowedOrigins' => [' https://Example.nl ']]);
		$this->settings->method('setAllowedOrigins')->willReturn(['https://example.nl']);

		$response = $controller->updateControls();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['https://example.nl'], $response->getData()['allowedOrigins']);
	}

	public function testADeclaredFloorAnswersWithWhatIsNowInForce(): void {
		$controller = $this->controller(body: ['floors' => ['auth.rateLimit.lockoutSeconds' => 1800]]);
		$this->settings->method('setFloor')->willReturn(1800);

		$response = $controller->updateFloors();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1800, $response->getData()['floors']['auth.rateLimit.lockoutSeconds']);
	}

	public function testAFloorWeakerThanTheBaselineAnswers409(): void {
		$controller = $this->controller(body: ['floors' => ['auth.rateLimit.attemptsPerIdentity' => 200]]);
		$this->settings->method('setFloor')->willThrowException(
			new HardeningFloorException(
				control: 'auth.rateLimit.attemptsPerIdentity',
				floor: 20,
				proposed: 200,
				comparator: 'atMost',
				message: 'The floor may not be weaker than the shipped baseline of 20.',
			)
		);

		$response = $controller->updateFloors();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(20, $response->getData()['floor']);
	}

	public function testFloorsSentAsSomethingOtherThanAMapAnswer400(): void {
		$controller = $this->controller(body: ['floors' => 'all of them']);

		$response = $controller->updateFloors();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
	// ---- REQ-IHC-002: a write needs a fresh sign-in, not an open session. ---

	/**
	 * The least privileged principal that should be refused here is an
	 * administrator whose elevated period has lapsed: they hold the session and
	 * the admin group, and still may not weaken a control.
	 */
	public function testAControlChangeIsRefusedWhenTheElevatedPeriodHasLapsed(): void {
		$controller = $this->controller(['controls' => ['auth.rateLimit.attemptsPerIdentity' => 5]], elevated: false);
		$this->settings->expects($this->never())->method('setControl');

		$response = $controller->updateControls();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertTrue($response->getData()['elevationRequired']);
		$this->assertSame(900, $response->getData()['periodSeconds']);
	}

	public function testAFloorChangeIsRefusedWhenTheElevatedPeriodHasLapsed(): void {
		$controller = $this->controller(['floors' => ['auth.rateLimit.windowSeconds' => 1200]], elevated: false);
		$this->settings->expects($this->never())->method('setFloor');

		$response = $controller->updateFloors();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertTrue($response->getData()['elevationRequired']);
	}

	public function testAWrongPasswordAnswers401AndElevatesNothing(): void {
		$controller = $this->controller(['password' => 'wrong'], elevated: false);
		$this->elevation->method('elevate')->willReturn(false);

		$response = $controller->elevate();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertArrayNotHasKey('elevated', $response->getData());
	}

	public function testAConfirmedPasswordAnswersWithThePeriod(): void {
		$controller = $this->controller(['password' => 'right']);
		$this->elevation->method('elevate')->willReturn(true);

		$response = $controller->elevate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['elevated']);
		$this->assertSame(900, $response->getData()['periodSeconds']);
	}

	// ---- REQ-IHC-001: the statement, and whose acceptance it is. -----------

	public function testTheStatementAnswersAboutTheSessionsOwnAccount(): void {
		$controller = $this->controller(uid: 'medewerker');
		$this->statements->expects($this->once())
			->method('needsAcceptance')
			->with('medewerker')
			->willReturn(true);
		$this->statements->method('published')->willReturn(
			['version' => '3', 'title' => 'Verwerking', 'body' => 'text', 'publishedAt' => '', 'publishedBy' => 'admin']
		);
		$this->statements->method('acceptanceOf')->willReturn(null);

		$response = $controller->statement();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['needsAcceptance']);
	}

	/**
	 * A request naming somebody else changes nothing: the id is the session's.
	 */
	public function testAnAcceptanceIsRecordedAgainstTheSessionAndNotAgainstAUserIdInTheBody(): void {
		$controller = $this->controller(['version' => '3', 'userId' => 'directeur'], uid: 'medewerker');
		$this->statements->expects($this->once())
			->method('accept')
			->with('medewerker', '3')
			->willReturn(['version' => '3', 'acceptedAt' => '2026-09-18T10:00:00+02:00']);

		$response = $controller->acceptStatement();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('3', $response->getData()['version']);
	}

	public function testAnAnonymousCallerAcceptsNothing(): void {
		$controller = $this->controller(['version' => '3'], uid: '');
		$this->statements->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->acceptStatement()->getStatus());
	}

	public function testPublishingAStatementIsAnAdministrationWriteAndNeedsTheFreshSignIn(): void {
		$controller = $this->controller(['version' => '4', 'body' => 'text'], elevated: false);
		$this->statements->expects($this->never())->method('publish');

		$response = $controller->publishStatement();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testWithdrawingAStatementNeedsTheFreshSignInToo(): void {
		$controller = $this->controller([], elevated: false);
		$this->statements->expects($this->never())->method('withdraw');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->withdrawStatement()->getStatus());
	}

	/**
	 * The auth posture is part of the contract, and it is invisible in a unit
	 * test that calls the method directly: no middleware runs. So read the
	 * attributes. Only the statement read and the acceptance may be called by
	 * an ordinary account; everything else is administrator-only, and a
	 * `#[NoAdminRequired]` added to one of them later fails here.
	 */
	public function testOnlyTheStatementReadAndTheAcceptanceAreOpenToAnOrdinaryAccount(): void {
		$open = ['statement', 'acceptStatement'];
		$closed = ['report', 'floors', 'updateControls', 'updateFloors', 'elevate', 'publishStatement', 'withdrawStatement'];

		foreach (array_merge($open, $closed) as $method) {
			$attributes = (new \ReflectionMethod(HardeningController::class, $method))
				->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class);

			$this->assertSame(
				in_array($method, $open, true),
				($attributes !== []),
				sprintf('%s has the wrong auth posture', $method)
			);
		}
	}
}