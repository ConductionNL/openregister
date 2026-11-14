<?php

/**
 * The rule inventory says which rules use each named condition.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md#requirement-a-condition-is-named-once-and-used-inside-other-rules-req-rct-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Rules\FlowRuleDescriptors;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see RuleInventoryService::namedConditions()}.
 */
class RuleInventoryNamedConditionsTest extends TestCase {

	/**
	 * A condition used by three rules lists those three rules against it.
	 *
	 * @return void
	 */
	public function testTheInventorySaysWhoUsesIt(): void {
		$rows = $this->byName(rows: $this->service()->namedConditions(schema: $this->schema()));

		$this->assertSame(
			[
				RuleDescriptor::idFor(kind: RuleVocabulary::KIND_STATE_FIELD_RULE, schemaSlug: 'melding', key: 'behandeling'),
				RuleDescriptor::idFor(kind: RuleVocabulary::KIND_LIFECYCLE_CONDITION, schemaSlug: 'melding', key: 'afronden'),
				RuleDescriptor::idFor(kind: RuleVocabulary::KIND_ADMINISTERED_VALIDATION, schemaSlug: 'melding', key: 'spoedHeeftBehandelaar'),
			],
			$this->sorted(ids: $rows['spoedeisend']['usedBy'], order: [RuleVocabulary::KIND_STATE_FIELD_RULE, RuleVocabulary::KIND_LIFECYCLE_CONDITION, RuleVocabulary::KIND_ADMINISTERED_VALIDATION])
		);
		$this->assertSame('Spoed: prioriteit hoog of wettelijke termijn.', $rows['spoedeisend']['description']);
		$this->assertTrue($rows['spoedeisend']['declared']);
	}//end testTheInventorySaysWhoUsesIt()

	/**
	 * A declared condition nobody uses is listed with no users, so it can be found and removed.
	 *
	 * @return void
	 */
	public function testAnUnusedConditionIsListedWithNoUsers(): void {
		$rows = $this->byName(rows: $this->service()->namedConditions(schema: $this->schema()));

		$this->assertSame([], $rows['ongebruikt']['usedBy']);
		$this->assertTrue($rows['ongebruikt']['declared']);
	}//end testAnUnusedConditionIsListedWithNoUsers()

	/**
	 * A condition that only composes another is not a rule: only direct users are listed.
	 *
	 * @return void
	 */
	public function testACompositionIsNotListedAsAUser(): void {
		$rows = $this->byName(rows: $this->service()->namedConditions(schema: $this->schema()));

		$this->assertNotContains('samengesteld', $rows['spoedeisend']['usedBy']);
		$this->assertCount(3, $rows['spoedeisend']['usedBy']);
	}//end testACompositionIsNotListedAsAUser()

	/**
	 * A name a rule references but the schema does not declare is listed as undeclared.
	 *
	 * @return void
	 */
	public function testAnUndeclaredReferenceIsListedAsUndeclared(): void {
		$rows = $this->byName(rows: $this->service()->namedConditions(schema: $this->schema()));

		$this->assertFalse($rows['bestaatNiet']['declared']);
		$this->assertSame(
			[RuleDescriptor::idFor(kind: RuleVocabulary::KIND_LIFECYCLE_CONDITION, schemaSlug: 'melding', key: 'heropenen')],
			$rows['bestaatNiet']['usedBy']
		);
	}//end testAnUndeclaredReferenceIsListedAsUndeclared()

	/**
	 * A schema without named conditions answers an empty list.
	 *
	 * @return void
	 */
	public function testASchemaWithoutNamedConditionsAnswersNone(): void {
		$schema = new Schema();
		$schema->setSlug('melding');
		$schema->setConfiguration(['x-openregister-lifecycle' => ['field' => 'status', 'transitions' => ['afronden' => ['from' => ['open'], 'to' => 'klaar', 'condition' => ['==' => [1, 1]]]]]]);

		$this->assertSame([], $this->service()->namedConditions(schema: $schema));
	}//end testASchemaWithoutNamedConditionsAnswersNone()

	/**
	 * The service over doubles for the stores it reads.
	 *
	 * @return RuleInventoryService The service.
	 */
	private function service(): RuleInventoryService {
		$triggers = $this->createMock(FlowTriggerMapper::class);
		$triggers->method('findBySchema')->willReturn([]);
		$summaries = $this->createMock(RuleRunSummaryMapper::class);
		$summaries->method('findBySchema')->willReturn([]);

		return new RuleInventoryService(
			summaries: $summaries,
			flows: new FlowRuleDescriptors(triggers: $triggers, published: $this->createMock(FlowPublishedGraph::class))
		);
	}//end service()

	/**
	 * A melding schema whose named condition is used by a state block, a transition and a validation.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$spoed = ['$condition' => 'spoedeisend'];
		$schema = new Schema();
		$schema->setSlug('melding');
		$schema->setProperties(['status' => ['type' => 'string'], 'behandelaar' => ['type' => 'string'], 'prioriteit' => ['type' => 'string']]);
		$schema->setConfiguration(
			[
				'x-openregister-conditions' => [
					'spoedeisend' => ['description' => 'Spoed: prioriteit hoog of wettelijke termijn.', 'expression' => ['==' => [['var' => 'prioriteit'], 'hoog']]],
					'samengesteld' => ['expression' => ['and' => [$spoed, ['!' => ['var' => 'behandelaar']]]]],
					'ongebruikt' => ['expression' => ['==' => [1, 1]]],
				],
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'states' => ['behandeling' => ['condition' => $spoed, 'fields' => ['behandelaar' => ['required' => true]]]],
					'transitions' => [
						'afronden' => ['from' => ['behandeling'], 'to' => 'klaar', 'condition' => ['not' => $spoed]],
						'heropenen' => ['from' => ['klaar'], 'to' => 'behandeling', 'condition' => ['$condition' => 'bestaatNiet']],
					],
				],
				'x-openregister-validations' => [
					'spoedHeeftBehandelaar' => ['condition' => ['and' => [$spoed, ['!' => ['var' => 'behandelaar']]]], 'severity' => 'error', 'message' => 'Een spoedmelding heeft een behandelaar nodig.'],
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The rows keyed by condition name.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>> The rows by name.
	 */
	private function byName(array $rows): array {
		return array_column($rows, null, 'name');
	}//end byName()

	/**
	 * Rule ids in the given kind order, so the assertion does not depend on the inventory's own sort.
	 *
	 * @param array<int, string> $ids   The rule ids.
	 * @param array<int, string> $order The kinds, in order.
	 *
	 * @return array<int, string> The ids.
	 */
	private function sorted(array $ids, array $order): array {
		usort(
			$ids,
			static function (string $left, string $right) use ($order): int {
				$rank = static function (string $id) use ($order): int {
					foreach ($order as $index => $kind) {
						if (str_contains($id, $kind) === true) {
							return $index;
						}
					}

					return count($order);
				};

				return $rank($left) <=> $rank($right);
			}
		);

		return $ids;
	}//end sorted()
}//end class
