<?php

/**
 * Golden record of DeckLink::jsonSerialize() over the JSON-column shapes it decodes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\DeckLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\DeckLink
 */
class DeckLinkJsonSerializeGoldenTest extends TestCase {
	/**
	 * Labels / assignees column values and the arrays they serialise to.
	 *
	 * @return array<string, array{0: string|null, 1: array<mixed>}>
	 */
	public static function columns(): array {
		return [
			'null' => [null, []],
			'empty string' => ['', []],
			'list' => ['[{"id":1,"title":"Urgent"},{"id":2,"title":"Bug"}]', [['id' => 1, 'title' => 'Urgent'], ['id' => 2, 'title' => 'Bug']]],
			'object' => ['{"a":"b"}', ['a' => 'b']],
			'empty list' => ['[]', []],
			'scalar' => ['5', []],
			'json string' => ['"alice"', []],
			'json null' => ['null', []],
			'malformed' => ['[1,', []],
			'whitespace' => [' ', []],
		];
	}//end columns()

	#[DataProvider('columns')]
	public function testLabelsAndAssigneesDecodeIndependently(?string $column, array $expected): void {
		$labelsOnly = new DeckLink();
		$labelsOnly->setLabels($column);
		$labelsOnly->setAssignees('["bob"]');
		$this->assertSame($expected, $labelsOnly->jsonSerialize()['labels']);
		$this->assertSame(['bob'], $labelsOnly->jsonSerialize()['assignees']);

		$assigneesOnly = new DeckLink();
		$assigneesOnly->setAssignees($column);
		$this->assertSame($expected, $assigneesOnly->jsonSerialize()['assignees']);
		$this->assertSame([], $assigneesOnly->jsonSerialize()['labels']);
	}//end testLabelsAndAssigneesDecodeIndependently()

	public function testFullRowKeysOrderAndValues(): void {
		$link = new DeckLink();
		$link->setId(3);
		$link->setObjectUuid('obj-1');
		$link->setRegisterId(5);
		$link->setSchemaId(6);
		$link->setBoardId(1);
		$link->setStackId(2);
		$link->setCardId(15);
		$link->setCardTitle('Card');
		$link->setDueDate(new DateTime('2026-04-01T09:30:00+02:00'));
		$link->setLabels('[{"id":1}]');
		$link->setAssignees('["alice","bob"]');
		$link->setLinkedBy('admin');
		$link->setLinkedAt(new DateTime('2026-03-25T11:00:00+00:00'));

		$this->assertSame(
			[
				'id' => 3,
				'objectUuid' => 'obj-1',
				'registerId' => 5,
				'schemaId' => 6,
				'boardId' => 1,
				'stackId' => 2,
				'cardId' => 15,
				'cardTitle' => 'Card',
				'dueDate' => '2026-04-01T09:30:00+02:00',
				'labels' => [['id' => 1]],
				'assignees' => ['alice', 'bob'],
				'linkedBy' => 'admin',
				'linkedAt' => '2026-03-25T11:00:00+00:00',
			],
			$link->jsonSerialize()
		);
	}//end testFullRowKeysOrderAndValues()

	public function testEmptyRow(): void {
		$this->assertSame(
			[
				'id' => null,
				'objectUuid' => null,
				'registerId' => null,
				'schemaId' => null,
				'boardId' => null,
				'stackId' => null,
				'cardId' => null,
				'cardTitle' => null,
				'dueDate' => null,
				'labels' => [],
				'assignees' => [],
				'linkedBy' => null,
				'linkedAt' => null,
			],
			(new DeckLink())->jsonSerialize()
		);
	}//end testEmptyRow()
}//end class
