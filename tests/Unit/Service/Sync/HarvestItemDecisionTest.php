<?php

/**
 * Item decisions, strategies and protected fields of the harvest import stage.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

use DateTime;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncExecution;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Sync\HarvestImportServices;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\HarvestRun;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The D-7 decision table row by row, the D-8 strategies with the real
 * decision-table evaluator, and protected fields and provenance.
 *
 * @covers \OCA\OpenRegister\Service\Sync\HarvestPipelineService
 * @covers \OCA\OpenRegister\Service\Sync\SyncConflictResolver
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Source
 * @uses \OCA\OpenRegister\Db\SyncExecution
 * @uses \OCA\OpenRegister\Db\SyncRecord
 * @uses \OCA\OpenRegister\Db\SyncRecordInputs
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\ResolvedInputs
 * @uses \OCA\OpenRegister\Service\Dmn\RuleMatch
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionEvaluationException
 * @uses \OCA\OpenRegister\Service\Sync\HarvestImportServices
 * @uses \OCA\OpenRegister\Service\Sync\HarvestRun
 * @uses \OCA\OpenRegister\Service\Sync\HarvestedRecord
 * @uses \OCA\OpenRegister\Service\Sync\SourceMapping
 * @uses \OCA\OpenRegister\Service\Sync\SyncConflict
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
class HarvestItemDecisionTest extends TestCase {

	private SyncRecordMapper&MockObject $records;

	private ObjectService&MockObject $objects;

	private HarvestPipelineService $pipeline;

	/** @var SyncRecord|null The record findByExternalId answers. */
	private ?SyncRecord $previous = null;

	/** @var list<array{0: array<string, mixed>, 1: string|null}> Every save: payload and uuid. */
	private array $saves = [];

	protected function setUp(): void {
		$this->records = $this->createMock(SyncRecordMapper::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->records->method('findByExternalId')->willReturnCallback(fn (): ?SyncRecord => $this->previous);
		$this->records->method('transitionStatus')->willReturnCallback(
			static function (SyncRecord $record, string $status): SyncRecord {
				$record->setStatus($status);
				return $record;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options) {
				$this->saves[] = [$object, $options->uuid];
				$saved = new ObjectEntity();
				$saved->setUuid($options->uuid ?? 'new-uuid');
				return $saved;
			}
		);

		$this->pipeline = new HarvestPipelineService(
			$this->records,
			new HarvestImportServices(
				mappingMapper: $this->createMock(MappingMapper::class),
				mappingService: $this->createMock(MappingService::class),
				objectService: $this->objects,
				conflictResolver: new SyncConflictResolver()
			),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function source(string $strategy = SyncConflictResolver::MANUAL): Source {
		$source = new Source();
		$source->setId(7);
		$source->setUuid('src-uuid');
		$source->setTargetRegister('3');
		$source->setTargetSchema('4');
		$source->setConflictStrategy($strategy);
		return $source;
	}

	private function fetched(array $raw, string $externalId = 'ds-1'): SyncRecord {
		$record = new SyncRecord();
		$record->setId(500);
		$record->setSourceId(7);
		$record->setExternalId($externalId);
		$record->setStatus(SyncRecordStatus::FETCHED);
		$record->setRawData($raw);
		return $record;
	}

	private function tracked(string $hash, ?DateTime $appliedAt, string $status = SyncRecordStatus::IMPORTED): SyncRecord {
		$record = new SyncRecord();
		$record->setId(400);
		$record->setExternalId('ds-1');
		$record->setStatus($status);
		$record->setContentHash($hash);
		$record->setObjectUuid('obj-1');
		$record->setLastAppliedAt($appliedAt);
		return $record;
	}

	private function local(?DateTime $updated): void {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setObject(['title' => 'Local']);
		$entity->setUpdated($updated);
		$this->objects->method('find')->willReturn($entity);
	}

	private function import(Source $source, SyncRecord $record): array {
		return $this->pipeline->import(new HarvestRun($source, $this->createMock(SourceFetcherInterface::class), 'exec-1'), [$record]);
	}

	public function testANewItemWithoutALocalClaimIsCreated(): void {
		$record = $this->fetched(['title' => 'A']);

		$summary = $this->import($this->source(), $record);

		$this->assertSame(1, $summary['created']);
		$this->assertSame(SyncRecordStatus::IMPORTED, $record->getStatus());
		$this->assertSame([[['title' => 'A'], null]], $this->saves);
		$this->assertNotNull($record->getLastAppliedAt());
	}

	public function testAnEqualHashIsUnchanged(): void {
		$this->previous = $this->tracked($this->pipeline->contentHash(['title' => 'A']), new DateTime('2026-10-01'));
		$record = $this->fetched(['title' => 'A']);

		$summary = $this->import($this->source(), $record);

		$this->assertSame(1, $summary['unchanged']);
		$this->assertSame([], $this->saves);
	}

	public function testTheHarvestsOwnUpdateIsNotACollision(): void {
		$this->previous = $this->tracked('old', new DateTime('2026-10-02 12:00'));
		$this->local(new DateTime('2026-10-02 12:00'));
		$record = $this->fetched(['title' => 'B']);

		$summary = $this->import($this->source(), $record);

		$this->assertSame(1, $summary['updated']);
		$this->assertSame([[['title' => 'B'], 'obj-1']], $this->saves);
		$this->assertNull($record->getConflictReason());
	}

	public function testALocalEditAfterTheLastHarvestIsALocalEditedCollision(): void {
		$this->previous = $this->tracked('old', new DateTime('2026-10-01'));
		$this->local(new DateTime('2026-10-03'));
		$record = $this->fetched(['title' => 'B']);

		$summary = $this->import($this->source(), $record);

		$this->assertSame(1, $summary['conflicts']);
		$this->assertSame(SyncRecordStatus::CONFLICT, $record->getStatus());
		$this->assertSame(SyncConflictResolver::REASON_LOCAL_EDITED, $record->getConflictReason());
		$this->assertSame(['title' => 'B'], $record->getRawData());
		$this->assertSame('obj-1', $record->getObjectUuid());
		$this->assertSame([], $this->saves);
	}

	public function testALocallyDeletedObjectIsNeverRecreatedEvenUnderSourceWins(): void {
		$this->previous = $this->tracked('old', new DateTime('2026-10-01'));
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$record = $this->fetched(['title' => 'B']);

		$summary = $this->import($this->source(SyncConflictResolver::SOURCE_WINS), $record);

		$this->assertSame(1, $summary['conflicts']);
		$this->assertSame(SyncConflictResolver::REASON_LOCAL_DELETED, $record->getConflictReason());
		$this->assertSame([], $this->saves);
	}

	public function testAPreExistingClaimIsACollision(): void {
		$claim = new ObjectEntity();
		$claim->setUuid('claimed');
		$this->objects->expects($this->once())->method('searchObjects')
			->with(['@self' => ['register' => 3, 'schema' => 4], 'identifier' => 'ds-1', '_limit' => 1])
			->willReturn([$claim]);
		$source = $this->source(SyncConflictResolver::LOCAL_WINS);
		$source->setIdentityProperty('identifier');
		$record = $this->fetched(['title' => 'A']);

		$summary = $this->import($source, $record);

		$this->assertSame(1, $summary['shadowed']);
		$this->assertSame(SyncRecordStatus::SHADOWED, $record->getStatus());
		$this->assertSame(SyncConflictResolver::REASON_PRE_EXISTING_CLAIM, $record->getConflictReason());
		$this->assertSame('claimed', $record->getObjectUuid());
		$this->assertSame(['title' => 'A'], $record->getRawData());
	}

	public function testSourceWinsUpdatesTheLocalObject(): void {
		$this->previous = $this->tracked('old', new DateTime('2026-10-01'));
		$this->local(new DateTime('2026-10-03'));
		$record = $this->fetched(['title' => 'B']);

		$summary = $this->import($this->source(SyncConflictResolver::SOURCE_WINS), $record);

		$this->assertSame(1, $summary['updated']);
		$this->assertSame(SyncRecordStatus::IMPORTED, $record->getStatus());
		$this->assertSame([[['title' => 'B'], 'obj-1']], $this->saves);
	}

	public function testRejectClearsThePayloadAndNeverLinks(): void {
		$this->previous = $this->tracked('old', new DateTime('2026-10-01'));
		$this->local(new DateTime('2026-10-03'));
		$record = $this->fetched(['title' => 'B']);

		$summary = $this->import($this->source(SyncConflictResolver::REJECT), $record);

		$this->assertSame(1, $summary['rejected']);
		$this->assertSame(SyncRecordStatus::REJECTED, $record->getStatus());
		$this->assertNull($record->getRawData());
		$this->assertNull($record->getObjectUuid());
		$this->assertSame([], $this->saves);
	}

	public function testADecisionTablePicksRejectForOneReasonOnly(): void {
		$source = $this->source(SyncConflictResolver::MANUAL);
		$source->setConflictRules([
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'conflictReason', 'type' => 'string']],
			'outputs' => [['name' => 'strategy', 'type' => 'string']],
			'rules' => [
				['id' => 'r1', 'inputEntries' => ['"pre-existing-claim"'], 'outputEntries' => ['"reject"']],
			],
		]);
		$resolver = new SyncConflictResolver();

		$this->assertSame(
			SyncConflictResolver::REJECT,
			$resolver->strategyFor($source, SyncConflictResolver::REASON_PRE_EXISTING_CLAIM, 'ds-1', ['title' => 'A'])
		);
		$this->assertSame(
			SyncConflictResolver::MANUAL,
			$resolver->strategyFor($source, SyncConflictResolver::REASON_LOCAL_EDITED, 'ds-1', ['title' => 'A'])
		);
	}

	public function testATableOutputThatIsNotAStrategyFallsBack(): void {
		$source = $this->source(SyncConflictResolver::LOCAL_WINS);
		$source->setConflictRules([
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'title', 'type' => 'string']],
			'outputs' => [['name' => 'strategy', 'type' => 'string']],
			'rules' => [['id' => 'r1', 'inputEntries' => ['-'], 'outputEntries' => ['"overwrite-everything"']]],
		]);

		$this->assertSame(
			SyncConflictResolver::LOCAL_WINS,
			(new SyncConflictResolver())->strategyFor($source, SyncConflictResolver::REASON_LOCAL_EDITED, 'ds-1', ['title' => 'A'])
		);
	}

	public function testLocalDeletedIsManualWhateverTheTableSays(): void {
		$source = $this->source(SyncConflictResolver::SOURCE_WINS);
		$source->setConflictRules([
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'conflictReason', 'type' => 'string']],
			'outputs' => [['name' => 'strategy', 'type' => 'string']],
			'rules' => [['id' => 'r1', 'inputEntries' => ['-'], 'outputEntries' => ['"source-wins"']]],
		]);

		$this->assertSame(
			SyncConflictResolver::MANUAL,
			(new SyncConflictResolver())->strategyFor($source, SyncConflictResolver::REASON_LOCAL_DELETED, 'ds-1', [])
		);
	}

	public function testProtectedFieldsAreDroppedAndProvenanceIsSet(): void {
		$source = $this->source();
		$source->setProtectedFields(['status', 'publicationDate']);
		$source->setProvenance(['externalIdProperty' => 'dct:source', 'sourceProperty' => 'prov:wasDerivedFrom']);
		$record = $this->fetched(['title' => 'A', 'status' => 'published', 'dct:source' => 'spoofed']);

		$this->import($source, $record);

		$this->assertSame(
			[[['title' => 'A', 'dct:source' => 'ds-1', 'prov:wasDerivedFrom' => 'src-uuid'], null]],
			$this->saves
		);
	}
}
