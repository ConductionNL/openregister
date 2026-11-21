<?php

declare(strict_types=1);

/**
 * Golden test for one AVG retention pass: dry runs and skipped activities.
 *
 * Pins the per-activity outcomes of AvgRetentionService::runRetentionPass()
 * that the legal-hold tests do not reach: a dry run reports the overdue
 * record without erasing it, and an activity without a usable bewaartermijn
 * is skipped rather than evaluated.
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
use OCA\OpenRegister\Service\Archival\ArchivalSchemaLookup;
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
 * Golden outcomes of a retention pass.
 */
class AvgRetentionPassGoldenTest extends TestCase {

	/**
	 * Object mapper the pass writes its soft deletes through.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * Build a service over one activity and one overdue candidate.
	 *
	 * @param string               $retentionPeriod The activity's bewaartermijn.
	 * @param array<string, mixed> $retention       Retention block carried by the candidate.
	 *
	 * @return AvgRetentionService
	 */
	private function service(string $retentionPeriod, array $retention): AvgRetentionService {
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$functionBuilder = $this->createMock(IFunctionBuilder::class);
		$expressionBuilder = $this->createMock(IExpressionBuilder::class);
		$result = $this->createMock(IResult::class);

		$expressionBuilder->method('eq')->willReturn('object = :p');
		$functionBuilder->method('max')->willReturn($this->createMock(IQueryFunction::class));
		$queryBuilder->method('func')->willReturn($functionBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		foreach (['select', 'selectAlias', 'from', 'where', 'groupBy', 'having'] as $fluent) {
			$queryBuilder->method($fluent)->willReturnSelf();
		}

		$queryBuilder->method('createNamedParameter')->willReturn(':p');
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAll')->willReturn(
			[['object' => 7, 'object_uuid' => 'candidate-1', 'register' => 1, 'schema' => 42]]
		);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);

		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-1');
		$activity->setName('Klachtafhandeling');
		$activity->setRetentionPeriod($retentionPeriod);
		$activityMapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$activityMapper->method('findAll')->willReturn([$activity]);

		$candidate = new ObjectEntity();
		$candidate->setUuid('candidate-1');
		$candidate->setRegister('1');
		$candidate->setSchema('klacht');
		$candidate->setRetention($retention);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectMapper->method('find')->willReturn($candidate);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (): Schema {
				$schema = new Schema();
				$schema->setSlug('klacht');
				$schema->setConfiguration([]);

				return $schema;
			}
		);
		$guard = new ArchivalRetentionGuard(new ArchivalSchemaLookup($schemaMapper, $this->createMock(LoggerInterface::class)));

		return new AvgRetentionService(
			$db,
			$activityMapper,
			$this->objectMapper,
			$this->createMock(LoggerInterface::class),
			$guard
		);
	}//end service()

	/**
	 * A dry run reports the overdue record and erases nothing.
	 *
	 * @return void
	 */
	public function testADryRunMatchesWithoutErasing(): void {
		$service = $this->service('P1Y', []);
		$this->objectMapper->expects($this->never())->method('update');

		$summary = $service->runRetentionPass(true);

		$this->assertTrue($summary['dryRun']);
		$this->assertSame(1, $summary['evaluatedActivities']);
		$this->assertSame(0, $summary['objectsErased']);
		$this->assertSame(1, $summary['perActivity'][0]['matchedObjects']);
		$this->assertSame(0, $summary['perActivity'][0]['erased']);
		$this->assertSame([], $summary['perActivity'][0]['withheld']);
	}//end testADryRunMatchesWithoutErasing()

	/**
	 * A dry run does not consult the hold either: nothing is withheld because nothing is erased.
	 *
	 * @return void
	 */
	public function testADryRunWithholdsNothing(): void {
		$service = $this->service('P1Y', ['legalHold' => ['active' => true, 'reason' => 'Bezwaar']]);

		$summary = $service->runRetentionPass(true);

		$this->assertSame(0, $summary['objectsWithheld']);
		$this->assertSame([], $summary['withheld']);
	}//end testADryRunWithholdsNothing()

	/**
	 * A real run reports the activity it erased under.
	 *
	 * @return void
	 */
	public function testARealRunReportsTheActivity(): void {
		$service = $this->service('P1Y', []);
		$this->objectMapper->expects($this->once())->method('update');

		$summary = $service->runRetentionPass(false);

		$this->assertFalse($summary['dryRun']);
		$this->assertSame('activity-1', $summary['perActivity'][0]['uuid']);
		$this->assertSame('Klachtafhandeling', $summary['perActivity'][0]['name']);
		$this->assertSame('P1Y', $summary['perActivity'][0]['retentionPeriod']);
		$this->assertSame(1, $summary['perActivity'][0]['erased']);
	}//end testARealRunReportsTheActivity()

	/**
	 * An activity without a bewaartermijn, or with an unparseable one, is skipped and erases nothing.
	 *
	 * @return void
	 */
	public function testAnActivityWithoutAUsableRetentionPeriodIsSkipped(): void {
		foreach (['', 'ten years'] as $period) {
			$service = $this->service($period, []);
			$this->objectMapper->expects($this->never())->method('update');

			$summary = $service->runRetentionPass(false);

			$this->assertSame(1, $summary['skippedActivities'], $period);
			$this->assertSame(0, $summary['evaluatedActivities'], $period);
			$this->assertSame([], $summary['perActivity'], $period);
		}
	}//end testAnActivityWithoutAUsableRetentionPeriodIsSkipped()
}//end class
