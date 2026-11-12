<?php

/**
 * Golden test for ExampleSetTaskDates and ExampleSetTaskChecklist: the refusal
 * each answers, and the moments ExampleSetTaskDates::moment() reads, for a fixed
 * table of task fields. Dates are fixed and far from today; "in the future" is
 * the year 9999. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskChecklist;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskDates;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskDates
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskChecklist
 */
class ExampleSetTaskChecksGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-task-checks-golden.json';

	public function testTaskChecksMatchGolden(): void {
		$actual = ['dates' => [], 'moment' => [], 'checklist' => []];
		foreach ($this->dates() as $name => $data) {
			$actual['dates'][$name] = ExampleSetTaskDates::refusal(data: $data);
		}

		$values = ['date' => '2024-01-02', 'moment' => '2024-01-02T10:00:00+02:00', 'blank' => ' ', 'garbage' => 'nope', 'int' => 5, 'null' => null];
		foreach ($values as $name => $value) {
			$actual['moment'][$name] = ExampleSetTaskDates::moment(value: $value)?->format('c');
		}

		foreach ($this->checklists() as $name => $data) {
			$actual['checklist'][$name] = ExampleSetTaskChecklist::refusal(data: $data);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $part => $outcomes) {
			$this->assertSame($outcomes, $actual[$part], $part);
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function dates(): array {
		return [
			'none'                     => [],
			'all good'                 => [
				'dueAt'       => '2024-01-02',
				'expiresAt'   => '2024-01-03',
				'createdAt'   => '2024-01-01',
				'claimedAt'   => '2024-01-01T12:00:00Z',
				'completedAt' => '2024-01-02',
			],
			'due not a date'           => ['dueAt' => 'soon'],
			'expires not a date'       => ['expiresAt' => '2024-13-45x'],
			'completed blank'          => ['completedAt' => ' '],
			'claimed int'              => ['claimedAt' => 5],
			'created not a date'       => ['createdAt' => 'nope'],
			'null is not set'          => ['dueAt' => null],
			'first bad field wins'     => ['createdAt' => 'nope', 'dueAt' => 'soon'],
			'expires before due'       => ['dueAt' => '2024-01-02', 'expiresAt' => '2024-01-01'],
			'expires equals due'       => ['dueAt' => '2024-01-02', 'expiresAt' => '2024-01-02'],
			'expires without due'      => ['expiresAt' => '2024-01-01'],
			'created in future'        => ['createdAt' => '9999-01-01'],
			'claimed before created'   => ['createdAt' => '2024-01-02', 'claimedAt' => '2024-01-01'],
			'completed before created' => ['createdAt' => '2024-01-02', 'completedAt' => '2024-01-01'],
			'both before created'      => ['createdAt' => '2024-01-02', 'completedAt' => '2024-01-01', 'claimedAt' => '2023-01-01'],
			'claimed same as created'  => ['createdAt' => '2024-01-02T10:00:00+02:00', 'claimedAt' => '2024-01-02T08:00:00Z'],
			'claimed without created'  => ['claimedAt' => '2024-01-01'],
			'expiry before due wins'   => ['dueAt' => '2024-01-02', 'expiresAt' => '2024-01-01', 'createdAt' => '9999-01-01'],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function checklists(): array {
		$item = ['id' => 'a', 'label' => 'A'];

		return [
			'none'               => [],
			'null'               => ['checklist' => null],
			'empty'              => ['checklist' => []],
			'map'                => ['checklist' => ['x' => $item]],
			'text'               => ['checklist' => 'x'],
			'good'               => ['checklist' => [
				$item,
				['id' => 'b', 'label' => 'B', 'checked' => true],
				['id' => 'c', 'label' => 'C', 'checked' => false],
			]],
			'item not array'     => ['checklist' => ['x']],
			'no id'              => ['checklist' => [['label' => 'A']]],
			'blank id'           => ['checklist' => [['id' => ' ', 'label' => 'A']]],
			'int id'             => ['checklist' => [['id' => 1, 'label' => 'A']]],
			'no label'           => ['checklist' => [['id' => 'a']]],
			'blank label'        => ['checklist' => [['id' => 'a', 'label' => '']]],
			'checked not bool'   => ['checklist' => [$item + ['checked' => 'yes']]],
			'checked null'       => ['checklist' => [$item + ['checked' => null]]],
			'id twice'           => ['checklist' => [$item, ['id' => 'a', 'label' => 'Again']]],
			'second item bad'    => ['checklist' => [$item, ['id' => 'b']]],
			'checked before dup' => ['checklist' => [$item, ['id' => 'a', 'label' => 'A', 'checked' => 1]]],
		];
	}
}
