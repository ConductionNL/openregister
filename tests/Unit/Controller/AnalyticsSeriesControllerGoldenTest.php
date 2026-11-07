<?php

/**
 * Golden tests for AnalyticsSeriesController.
 *
 * Pins the argument mapping from request params to
 * AnalyticsSeriesService::register(), the guard order, and every response
 * of register and fetch so a refactor can be proven behaviour-preserving.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\AnalyticsSeriesController;
use OCA\OpenRegister\Service\AnalyticsSeriesService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for AnalyticsSeriesController.
 */
class AnalyticsSeriesControllerGoldenTest extends TestCase {
	private IRequest&MockObject $request;
	private AnalyticsSeriesService&MockObject $service;
	private AnalyticsSeriesController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AnalyticsSeriesService::class);
		$this->controller = new AnalyticsSeriesController('openregister', $this->request, $this->service);
	}

	public function testRegisterAppliesDefaultsForAnEmptyBody(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->expects($this->once())->method('ensureCanRegister');
		$this->service->expects($this->once())->method('register')
			->with('', [], [], null, 'line', 'private', null, null)
			->willReturn(['seriesKey' => '']);

		$response = $this->controller->register();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['seriesKey' => ''], $response->getData());
	}

	public function testRegisterCoercesEveryParam(): void {
		$this->request->method('getParams')->willReturn(
			[
				'seriesKey' => 42,
				'labels' => 'jan',
				'datasets' => [['data' => [1]]],
				'title' => 7,
				'chartType' => 'bar',
				'visibility' => 'group',
				'registerId' => '12',
				'schemaId' => 3.0,
			]
		);
		$this->service->expects($this->once())->method('register')
			->with('42', ['jan'], [['data' => [1]]], '7', 'bar', 'group', 12, 3)
			->willReturn(['ok' => true]);

		$this->assertSame(['ok' => true], $this->controller->register()->getData());
	}

	public function testRegisterTreatsNullTitleAndNonNumericIdsAsAbsent(): void {
		$this->request->method('getParams')->willReturn(
			['seriesKey' => 'k', 'title' => null, 'registerId' => 'abc', 'schemaId' => null, 'chartType' => null]
		);
		$this->service->expects($this->once())->method('register')
			->with('k', [], [], null, 'line', 'private', null, null)
			->willReturn([]);

		$this->assertSame(201, $this->controller->register()->getStatus());
	}

	public function testRegisterKeepsAnEmptyStringTitle(): void {
		$this->request->method('getParams')->willReturn(['seriesKey' => 'k', 'title' => '']);
		$this->service->expects($this->once())->method('register')
			->with('k', [], [], '', 'line', 'private', null, null)
			->willReturn([]);

		$this->controller->register();
	}

	public function testRegisterGuardRefusalSkipsTheWrite(): void {
		$this->request->method('getParams')->willReturn(['seriesKey' => 'k']);
		$this->service->method('ensureCanRegister')->willThrowException(new InvalidArgumentException('login'));
		$this->service->expects($this->never())->method('register');

		$response = $this->controller->register();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'login'], $response->getData());
	}

	public function testRegisterValidationFailureIs400(): void {
		$this->request->method('getParams')->willReturn(['seriesKey' => 'k']);
		$this->service->method('register')->willThrowException(new InvalidArgumentException('bad chart'));

		$response = $this->controller->register();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'bad chart'], $response->getData());
	}

	public function testFetchFound(): void {
		$this->service->expects($this->once())->method('ensureReadableOrNull')->with('k')->willReturn(['a' => 1]);

		$response = $this->controller->fetch('k');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['a' => 1], $response->getData());
	}

	public function testFetchNotFound(): void {
		$this->service->method('ensureReadableOrNull')->willReturn(null);

		$response = $this->controller->fetch('k');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'Not Found'], $response->getData());
	}
}
