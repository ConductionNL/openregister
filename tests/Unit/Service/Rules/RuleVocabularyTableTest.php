<?php

/**
 * RuleVocabularyTable: the listing of rule kinds, verdicts and actions.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Rules\RuleVocabularyTable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\RuleVocabularyTable
 */
class RuleVocabularyTableTest extends TestCase {

	public function testKindsAreListedWithTheirDescriptorAndTheKindFirst(): void {
		$kinds = (new RuleVocabularyTable())->listing()['kinds'];

		$this->assertSame(
			['calculation', 'stateFieldRule', 'lifecycleCondition', 'administeredValidation', 'flow'],
			array_column($kinds, 'kind')
		);
		$this->assertSame(['kind', 'order', 'source', 'actions', 'description'], array_keys($kinds[0]));
		$this->assertSame(
			[
				'kind' => 'flow',
				'order' => 5,
				'source' => 'openregister_flow_triggers',
				'actions' => ['runFlow'],
				'description' => 'Runs a flow after the object is stored.',
			],
			$kinds[4]
		);
	}

	public function testTheValidationIsOrderedBeforeTheFlow(): void {
		$kinds = (new RuleVocabularyTable())->listing()['kinds'];

		$this->assertSame([1, 2, 3, 4, 5], array_column($kinds, 'order'));
	}

	public function testVerdictsAndActionsArePairedWithTheirDescriptions(): void {
		$listing = (new RuleVocabularyTable())->listing();

		$this->assertSame(
			['verdict' => RuleVocabulary::VERDICT_NO_MATCH, 'description' => 'The rule was evaluated and its condition did not hold.'],
			$listing['verdicts'][1]
		);
		$this->assertSame(['fired', 'no_match', 'refused', 'error'], array_column($listing['verdicts'], 'verdict'));
		$this->assertCount(count(RuleVocabularyTable::ACTIONS), $listing['actions']);
		$this->assertSame(
			['action' => 'setValue', 'description' => 'Writes a value onto the object being saved.'],
			$listing['actions'][0]
		);
	}

	public function testEveryActionAKindDeclaresIsDescribed(): void {
		foreach (RuleVocabularyTable::KINDS as $kind => $descriptor) {
			foreach ($descriptor['actions'] as $action) {
				$this->assertArrayHasKey($action, RuleVocabularyTable::ACTIONS, $kind . ' declares an undescribed action');
			}
		}
	}
}
