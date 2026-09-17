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
use OCA\OpenRegister\Service\Hardening\HardeningFloorException;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
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
	private function controller(array $body = []): HardeningController {
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

		return new HardeningController(
			'openregister',
			$request,
			$this->reportService,
			$this->settings,
			new HardeningPolicy($appConfig),
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
}
