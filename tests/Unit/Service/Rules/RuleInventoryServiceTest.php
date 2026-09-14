<?php

/**
 * The inventory as a projection of the schema, never a second registry.
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

use DateTime;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\RuleRunSummary;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the derivation, the order, the switch and the idle window.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class RuleInventoryServiceTest extends TestCase {

	/**
	 * Build the service over doubles for the two stores it reads.
	 *
	 * @param array<int, array{flow_uuid: string, event: string, register: string, enabled: bool}> $triggerRows The flow trigger rows.
	 * @param array<string, RuleRunSummary> $summaries The summaries, keyed by rule id.
	 *
	 * @return RuleInventoryService The service.
	 */
	private function service(array $triggerRows = [], array $summaries = []): RuleInventoryService {
		$triggers = $this->createMock(FlowTriggerMapper::class);
		$triggers->method('findBySchema')->willReturn($triggerRows);

		$summaryMapper = $this->createMock(RuleRunSummaryMapper::class);
		$summaryMapper->method('findBySchema')->willReturn($summaries);

		return new RuleInventoryService(
			$triggers,
			$summaryMapper,
			new PropertyCalculations(),
			new RuleVocabulary()
		);

	}//end service()

	/**
	 * A schema carrying one calculation, one state block and one condition.
	 *
	 * @param bool $withCondition Whether the transition declares a condition.
	 *
	 * @return Schema The schema.
	 */
	private function schema(bool $withCondition = true): Schema {
		$transition = ['from' => ['open'], 'to' => 'gesloten'];
		if ($withCondition === true) {
			$transition['condition'] = ['>' => [['var' => 'object.bedrag'], 500]];
		}

		$schema = new Schema();
		$schema->setSlug('bezwaar');
		$schema->setProperties(['bedrag' => ['type' => 'number'], 'status' => ['type' => 'string']]);
		$schema->setConfiguration(
			[
				'x-openregister-calculations' => [
					'uiterlijkeDatum' => ['type' => 'date', 'expression' => ['prop' => 'ontvangstdatum']],
				],
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'states' => ['gesloten' => ['fields' => ['outcome' => ['required' => true]]]],
					'transitions' => ['sluiten' => $transition],
				],
			]
		);

		return $schema;

	}//end schema()

	/**
	 * Four declarations produce four entries, in the order the pipeline runs
	 * them: calculation, state field block, lifecycle condition, flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnAdministratorReadsWhatWillRunInEvaluationOrder(): void {
		$service = $this->service(
			triggerRows: [
				['flow_uuid' => 'flow-1', 'event' => 'created', 'register' => 'zaken', 'enabled' => true],
				['flow_uuid' => 'flow-1', 'event' => 'updated', 'register' => 'zaken', 'enabled' => true],
			]
		);

		$rules = $service->describe(schema: $this->schema());

		$this->assertCount(4, $rules);
		$this->assertSame(
			[
				RuleVocabulary::KIND_CALCULATION,
				RuleVocabulary::KIND_STATE_FIELD_RULE,
				RuleVocabulary::KIND_LIFECYCLE_CONDITION,
				RuleVocabulary::KIND_FLOW,
			],
			array_map(static fn ($rule): string => $rule->getKind(), $rules)
		);
		$this->assertSame('calculation:bezwaar:uiterlijkeDatum', $rules[0]->getId());
		$this->assertSame('x-openregister-lifecycle.transitions.sluiten.condition', $rules[2]->jsonSerialize()['source']);

	}//end testAnAdministratorReadsWhatWillRunInEvaluationOrder()

	/**
	 * A removed annotation leaves the inventory, because the inventory IS the
	 * annotation read back. This is the projection property, and it is the one
	 * claim a second registry could not make.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARemovedAnnotationLeavesTheInventory(): void {
		$service = $this->service();

		$this->assertCount(3, $service->describe(schema: $this->schema(withCondition: true)));

		$rules = $service->describe(schema: $this->schema(withCondition: false));

		$this->assertCount(2, $rules);
		$this->assertSame(
			[],
			array_values(
				array_filter(
					$rules,
					static fn ($rule): bool => $rule->getKind() === RuleVocabulary::KIND_LIFECYCLE_CONDITION
				)
			)
		);

	}//end testARemovedAnnotationLeavesTheInventory()

	/**
	 * A rule switched off in its own declaration reads as disabled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testASwitchedOffRuleReadsAsDisabled(): void {
		$schema = $this->schema();
		$configuration = $schema->getConfiguration();
		$configuration['x-openregister-calculations']['uiterlijkeDatum']['enabled'] = false;
		$schema->setConfiguration($configuration);

		$rule = $this->service()->find(schema: $schema, ruleId: 'calculation:bezwaar:uiterlijkeDatum');

		$this->assertNotNull($rule);
		$this->assertFalse($rule->isEnabled());

	}//end testASwitchedOffRuleReadsAsDisabled()

	/**
	 * A rule whose last run is older than the window is flagged, and one that
	 * has never run at all is flagged too: never is also outside the window.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARuleThatHasNotRunInsideTheWindowIsVisibleAsSuch(): void {
		$stale = new RuleRunSummary();
		$stale->setRuleId('calculation:bezwaar:uiterlijkeDatum');
		$stale->setSchemaSlug('bezwaar');
		$stale->setLastRun(new DateTime('2026-01-01 00:00:00'));
		$stale->setLastVerdict(RuleVocabulary::VERDICT_FIRED);

		$rows = $this->service(summaries: ['calculation:bezwaar:uiterlijkeDatum' => $stale])->inventory(
			schema: $this->schema(),
			idleDays: 90,
			now: new DateTime('2026-09-14 00:00:00')
		);

		$byId = [];
		foreach ($rows as $row) {
			$byId[$row['id']] = $row;
		}

		$this->assertFalse($byId['calculation:bezwaar:uiterlijkeDatum']['ranInsideWindow']);
		$this->assertSame('2026-01-01T00:00:00+00:00', $byId['calculation:bezwaar:uiterlijkeDatum']['lastRun']);
		$this->assertFalse($byId['lifecycleCondition:bezwaar:sluiten']['ranInsideWindow']);
		$this->assertNull($byId['lifecycleCondition:bezwaar:sluiten']['lastRun']);

	}//end testARuleThatHasNotRunInsideTheWindowIsVisibleAsSuch()

	/**
	 * A recent run is inside the window, which is what makes the flag above a
	 * measurement rather than a constant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARecentRunIsInsideTheWindow(): void {
		$fresh = new RuleRunSummary();
		$fresh->setRuleId('calculation:bezwaar:uiterlijkeDatum');
		$fresh->setSchemaSlug('bezwaar');
		$fresh->setLastRun(new DateTime('2026-09-10 00:00:00'));
		$fresh->setLastVerdict(RuleVocabulary::VERDICT_FIRED);

		$rows = $this->service(summaries: ['calculation:bezwaar:uiterlijkeDatum' => $fresh])->inventory(
			schema: $this->schema(),
			idleDays: 90,
			now: new DateTime('2026-09-14 00:00:00')
		);

		$this->assertTrue($rows[0]['ranInsideWindow']);

	}//end testARecentRunIsInsideTheWindow()

	/**
	 * A calculation forwarded on a property appears once, from the same merge
	 * the save listener performs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAPropertyForwardedCalculationIsInventoriedOnce(): void {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties(
			[
				'kenmerk' => [
					'type' => 'string',
					PropertyCalculations::PROPERTY_KEY => ['type' => 'string', 'expression' => ['prop' => 'nummer']],
				],
			]
		);
		$schema->setConfiguration([]);

		$rules = $this->service()->describe(schema: $schema);

		$this->assertCount(1, $rules);
		$this->assertSame('calculation:zaak:kenmerk', $rules[0]->getId());

	}//end testAPropertyForwardedCalculationIsInventoriedOnce()

	/**
	 * A flow subscribed to two events is one rule, listing both.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAFlowOnTwoEventsIsOneRule(): void {
		$service = $this->service(
			triggerRows: [
				['flow_uuid' => 'flow-9', 'event' => 'updated', 'register' => 'zaken', 'enabled' => false],
				['flow_uuid' => 'flow-9', 'event' => 'created', 'register' => 'zaken', 'enabled' => false],
			]
		);

		$rule = $service->find(schema: $this->schema(), ruleId: 'flow:bezwaar:flow-9');

		$this->assertNotNull($rule);
		$this->assertFalse($rule->isEnabled());
		$this->assertSame(['events' => ['created', 'updated']], $rule->getCondition());

	}//end testAFlowOnTwoEventsIsOneRule()
}//end class
