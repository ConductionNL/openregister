<?php

/**
 * ExampleSetTaskChecklist refuses a malformed task checklist and accepts a
 * well-formed one or none.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskChecklist;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskChecklist
 */
class ExampleSetTaskChecklistTest extends TestCase {
	/**
	 * No checklist key means nothing to check.
	 *
	 * @return void
	 */
	public function testAbsentChecklistIsAccepted(): void {
		$this->assertSame('', ExampleSetTaskChecklist::refusal(['title' => 'x']));
	}

	/**
	 * A present but null checklist is refused, not skipped.
	 *
	 * @return void
	 */
	public function testNullChecklistIsRefused(): void {
		$this->assertSame('checklist must be a list of items', ExampleSetTaskChecklist::refusal(['checklist' => null]));
	}

	/**
	 * A string or a keyed map is not a list.
	 *
	 * @return void
	 */
	public function testNonListChecklistIsRefused(): void {
		$this->assertSame('checklist must be a list of items', ExampleSetTaskChecklist::refusal(['checklist' => 'a']));
		$this->assertSame(
			'checklist must be a list of items',
			ExampleSetTaskChecklist::refusal(['checklist' => ['a' => ['id' => 'a', 'label' => 'A']]])
		);
	}

	/**
	 * An empty list and a well-formed list are accepted.
	 *
	 * @return void
	 */
	public function testWellFormedChecklistIsAccepted(): void {
		$this->assertSame('', ExampleSetTaskChecklist::refusal(['checklist' => []]));
		$this->assertSame(
			'',
			ExampleSetTaskChecklist::refusal(
				['checklist' => [['id' => 'a', 'label' => 'A', 'checked' => true], ['id' => 'b', 'label' => 'B', 'checked' => false], ['id' => 'c', 'label' => 'C']]]
			)
		);
	}

	/**
	 * Each missing or blank id or label, and a non-array item, is refused.
	 *
	 * @return void
	 */
	public function testItemWithoutIdOrLabelIsRefused(): void {
		$bad = [
			'scalar item'   => 'a',
			'no id'         => ['label' => 'A'],
			'blank id'      => ['id' => '  ', 'label' => 'A'],
			'numeric id'    => ['id' => 1, 'label' => 'A'],
			'no label'      => ['id' => 'a'],
			'blank label'   => ['id' => 'a', 'label' => ''],
		];
		foreach ($bad as $case => $item) {
			$this->assertSame(
				'every checklist item needs an id and a label',
				ExampleSetTaskChecklist::refusal(['checklist' => [$item]]),
				$case
			);
		}
	}

	/**
	 * A non-boolean checked flag is refused and the item is named.
	 *
	 * @return void
	 */
	public function testNonBooleanCheckedIsRefused(): void {
		$this->assertSame(
			'checklist item "a": checked is true or false',
			ExampleSetTaskChecklist::refusal(['checklist' => [['id' => 'a', 'label' => 'A', 'checked' => 'yes']]])
		);
	}

	/**
	 * A duplicate id is refused; the first bad item stops the walk.
	 *
	 * @return void
	 */
	public function testDuplicateIdIsRefused(): void {
		$this->assertSame(
			'checklist item id "a" is used twice',
			ExampleSetTaskChecklist::refusal(
				['checklist' => [['id' => 'a', 'label' => 'A'], ['id' => 'a', 'label' => 'B'], ['label' => 'no id']]]
			)
		);
	}
}
