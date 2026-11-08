<?php

/**
 * QualityStatisticsListingGoldenTest
 *
 * Characterisation test pinning the lowest-quality listing: status filter,
 * sort key and direction, null placement, score coercion and the window,
 * written against the code before it was split into smaller steps.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Quality\QualityListingWindow;
use OCA\OpenRegister\Service\Quality\QualityScopeMappers;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use OCA\OpenRegister\Service\Quality\QualityStatisticsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QualityStatisticsListingGoldenTest extends TestCase {

	/**
	 * Build the service over a fixed object set (good >= 0.8, fair >= 0.5).
	 *
	 * @return QualityStatisticsService
	 */
	private function service(): QualityStatisticsService {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$register->setSchemas([1]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-quality' => ['thresholds' => ['good' => 0.8, 'fair' => 0.5]]]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn($schema);

		$payloads = [
			'u1' => ['qualityScore' => 0.9],
			'u2' => ['qualityScore' => 'n/a'],
			'u3' => ['qualityScore' => '0.3'],
			'u4' => ['qualityScore' => 1],
			'u5' => [],
			'u6' => ['qualityScore' => 0.6],
			'u7' => ['qualityScore' => 0.3],
		];
		$objects = [];
		foreach ($payloads as $uuid => $payload) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setObject($payload);
			$objects[] = $object;
		}

		$objects[] = 'not an entity';

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn($objects);

		return new QualityStatisticsService(
			$objectService,
			new QualityScopeMappers($schemaMapper, $registerMapper),
			new QualityScorer(),
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Listings and the exact ids, scores and statuses they produce.
	 *
	 * @return array<string, array{0: string|null, 1: QualityListingWindow, 2: array<int, array{0: string, 1: float|null, 2: string|null}>, 3: int}>
	 */
	public static function listings(): array {
		$all = [
			['u3', 0.3, 'poor'],
			['u7', 0.3, 'poor'],
			['u6', 0.6, 'fair'],
			['u1', 0.9, 'good'],
			['u4', 1.0, 'good'],
			['u2', null, null],
			['u5', null, null],
		];

		return [
			'default ascending, nulls last, ties keep order' => [null, new QualityListingWindow(), $all, 7],
			'empty status is no filter' => ['', new QualityListingWindow(), $all, 7],
			'descending, nulls still last' => [
				null,
				new QualityListingWindow(order: 'DESC'),
				[['u4', 1.0, 'good'], ['u1', 0.9, 'good'], ['u6', 0.6, 'fair'], ['u3', 0.3, 'poor'], ['u7', 0.3, 'poor'], ['u2', null, null], ['u5', null, null]],
				7,
			],
			'by status ascending' => [
				null,
				new QualityListingWindow(sort: 'qualityStatus'),
				[['u6', 0.6, 'fair'], ['u1', 0.9, 'good'], ['u4', 1.0, 'good'], ['u3', 0.3, 'poor'], ['u7', 0.3, 'poor'], ['u2', null, null], ['u5', null, null]],
				7,
			],
			'by status descending' => [
				null,
				new QualityListingWindow(sort: 'qualityStatus', order: 'desc'),
				[['u3', 0.3, 'poor'], ['u7', 0.3, 'poor'], ['u1', 0.9, 'good'], ['u4', 1.0, 'good'], ['u6', 0.6, 'fair'], ['u2', null, null], ['u5', null, null]],
				7,
			],
			'unknown sort falls back to score' => [null, new QualityListingWindow(sort: 'name'), $all, 7],
			'status filter good' => [
				'good',
				new QualityListingWindow(),
				[['u1', 0.9, 'good'], ['u4', 1.0, 'good']],
				2,
			],
			'status filter drops unscored' => ['unscored', new QualityListingWindow(), [], 0],
			'window slices after total' => [
				null,
				new QualityListingWindow(limit: 2, offset: 1),
				[['u7', 0.3, 'poor'], ['u6', 0.6, 'fair']],
				7,
			],
			'negative window clamps' => [null, new QualityListingWindow(limit: -1, offset: -4), [], 7],
		];
	}//end listings()

	/**
	 * The listing yields exactly the pinned rows and window.
	 *
	 * @param string|null $status Status filter.
	 * @param QualityListingWindow $window Window.
	 * @param array<int, array{0: string, 1: float|null, 2: string|null}> $expected Rows.
	 * @param int $total Expected total.
	 *
	 * @return void
	 */
	#[DataProvider('listings')]
	public function testListingIsPinned(?string $status, QualityListingWindow $window, array $expected, int $total): void {
		$result = $this->service()->lowestQuality('reg', 'sch', $status, $window);

		$rows = [];
		foreach ($expected as [$id, $score, $rowStatus]) {
			$rows[] = ['id' => $id, 'qualityScore' => $score, 'qualityStatus' => $rowStatus];
		}

		$this->assertSame($rows, $result['items']);
		$this->assertSame($total, $result['total']);
		$this->assertSame($window->limit, $result['limit']);
		$this->assertSame($window->offset, $result['offset']);
	}//end testListingIsPinned()
}//end class
