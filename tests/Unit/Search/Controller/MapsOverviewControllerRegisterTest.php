<?php

/**
 * MapsOverviewController guards and forwards the overview declaration.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Search\Controller\MapsOverviewController;
use OCA\OpenRegister\Search\Service\MapMarkerSource;
use OCA\OpenRegister\Search\Service\MapsOverviewService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Search\Service\MapOverview;

/**
 * The register endpoint, and the points branches the existing test leaves:
 * an explicit filters object and a non-numeric limit.
 */
class MapsOverviewControllerRegisterTest extends TestCase {

	private IRequest&MockObject $request;
	private MapsOverviewService&MockObject $overviews;
	private MapsOverviewController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->overviews = $this->createMock(MapsOverviewService::class);
		$this->controller = new MapsOverviewController($this->request, $this->overviews);
	}

	public function testRegisterForwardsEveryOptionalFieldAndAnswers201(): void {
		$this->request->method('getParams')->willReturn(
			[
				'overviewKey' => 'zaken-kaart', 'register' => 5, 'schema' => 'zaak', 'title' => 'Zaken',
				'geoProperty' => 'locatie', 'filters' => ['status' => 'open'], 'baseLayer' => ['url' => 'tiles'],
			]
		);
		$this->overviews->expects($this->once())->method('ensureCanRegister');
		$this->overviews->expects($this->once())->method('declareOverview')
			->with(
				$this->equalTo(new MapOverview('zaken-kaart', new MapMarkerSource('5', 'zaak', 'locatie', ['status' => 'open']), 'Zaken', ['url' => 'tiles']))
			)
			->willReturn(['id' => 'maps-overview:zaken-kaart']);

		$response = $this->controller->register();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 'maps-overview:zaken-kaart'], $response->getData());
	}

	public function testRegisterWithOnlyRequiredFieldsPassesNullsAndEmptyFilters(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->overviews->expects($this->once())->method('declareOverview')
			->with($this->equalTo(new MapOverview('', new MapMarkerSource('', '', null, []), null, null)))
			->willReturn([]);

		$this->assertSame(201, $this->controller->register()->getStatus());
	}

	public function testRegisterRefusedByTheGuardIs400AndDeclaresNothing(): void {
		$this->request->method('getParams')->willReturn(['overviewKey' => 'k', 'register' => 'r', 'schema' => 's']);
		$this->overviews->method('ensureCanRegister')
			->willThrowException(new InvalidArgumentException('A logged-in user is required to register a map overview'));
		$this->overviews->expects($this->never())->method('declareOverview');

		$response = $this->controller->register();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'A logged-in user is required to register a map overview'], $response->getData());
	}

	public function testRegisterRejectedByTheServiceIs400(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->overviews->method('declareOverview')->willThrowException(new InvalidArgumentException('overviewKey is required'));

		$response = $this->controller->register();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('overviewKey is required', $response->getData()['message']);
	}

	public function testPointsPrefersAnExplicitFiltersObjectAndDropsANonNumericLimit(): void {
		$this->request->method('getParams')->willReturn(
			['filters' => ['type' => 'boom'], 'status' => 'ignored', 'limit' => 'all']
		);
		$this->overviews->expects($this->once())->method('ensureReadablePoints')
			->with($this->equalTo(new MapMarkerSource('bomen', 'boom', null, ['type' => 'boom'])), null)
			->willReturn([['id' => 1]]);

		$response = $this->controller->points('bomen', 'boom');

		$this->assertSame(['points' => [['id' => 1]], 'count' => 1], $response->getData());
	}
}
