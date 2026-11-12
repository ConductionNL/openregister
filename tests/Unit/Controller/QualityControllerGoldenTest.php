<?php

/**
 * QualityControllerGoldenTest
 *
 * Golden test pinning the parameter clamping, casting and error bodies of
 * the MDM quality endpoints, alongside QualityControllerTest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Service\Quality\QualityListingRequest;
use OCA\OpenRegister\Service\Quality\QualityListingWindow;
use OCA\OpenRegister\Controller\QualityController;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Quality\QualityStatisticsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\QualityController
 */
class QualityControllerGoldenTest extends TestCase {

	private QualityStatisticsService&MockObject $statistics;

	private IRequest&MockObject $request;

	private QualityController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->statistics = $this->createMock(QualityStatisticsService::class);
		$this->controller = new QualityController($this->request, $this->statistics);
	}

	public function testIndexClampsNonPositiveLimitAndNegativeOffsetAndCastsStatus(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['qualityStatus', null, 7],
				['sort', 'qualityScore', 3],
				['order', 'asc', 'desc'],
				['limit', 20, '-4'],
				['offset', 0, '-1'],
			]
		);
		$listing = ['items' => [['id' => 'a']], 'total' => 1, 'limit' => 20, 'offset' => 0];
		$this->statistics->expects($this->once())
			->method('lowestQuality')
			->with(new QualityListingRequest('reg', 'sch', '7', new QualityListingWindow('3', 'desc', 20, 0)))
			->willReturn($listing);

		$response = $this->controller->index('reg', 'sch');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($listing, $response->getData());
	}

	public function testIndexZeroLimitFallsBackToTwentyAndKeepsZeroOffset(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['qualityStatus', null, null],
				['sort', 'qualityScore', 'qualityScore'],
				['order', 'asc', 'asc'],
				['limit', 20, 0],
				['offset', 0, 0],
			]
		);
		$this->statistics->expects($this->once())
			->method('lowestQuality')
			->with(new QualityListingRequest('reg', 'sch', null, new QualityListingWindow('qualityScore', 'asc', 20, 0)))
			->willReturn(['items' => []]);

		$this->assertSame(200, $this->controller->index('reg', 'sch')->getStatus());
	}

	public function testIndexRuntimeExceptionIsA404WithItsMessage(): void {
		$this->request->method('getParam')->willReturnArgument(1);
		$this->statistics->method('lowestQuality')->willThrowException(new RuntimeException('no schema'));

		$response = $this->controller->index('reg', 'sch');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'no schema'], $response->getData());
	}

	public function testIndexNotAuthorizedIsA403WithItsMessage(): void {
		$this->request->method('getParam')->willReturnArgument(1);
		$this->statistics->method('lowestQuality')->willThrowException(new NotAuthorizedException(message: 'denied'));

		$response = $this->controller->index('reg', 'sch');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'denied'], $response->getData());
	}

	public function testStatsReturnsTheServiceResultAsIs(): void {
		$stats = ['average' => 0.5, 'total' => 2, 'buckets' => [], 'histogram' => []];
		$this->statistics->method('statisticsFor')->willReturn($stats);

		$response = $this->controller->stats('reg', 'sch');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($stats, $response->getData());
	}

	public function testStatsRuntimeExceptionIsA404WithItsMessage(): void {
		$this->statistics->method('statisticsFor')->willThrowException(new RuntimeException('missing'));

		$response = $this->controller->stats('reg', 'sch');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'missing'], $response->getData());
	}

	public function testStatsNotAuthorizedIsA403WithItsMessage(): void {
		$this->statistics->method('statisticsFor')->willThrowException(new NotAuthorizedException(message: 'nope'));

		$response = $this->controller->stats('reg', 'sch');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'nope'], $response->getData());
	}
}
