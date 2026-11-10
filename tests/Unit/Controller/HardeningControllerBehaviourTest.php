<?php

/**
 * Behaviour test for HardeningController: elevation, floors and control changes.
 *
 * Asserts that every write first requires a fresh sign-in, that a lapsed
 * elevation answers 403, a floor refusal 409 and invalid input 400, what is
 * written for each control, floor and origin list, and how elevation answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\HardeningController;
use OCA\OpenRegister\Controller\Support\HardeningControllerServices;
use OCA\OpenRegister\Service\Hardening\ElevationRequiredException;
use OCA\OpenRegister\Service\Hardening\ElevationServiceInterface;
use OCA\OpenRegister\Service\Hardening\FloorBreach;
use OCA\OpenRegister\Service\Hardening\HardeningFloorException;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportServiceInterface;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsServiceInterface;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HardeningControllerBehaviourTest extends TestCase {

	/** @var HardeningSettingsServiceInterface&MockObject The settings writer. */
	private HardeningSettingsServiceInterface $settings;

	/** @var ElevationServiceInterface&MockObject The elevation gate. */
	private ElevationServiceInterface $elevation;

	/** @var HardeningReportServiceInterface&MockObject The report builder. */
	private HardeningReportServiceInterface $report;

	/** @var string The stored floors JSON. */
	private string $declared = '';

	protected function setUp(): void {
		$this->settings = $this->createMock(HardeningSettingsServiceInterface::class);
		$this->elevation = $this->createMock(ElevationServiceInterface::class);
		$this->report = $this->createMock(HardeningReportServiceInterface::class);
	}

	private function controller(array $body = [], ?string $password = null): HardeningController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);
		$request->method('getParam')->with('password')->willReturn($password);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($key === HardeningPolicy::FLOORS_KEY) {
					return $this->declared;
				}

				return $default;
			}
		);
		$services = new HardeningControllerServices($this->report, $this->settings, new HardeningPolicy($appConfig), $this->elevation);
		return new HardeningController($request, $services);
	}

	private function floorRefusal(): HardeningFloorException {
		return new HardeningFloorException(new FloorBreach('auth.rateLimit.attemptsPerIdentity', 20, 50, 'atMost'), 'Below the floor');
	}

	public function testReportServesWhatTheServiceBuilds(): void {
		$this->report->method('report')->willReturn(['score' => 7]);
		$this->assertSame(['score' => 7], $this->controller()->report()->getData());
	}

	public function testFloorsListsEveryControlWithItsBaselineComparatorAndDeclaredFloors(): void {
		$this->declared = '{"auth.rateLimit.attemptsPerIdentity": 10, "bad": "x"}';
		$data = $this->controller()->floors()->getData();

		$this->assertSame(array_keys($data['floors']), array_keys($data['baselines']));
		$this->assertSame(array_keys($data['floors']), array_keys($data['comparators']));
		$this->assertSame(20, $data['baselines']['auth.rateLimit.attemptsPerIdentity']);
		$this->assertSame('atMost', $data['comparators']['auth.rateLimit.attemptsPerIdentity']);
		$this->assertSame(86400, $data['baselines']['session.lifetimeSeconds']);
		$this->assertSame(['auth.rateLimit.attemptsPerIdentity' => 10], $data['declared']);
	}

	public function testUpdateControlsWritesEachControlAndTheOrigins(): void {
		$this->elevation->expects($this->once())->method('requireElevated');
		$this->settings->expects($this->exactly(2))->method('setControl')
			->willReturnCallback(static fn (string $control, int $value): int => $value + 1);
		$this->settings->expects($this->once())->method('setAllowedOrigins')->with(['https://a.example'])->willReturn(['https://a.example']);

		$response = $this->controller(
			['controls' => ['auth.rateLimit.windowSeconds' => '900', 'admin.elevationSeconds' => 600], 'allowedOrigins' => ['https://a.example']]
		)->updateControls();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['controls' => ['auth.rateLimit.windowSeconds' => 901, 'admin.elevationSeconds' => 601], 'allowedOrigins' => ['https://a.example']],
			$response->getData()
		);
	}

	public function testUpdateControlsIgnoresNonListControlsAndNonListOrigins(): void {
		$this->settings->expects($this->never())->method('setControl');
		$this->settings->expects($this->never())->method('setAllowedOrigins');
		$response = $this->controller(['controls' => 'all', 'allowedOrigins' => 'https://a.example'])->updateControls();
		$this->assertSame(['controls' => []], $response->getData());
	}

	public function testUpdateControlsNeedsAFreshSignIn(): void {
		$this->elevation->method('requireElevated')->willThrowException(new ElevationRequiredException(900));
		$this->settings->expects($this->never())->method('setControl');
		$response = $this->controller(['controls' => ['auth.rateLimit.windowSeconds' => 1]])->updateControls();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertTrue($response->getData()['elevationRequired']);
		$this->assertSame(900, $response->getData()['periodSeconds']);
	}

	public function testUpdateControlsAnswersConflictBelowAFloor(): void {
		$this->settings->method('setControl')->willThrowException($this->floorRefusal());
		$response = $this->controller(['controls' => ['auth.rateLimit.attemptsPerIdentity' => 50]])->updateControls();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(20, $response->getData()['floor']);
		$this->assertSame(50, $response->getData()['proposed']);
	}

	public function testUpdateControlsAnswersBadRequestForAnUnknownControl(): void {
		$this->settings->method('setControl')->willThrowException(new InvalidArgumentException('Unknown control x'));
		$response = $this->controller(['controls' => ['x' => 1]])->updateControls();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Unknown control x'], $response->getData());
	}

	public function testUpdateFloorsRefusesANonMapBeforeAskingForElevation(): void {
		$this->elevation->expects($this->never())->method('requireElevated');
		$response = $this->controller(['floors' => 'high'])->updateFloors();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Send floors as a map of control to number.', $response->getData()['error']);
	}

	public function testUpdateFloorsWritesEachFloor(): void {
		$this->elevation->expects($this->once())->method('requireElevated');
		$this->settings->expects($this->once())->method('setFloor')->with('auth.rateLimit.windowSeconds', 1200)->willReturn(1200);
		$response = $this->controller(['floors' => ['auth.rateLimit.windowSeconds' => '1200']])->updateFloors();
		$this->assertSame(['floors' => ['auth.rateLimit.windowSeconds' => 1200]], $response->getData());
	}

	public function testUpdateFloorsWithoutFloorsWritesNothing(): void {
		$this->settings->expects($this->never())->method('setFloor');
		$this->assertSame(['floors' => []], $this->controller([])->updateFloors()->getData());
	}

	public function testUpdateFloorsNeedsAFreshSignIn(): void {
		$this->elevation->method('requireElevated')->willThrowException(new ElevationRequiredException(300));
		$this->settings->expects($this->never())->method('setFloor');
		$response = $this->controller(['floors' => ['a' => 1]])->updateFloors();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdateFloorsAnswersConflictAndBadRequest(): void {
		$this->settings->method('setFloor')->willReturnOnConsecutiveCalls(
			$this->throwException($this->floorRefusal()),
			$this->throwException(new InvalidArgumentException('No such control'))
		);
		$this->assertSame(Http::STATUS_CONFLICT, $this->controller(['floors' => ['a' => 1]])->updateFloors()->getStatus());
		$bad = $this->controller(['floors' => ['b' => 1]])->updateFloors();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus());
		$this->assertSame(['error' => 'No such control'], $bad->getData());
	}

	public function testAWrongPasswordIsRefusedAndThrottled(): void {
		$this->elevation->expects($this->once())->method('elevate')->with('')->willReturn(false);
		$response = $this->controller([], null)->elevate();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertTrue($response->getData()['elevationRequired']);
		$this->assertTrue($response->isThrottled());
		$this->assertSame(['action' => 'openregister_elevation'], $response->getThrottleMetadata());
	}

	public function testAConfirmedPasswordAnswersWithThePeriod(): void {
		$this->elevation->expects($this->once())->method('elevate')->with('secret')->willReturn(true);
		$this->elevation->method('periodSeconds')->willReturn(900);
		$this->elevation->method('remainingSeconds')->willReturn(899);
		$response = $this->controller([], 'secret')->elevate();

		$this->assertSame(['elevated' => true, 'periodSeconds' => 900, 'remainingSeconds' => 899], $response->getData());
		$this->assertFalse($response->isThrottled());
	}
}
