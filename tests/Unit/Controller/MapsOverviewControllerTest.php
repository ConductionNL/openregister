<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\MapsOverviewController}.
 *
 * Covers the RBAC-scoped marker query. The point set comes from
 * {@see \OCA\OpenRegister\Service\MapsOverviewService::ensureReadablePoints()},
 * which runs the canonical OpenRegister read path with `_rbac: true`; the
 * controller must return exactly those rows plus their count, and must surface
 * an invalid register/schema selector as a 400 rather than a fatal.
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
 *
 * @spec openspec/specs/integration-maps-overview/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\MapsOverviewController;
use OCA\OpenRegister\Service\MapsOverviewService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * MapsOverviewControllerTest.
 */
class MapsOverviewControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Map overview register/query service mock.
	 *
	 * @var MapsOverviewService&MockObject
	 */
	private MapsOverviewService&MockObject $overviews;

	/**
	 * Controller under test.
	 *
	 * @var MapsOverviewController
	 */
	private MapsOverviewController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->overviews = $this->createMock(MapsOverviewService::class);

		$this->controller = new MapsOverviewController(
			'openregister',
			$this->request,
			$this->overviews
		);
	}//end setUp()

	public function testPointsReturnsTheRbacScopedPointSetWithItsCount(): void {
		$this->request->method('getParams')->willReturn(
			['register' => 'zaken', 'schema' => 'zaak', 'geoProperty' => 'locatie', 'limit' => '25']
		);

		$points = [
			['id' => 1, 'label' => 'Zaak A', 'lat' => 52.0, 'lng' => 5.1],
			['id' => 2, 'label' => 'Zaak B', 'lat' => 52.3, 'lng' => 4.9],
		];

		$this->overviews->expects($this->once())
			->method('ensureReadablePoints')
			->with('zaken', 'zaak', [], 'locatie', 25)
			->willReturn($points);

		$response = $this->controller->points('zaken', 'zaak');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(2, $data['count']);
		$this->assertSame($points, $data['points']);
	}//end testPointsReturnsTheRbacScopedPointSetWithItsCount()

	public function testPointsTreatsNonReservedQueryParamsAsObjectFilters(): void {
		$this->request->method('getParams')->willReturn(
			['register' => 'zaken', 'schema' => 'zaak', '_route' => 'x', 'status' => 'open']
		);

		$this->overviews->expects($this->once())
			->method('ensureReadablePoints')
			->with('zaken', 'zaak', ['status' => 'open'], null, null)
			->willReturn([]);

		$response = $this->controller->points('zaken', 'zaak');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(0, $response->getData()['count']);
		$this->assertSame([], $response->getData()['points']);
	}//end testPointsTreatsNonReservedQueryParamsAsObjectFilters()

	public function testPointsReturns400WhenTheSelectorIsRejected(): void {
		$this->request->method('getParams')->willReturn([]);

		$this->overviews->method('ensureReadablePoints')
			->willThrowException(new InvalidArgumentException('Unknown register: nope'));

		$response = $this->controller->points('nope', 'zaak');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Unknown register: nope', $response->getData()['message']);
	}//end testPointsReturns400WhenTheSelectorIsRejected()
}//end class
