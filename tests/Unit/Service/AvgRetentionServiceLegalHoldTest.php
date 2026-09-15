<?php

declare(strict_types=1);

/**
 * AvgRetentionService legal-hold unit tests.
 *
 * The AVG pass SOFT-deletes rather than destroys, so a record it takes is
 * recoverable. It is still wrong: a record under objection or under a court
 * order must stay live and visible until the hold is released, and a soft
 * delete removes it from every list the handler reads.
 *
 * These tests drive the real pass with a real ArchivalRetentionGuard, so a
 * guard that grew its own opinion of "held" would fail here.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\AvgRetentionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for the AVG retention pass and legal holds.
 */
class AvgRetentionServiceLegalHoldTest extends TestCase {

	/**
	 * Object mapper the pass writes its soft deletes through.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * Build a service whose audit-trail query answers with one overdue row.
	 *
	 * @param array<string, mixed> $retention Retention block carried by the candidate.
	 *
	 * @return AvgRetentionService
	 */
	private function service(array $retention): AvgRetentionService {
		$db = $this->createMock(IDBConnection::class);
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$functionBuilder = $this->createMock(IFunctionBuilder::class);
		$result = $this->createMock(IResult::class);

		$expressionBuilder = $this->createMock(IExpressionBuilder::class);
		$expressionBuilder->method('eq')->willReturn('object = :p');

		$functionBuilder->method('max')->willReturn($this->createMock(IQueryFunction::class));
		$queryBuilder->method('func')->willReturn($functionBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('selectAlias')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('where')->willReturnSelf();
		$queryBuilder->method('groupBy')->willReturnSelf();
		$queryBuilder->method('having')->willReturnSelf();
		$queryBuilder->method('createNamedParameter')->willReturn(':p');
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAll')->willReturn(
			[
				[
					'object' => 7,
					'object_uuid' => 'candidate-1',
					'register' => 1,
					'schema' => 42,
				],
			]
		);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);

		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-1');
		$activity->setName('Klachtafhandeling');
		$activity->setRetentionPeriod('P1Y');

		$activityMapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$activityMapper->method('findAll')->willReturn([$activity]);

		$candidate = new ObjectEntity();
		$candidate->setUuid('candidate-1');
		$candidate->setRegister('1');
		$candidate->setSchema('klacht');
		$candidate->setRetention($retention);

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectMapper->method('find')->willReturn($candidate);

		// A REAL guard over a schema that declares no archival annotation: the
		// only thing that can refuse this candidate is the legal hold.
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (): Schema {
				$schema = new Schema();
				$schema->setSlug('klacht');
				$schema->setConfiguration([]);

				return $schema;
			}
		);
		$guard = new ArchivalRetentionGuard($schemaMapper, $this->createMock(LoggerInterface::class));

		return new AvgRetentionService(
			$db,
			$activityMapper,
			$this->objectMapper,
			$this->createMock(LoggerInterface::class),
			$guard
		);
	}//end service()

	/**
	 * THE DEFECT THIS TEST EXISTS FOR: an overdue record under an active legal
	 * hold must not be soft-deleted by the AVG pass.
	 *
	 * @return void
	 */
	public function testAnOverdueRecordUnderLegalHoldIsNotSoftDeleted(): void {
		$service = $this->service(
			['legalHold' => ['active' => true, 'reason' => 'Bezwaarprocedure loopt']]
		);

		$this->objectMapper->expects($this->never())->method('update');

		$summary = $service->runRetentionPass();

		$this->assertSame(0, $summary['objectsErased']);
	}//end testAnOverdueRecordUnderLegalHoldIsNotSoftDeleted()

	/**
	 * SKIPPING MUST BE VISIBLE: the held record is named in `withheld` with its
	 * own ground, and counted under `objectsWithheld` rather than dropped.
	 *
	 * @return void
	 */
	public function testAHeldRecordIsReportedUnderItsOwnGround(): void {
		$service = $this->service(
			['legalHold' => ['active' => true, 'reason' => 'Bezwaarprocedure loopt']]
		);

		$summary = $service->runRetentionPass();

		$this->assertSame(1, $summary['objectsWithheld']);
		$this->assertCount(1, $summary['withheld']);
		$this->assertSame('candidate-1', $summary['withheld'][0]['uuid']);
		$this->assertSame(
			ArchivalRetentionGuard::GROUND_LEGAL_HOLD,
			$summary['withheld'][0]['ground']
		);
	}//end testAHeldRecordIsReportedUnderItsOwnGround()

	/**
	 * The pass still erases an overdue record that is NOT held, so the hold
	 * check cannot pass by refusing everything.
	 *
	 * @return void
	 */
	public function testAnOverdueRecordWithoutAHoldIsStillErased(): void {
		$service = $this->service([]);

		$this->objectMapper->expects($this->once())->method('update');

		$summary = $service->runRetentionPass();

		$this->assertSame(1, $summary['objectsErased']);
		$this->assertSame(0, $summary['objectsWithheld']);
	}//end testAnOverdueRecordWithoutAHoldIsStillErased()

	/**
	 * A RELEASED HOLD IS NOT A HOLD. `active: false` is the shape
	 * `RetentionService::releaseLegalHold()` writes, and it must not keep a
	 * record alive forever.
	 *
	 * @return void
	 */
	public function testAReleasedHoldDoesNotWithholdTheRecord(): void {
		$service = $this->service(
			['legalHold' => ['active' => false, 'history' => [['reason' => 'Afgerond']]]]
		);

		$this->objectMapper->expects($this->once())->method('update');

		$summary = $service->runRetentionPass();

		$this->assertSame(1, $summary['objectsErased']);
		$this->assertSame(0, $summary['objectsWithheld']);
	}//end testAReleasedHoldDoesNotWithholdTheRecord()
}//end class
