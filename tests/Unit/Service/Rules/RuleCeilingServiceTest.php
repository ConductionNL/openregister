<?php

/**
 * The ceiling: counted before the first write, refused as a whole.
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

use OCA\OpenRegister\Service\Rules\RuleCeilingException;
use OCA\OpenRegister\Service\Rules\RuleCeilingService;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * Task 3.1 and the scenario "a runaway rule writes nothing".
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class RuleCeilingServiceTest extends TestCase {

	/**
	 * A calculation rule declaring the given ceiling.
	 *
	 * @param int|null $maxObjects The ceiling, or null for none.
	 *
	 * @return RuleDescriptor The rule.
	 */
	private function rule(?int $maxObjects): RuleDescriptor {
		return new RuleDescriptor(
			kind: RuleVocabulary::KIND_CALCULATION,
			schemaSlug: 'bezwaar',
			key: 'uiterlijkeDatum',
			label: 'uiterlijkeDatum',
			source: 'x-openregister-calculations.uiterlijkeDatum',
			enabled: true,
			actions: [RuleVocabulary::ACTION_SET_VALUE],
			condition: null,
			maxObjects: $maxObjects
		);

	}//end rule()

	/**
	 * A selection above the ceiling refuses, naming the rule and the count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testASelectionAboveTheCeilingIsRefusedNamingBothNumbers(): void {
		$service = new RuleCeilingService();

		try {
			$service->assert(rule: $this->rule(100), count: 4000);
			$this->fail('A selection of 4,000 under a ceiling of 100 was not refused.');
		} catch (RuleCeilingException $refusal) {
			$this->assertSame('calculation:bezwaar:uiterlijkeDatum', $refusal->getRuleId());
			$this->assertSame(100, $refusal->getCeiling());
			$this->assertSame(4000, $refusal->getCount());
			$this->assertStringContainsString('calculation:bezwaar:uiterlijkeDatum', $refusal->getMessage());
			$this->assertStringContainsString('4000', $refusal->getMessage());
			$this->assertStringContainsString('Nothing was written', $refusal->getMessage());
		}

	}//end testASelectionAboveTheCeilingIsRefusedNamingBothNumbers()

	/**
	 * A selection exactly at the ceiling proceeds.
	 *
	 * The off-by-one is the difference between a ceiling an administrator can
	 * set to the number they measured and one they have to guess above it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testASelectionExactlyAtTheCeilingProceeds(): void {
		$service = new RuleCeilingService();
		$service->assert(rule: $this->rule(100), count: 100);

		$this->assertTrue($service->allows(rule: $this->rule(100), count: 100));
		$this->assertFalse($service->allows(rule: $this->rule(100), count: 101));

	}//end testASelectionExactlyAtTheCeilingProceeds()

	/**
	 * A rule declaring no ceiling is not bounded here (task 5.3).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARuleWithNoCeilingIsNotBounded(): void {
		$service = new RuleCeilingService();
		$service->assert(rule: $this->rule(null), count: 4000000);

		$this->assertTrue($service->allows(rule: $this->rule(null), count: 4000000));

	}//end testARuleWithNoCeilingIsNotBounded()

	/**
	 * The refusal renders in the envelope every rules endpoint returns.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testTheRefusalRendersInTheEndpointEnvelope(): void {
		try {
			(new RuleCeilingService())->assert(rule: $this->rule(10), count: 11);
			$this->fail('The ceiling did not refuse.');
		} catch (RuleCeilingException $refusal) {
			$envelope = $refusal->toArray();
			$this->assertFalse($envelope['ok']);
			$this->assertSame(RuleCeilingService::CODE_CEILING_EXCEEDED, $envelope['error']['code']);
			$this->assertSame(10, $envelope['error']['maxObjects']);
			$this->assertSame(11, $envelope['error']['count']);
		}

	}//end testTheRefusalRendersInTheEndpointEnvelope()

	/**
	 * `allows` and `assert` answer from the same comparison.
	 *
	 * 🔴 The preview and the run must never disagree: a preview that says
	 * "this fits" about a run the ceiling then refuses is the ceiling failing
	 * open in the only place an operator looks before committing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testThePreviewAndTheRunNeverDisagree(): void {
		$service = new RuleCeilingService();

		foreach ([null, 1, 10, 100] as $ceiling) {
			foreach ([0, 1, 9, 10, 11, 100, 101] as $count) {
				$refused = false;
				try {
					$service->assert(rule: $this->rule($ceiling), count: $count);
				} catch (RuleCeilingException $refusal) {
					$refused = true;
				}

				$this->assertSame(
					$service->allows(rule: $this->rule($ceiling), count: $count),
					($refused === false),
					sprintf('ceiling %s, count %d', var_export($ceiling, true), $count)
				);
			}
		}

	}//end testThePreviewAndTheRunNeverDisagree()
}//end class
