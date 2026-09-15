<?php

/**
 * The replay: counted first, refused as a whole above the ceiling, and nothing
 * created when it is.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rules\RuleCeilingException;
use OCA\OpenRegister\Service\Rules\RuleCeilingService;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleReplayService;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tasks 3.1 and 3.3, and the scenario "a runaway rule writes nothing".
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class RuleReplayServiceTest extends TestCase {

	/**
	 * The job service, watched so "nothing was created" is an assertion.
	 *
	 * @var BulkJobService&MockObject
	 */
	private BulkJobService $jobs;

	/**
	 * The object service, which answers the count.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->jobs = $this->createMock(originalClassName: BulkJobService::class);
		$this->objects = $this->createMock(originalClassName: ObjectService::class);
	}//end setUp()

	/**
	 * The REAL inventory, over doubles for the two stores it reads.
	 *
	 * It is final and it is a projection, so there is nothing to stub: the
	 * rule under test is declared on the schema the test builds, exactly as an
	 * administrator would declare it, and the replay finds it the way it will
	 * in production.
	 *
	 * @return RuleInventoryService The inventory.
	 */
	private function inventory(): RuleInventoryService {
		$triggers = $this->createMock(originalClassName: FlowTriggerMapper::class);
		$triggers->method('findBySchema')->willReturn([]);

		$summaries = $this->createMock(originalClassName: RuleRunSummaryMapper::class);
		$summaries->method('findBySchema')->willReturn([]);

		return new RuleInventoryService(
			triggers: $triggers,
			summaries: $summaries,
			propertyCalculations: new PropertyCalculations(),
			vocabulary: new RuleVocabulary()
		);
	}//end inventory()

	/**
	 * The schema the rule is declared on.
	 *
	 * @return Schema The schema.
	 */
	private function schema(?int $maxObjects = 100, bool $asCalculation = true): Schema {
		$declaration = ['type' => 'date', 'expression' => ['prop' => 'ontvangstdatum']];
		if ($maxObjects !== null) {
			$declaration['maxObjects'] = $maxObjects;
		}

		$configuration = ['x-openregister-calculations' => ['uiterlijkeDatum' => $declaration]];
		if ($asCalculation === false) {
			$configuration = [
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'states' => ['open' => [], 'gesloten' => []],
					'transitions' => [
						'beslissen' => [
							'from' => ['open'],
							'to' => 'gesloten',
							'condition' => ['gt' => [['prop' => 'object.bedrag'], 500]],
						],
					],
				],
			];
		}

		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('bezwaar');
		$schema->setProperties(['bedrag' => ['type' => 'number'], 'status' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * The service under test.
	 *
	 * @return RuleReplayService The service.
	 */
	private function service(): RuleReplayService {
		return new RuleReplayService(
			inventory: $this->inventory(),
			ceiling: new RuleCeilingService(),
			jobs: $this->jobs,
			objects: $this->objects
		);
	}//end service()

	/**
	 * 🔴 A RUNAWAY RULE WRITES NOTHING, AND CREATES NOTHING.
	 *
	 * `create()` is expected NEVER, not merely unasserted: a ceiling that
	 * refuses after the previewed job exists has already written the preview
	 * rows, and a reviewer reading only the refusal would never see it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARunawaySelectionIsRefusedBeforeAnythingIsCreated(): void {
		$this->objects->method('countSearchObjects')->willReturn(4000);
		$this->jobs->expects($this->never())->method('create');

		try {
			$this->service()->preview(
				schema: $this->schema(),
				ruleId: 'calculation:bezwaar:uiterlijkeDatum',
				selection: ['query' => ['status' => 'open']],
				actorUid: 'admin'
			);
			$this->fail('A selection of 4,000 under a ceiling of 100 was not refused.');
		} catch (RuleCeilingException $refusal) {
			$this->assertSame(4000, $refusal->getCount());
			$this->assertSame(100, $refusal->getCeiling());
			$this->assertStringContainsString('calculation:bezwaar:uiterlijkeDatum', $refusal->getMessage());
			$this->assertStringContainsString('4000', $refusal->getMessage());
		}
	}//end testARunawaySelectionIsRefusedBeforeAnythingIsCreated()

	/**
	 * The count is the real one, not the sample the bulk resolver would take.
	 *
	 * The selection resolver stops at the instance ceiling plus one, so a
	 * replay that counted through it could only ever say "more than 1,001".
	 * The requirement is that the refusal names 4,000.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testTheCountComesFromACountQuery(): void {
		$this->objects->expects($this->once())
			->method('countSearchObjects')
			->willReturn(4000);
		$this->jobs->expects($this->never())->method('create');

		$this->expectException(RuleCeilingException::class);
		$this->service()->preview(
			schema: $this->schema(),
			ruleId: 'calculation:bezwaar:uiterlijkeDatum',
			selection: ['query' => []],
			actorUid: 'admin'
		);
	}//end testTheCountComesFromACountQuery()

	/**
	 * A selection within the ceiling is previewed as a job.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testASelectionWithinTheCeilingIsPreviewed(): void {
		$this->objects->method('countSearchObjects')->willReturn(30);

		$job = new BulkJob();
		$job->setId(3);
		$this->jobs->expects($this->once())->method('create')->willReturn($job);

		$result = $this->service()->preview(
			schema: $this->schema(),
			ruleId: 'calculation:bezwaar:uiterlijkeDatum',
			selection: ['query' => []],
			actorUid: 'admin',
			justification: 'The termijn changed.'
		);

		$this->assertTrue($result['ok']);
		$this->assertFalse($result['committed']);
		$this->assertSame(30, $result['count']);
		$this->assertSame(100, $result['maxObjects']);
	}//end testASelectionWithinTheCeilingIsPreviewed()

	/**
	 * 🔴 THE REGRESSION OF TASK 5.3. A rule declaring no ceiling replays as it
	 * always would have: nothing here refuses it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARuleWithNoCeilingIsNotRefusedHere(): void {
		$this->objects->method('countSearchObjects')->willReturn(4000);

		$job = new BulkJob();
		$job->setId(4);
		$this->jobs->expects($this->once())->method('create')->willReturn($job);

		$result = $this->service()->preview(
			schema: $this->schema(maxObjects: null),
			ruleId: 'calculation:bezwaar:uiterlijkeDatum',
			selection: ['query' => []],
			actorUid: 'admin'
		);

		$this->assertTrue($result['ok']);
		$this->assertNull($result['maxObjects']);
	}//end testARuleWithNoCeilingIsNotRefusedHere()

	/**
	 * An ids selection is counted by its own distinct ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnIdsSelectionIsCountedByItsDistinctIds(): void {
		$this->objects->expects($this->never())->method('countSearchObjects');
		$this->jobs->expects($this->never())->method('create');

		try {
			$this->service()->preview(
				schema: $this->schema(maxObjects: 2),
				ruleId: 'calculation:bezwaar:uiterlijkeDatum',
				selection: ['ids' => ['a', 'b', 'c', 'c']],
				actorUid: 'admin'
			);
			$this->fail('Three distinct ids under a ceiling of two were not refused.');
		} catch (RuleCeilingException $refusal) {
			$this->assertSame(3, $refusal->getCount());
		}
	}//end testAnIdsSelectionIsCountedByItsDistinctIds()

	/**
	 * A rule the schema does not declare is refused, not replayed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnUnknownRuleIsRefused(): void {
		$this->jobs->expects($this->never())->method('create');

		$result = $this->service()->preview(
			schema: $this->schema(),
			ruleId: 'calculation:bezwaar:nietBestaand',
			selection: ['ids' => ['a']],
			actorUid: 'admin'
		);

		$this->assertFalse($result['ok']);
		$this->assertSame(RuleReplayService::CODE_UNKNOWN_RULE, $result['error']['code']);
	}//end testAnUnknownRuleIsRefused()

	/**
	 * A kind that derives no value for a stored object is refused.
	 *
	 * Replaying one would be inventing an event: a condition guards a move
	 * nobody is making, and a flow is a run with a log of its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAConditionCannotBeReplayed(): void {
		$this->jobs->expects($this->never())->method('create');

		$result = $this->service()->preview(
			schema: $this->schema(asCalculation: false),
			ruleId: 'lifecycleCondition:bezwaar:beslissen',
			selection: ['ids' => ['a']],
			actorUid: 'admin'
		);

		$this->assertFalse($result['ok']);
		$this->assertSame(RuleReplayService::CODE_NOT_REPLAYABLE, $result['error']['code']);
	}//end testAConditionCannotBeReplayed()

	/**
	 * A selection naming neither ids nor a query is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnEmptySelectionIsRefused(): void {
		$this->jobs->expects($this->never())->method('create');

		$result = $this->service()->preview(
			schema: $this->schema(),
			ruleId: 'calculation:bezwaar:uiterlijkeDatum',
			selection: [],
			actorUid: 'admin'
		);

		$this->assertFalse($result['ok']);
		$this->assertSame(RuleReplayService::CODE_NO_SELECTION, $result['error']['code']);
	}//end testAnEmptySelectionIsRefused()

	/**
	 * A count that cannot be taken refuses the replay rather than running it
	 * under a ceiling nothing was compared to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testACountThatFailsRefusesRatherThanRuns(): void {
		$this->objects->method('countSearchObjects')->willThrowException(new \RuntimeException('no'));
		$this->jobs->expects($this->never())->method('create');

		$result = $this->service()->preview(
			schema: $this->schema(),
			ruleId: 'calculation:bezwaar:uiterlijkeDatum',
			selection: ['query' => []],
			actorUid: 'admin'
		);

		$this->assertFalse($result['ok']);
		$this->assertSame(RuleReplayService::CODE_NO_SELECTION, $result['error']['code']);
	}//end testACountThatFailsRefusesRatherThanRuns()
}//end class
