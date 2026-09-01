<?php

/**
 * QualityStatisticsServiceTest
 *
 * Unit tests for the read-time quality statistics + lowest-quality listing
 * service. Runs the CI way (php:8.3-cli + OCP stubs, no live Nextcloud)
 * against ObjectService/SchemaMapper doubles — mirrors
 * DuplicateDetectionServiceTest's bootstrap style.
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
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-4
 */

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use OCA\OpenRegister\Service\Quality\QualityStatisticsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QualityStatisticsServiceTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	private $registerMapper;

	private QualityStatisticsService $service;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->service = new QualityStatisticsService(
			$this->objectService,
			$this->schemaMapper,
			$this->registerMapper,
			new QualityScorer(),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Stub the register-scoped resolution path the annotation lookup now takes.
	 *
	 * The annotation is no longer read via a GLOBAL SchemaMapper::find(): the
	 * register named in the route is the boundary, so the schema ref is matched
	 * only among the ids that register carries (SchemaMapper::findInIds()).
	 *
	 * @param mixed $schema The schema the scoped lookup should resolve to.
	 *
	 * @return void
	 */
	private function stubScopedSchema($schema): void {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$register->setSchemas([1]);

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('findInIds')->willReturn($schema);
	}//end stubScopedSchema()

	/**
	 * Build an ObjectEntity carrying the nil placeholder uuid + payload.
	 *
	 * @param string $uuid Object uuid (placeholder-only in fixtures).
	 * @param array<string, mixed> $payload Object data.
	 *
	 * @return ObjectEntity
	 */
	private function makeObject(string $uuid, array $payload): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($payload);

		return $object;
	}//end makeObject()

	/**
	 * Stub the schema mapper to return a schema declaring the given
	 * `x-openregister-quality` config (empty by default => scorer defaults apply).
	 *
	 * @param array<string, mixed> $quality Quality annotation body.
	 *
	 * @return void
	 */
	private function stubQualityAnnotation(array $quality): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-quality' => $quality]);
		$this->stubScopedSchema($schema);
	}//end stubQualityAnnotation()

	public function testAverageAndBucketsOverScoredSchema(): void {
		$this->stubQualityAnnotation(['thresholds' => ['good' => 0.8, 'fair' => 0.5]]);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeObject('00000000-0000-0000-0000-000000000001', ['qualityScore' => 0.9]),
				$this->makeObject('00000000-0000-0000-0000-000000000002', ['qualityScore' => 0.6]),
				$this->makeObject('00000000-0000-0000-0000-000000000003', ['qualityScore' => 0.2]),
			]
		);

		$stats = $this->service->statisticsFor('reg', 'sch');

		$this->assertSame(3, $stats['total']);
		$this->assertEqualsWithDelta((0.9 + 0.6 + 0.2) / 3, $stats['average'], 0.0001);
		$this->assertSame(1, $stats['buckets']['good']);
		$this->assertSame(1, $stats['buckets']['fair']);
		$this->assertSame(1, $stats['buckets']['poor']);

		$bucketSum = array_sum($stats['buckets']);
		$this->assertSame($stats['total'], $bucketSum);
	}//end testAverageAndBucketsOverScoredSchema()

	public function testStatusBucketsHonourSchemaThresholds(): void {
		$this->stubQualityAnnotation(['thresholds' => ['good' => 0.8, 'fair' => 0.5]]);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeObject('00000000-0000-0000-0000-000000000001', ['qualityScore' => 0.9]),
				$this->makeObject('00000000-0000-0000-0000-000000000002', ['qualityScore' => 0.6]),
				$this->makeObject('00000000-0000-0000-0000-000000000003', ['qualityScore' => 0.2]),
			]
		);

		$stats = $this->service->statisticsFor('reg', 'sch');

		$this->assertSame(1, $stats['buckets']['good']);
		$this->assertSame(1, $stats['buckets']['fair']);
		$this->assertSame(1, $stats['buckets']['poor']);
	}//end testStatusBucketsHonourSchemaThresholds()

	public function testHistogramSumsToTotal(): void {
		$this->stubQualityAnnotation([]);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeObject('00000000-0000-0000-0000-000000000001', ['qualityScore' => 0.05]),
				$this->makeObject('00000000-0000-0000-0000-000000000002', ['qualityScore' => 0.55]),
				$this->makeObject('00000000-0000-0000-0000-000000000003', ['qualityScore' => 1.0]),
				$this->makeObject('00000000-0000-0000-0000-000000000004', ['qualityScore' => 0.0]),
			]
		);

		$stats = $this->service->statisticsFor('reg', 'sch');

		$this->assertCount(10, $stats['histogram']);

		$histogramSum = 0;
		foreach ($stats['histogram'] as $bucket) {
			$histogramSum += $bucket['count'];
		}

		$this->assertSame($stats['total'], $histogramSum);

		// 0.05 -> bucket 0, 0.55 -> bucket 5, 1.0 -> bucket 9 (closed on both ends), 0.0 -> bucket 0.
		$this->assertSame(2, $stats['histogram'][0]['count']);
		$this->assertSame(1, $stats['histogram'][5]['count']);
		$this->assertSame(1, $stats['histogram'][9]['count']);
	}//end testHistogramSumsToTotal()

	public function testEmptySetReturnsZeroedStatistics(): void {
		$this->stubQualityAnnotation([]);
		$this->objectService->method('findAll')->willReturn([]);

		$stats = $this->service->statisticsFor('reg', 'sch');

		$this->assertSame(0, $stats['total']);
		$this->assertNull($stats['average']);
		$this->assertSame(['good' => 0, 'fair' => 0, 'poor' => 0], $stats['buckets']);

		$histogramSum = 0;
		foreach ($stats['histogram'] as $bucket) {
			$histogramSum += $bucket['count'];
		}

		$this->assertSame(0, $histogramSum);
	}//end testEmptySetReturnsZeroedStatistics()

	public function testThresholdDrivenBucketingUsesAnnotationField(): void {
		// Score materialised at a custom field name, annotation declares custom thresholds.
		$this->stubQualityAnnotation(
			[
				'field' => 'dqScore',
				'thresholds' => ['good' => 0.95, 'fair' => 0.7],
			]
		);

		$this->objectService->method('findAll')->willReturn(
			[
				// 0.9 would be "good" under default thresholds but is "fair" under 0.95/0.7.
				$this->makeObject('00000000-0000-0000-0000-000000000001', ['dqScore' => 0.9]),
			]
		);

		$stats = $this->service->statisticsFor('reg', 'sch');

		$this->assertSame(1, $stats['total']);
		$this->assertSame(0, $stats['buckets']['good']);
		$this->assertSame(1, $stats['buckets']['fair']);
		$this->assertSame(0, $stats['buckets']['poor']);
	}//end testThresholdDrivenBucketingUsesAnnotationField()

	public function testLowestQualityOrdersAscendingByScore(): void {
		$this->stubQualityAnnotation([]);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeObject('00000000-0000-0000-0000-000000000001', ['qualityScore' => 0.9]),
				$this->makeObject('00000000-0000-0000-0000-000000000002', ['qualityScore' => 0.2]),
				$this->makeObject('00000000-0000-0000-0000-000000000003', ['qualityScore' => 0.5]),
			]
		);

		$result = $this->service->lowestQuality('reg', 'sch');

		$scores = array_column($result['items'], 'qualityScore');
		$this->assertSame([0.2, 0.5, 0.9], $scores);
		$this->assertSame(3, $result['total']);
	}//end testLowestQualityOrdersAscendingByScore()

	public function testLowestQualityFiltersByStatus(): void {
		$this->stubQualityAnnotation(['thresholds' => ['good' => 0.8, 'fair' => 0.5]]);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeObject('00000000-0000-0000-0000-000000000001', ['qualityScore' => 0.9]),
				$this->makeObject('00000000-0000-0000-0000-000000000002', ['qualityScore' => 0.2]),
				$this->makeObject('00000000-0000-0000-0000-000000000003', ['qualityScore' => 0.55]),
			]
		);

		$result = $this->service->lowestQuality('reg', 'sch', qualityStatus: 'poor');

		$this->assertCount(1, $result['items']);
		$this->assertSame('poor', $result['items'][0]['qualityStatus']);
		$this->assertSame(0.2, $result['items'][0]['qualityScore']);
	}//end testLowestQualityFiltersByStatus()

	public function testLowestQualityPaginates(): void {
		$this->stubQualityAnnotation([]);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeObject('00000000-0000-0000-0000-000000000001', ['qualityScore' => 0.1]),
				$this->makeObject('00000000-0000-0000-0000-000000000002', ['qualityScore' => 0.2]),
				$this->makeObject('00000000-0000-0000-0000-000000000003', ['qualityScore' => 0.3]),
			]
		);

		$page1 = $this->service->lowestQuality('reg', 'sch', limit: 2, offset: 0);
		$page2 = $this->service->lowestQuality('reg', 'sch', limit: 2, offset: 2);

		$this->assertCount(2, $page1['items']);
		$this->assertCount(1, $page2['items']);
		$this->assertSame(3, $page1['total']);
		$this->assertSame(3, $page2['total']);
	}//end testLowestQualityPaginates()
}//end class
