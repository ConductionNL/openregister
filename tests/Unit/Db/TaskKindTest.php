<?php

/**
 * The task's kind: carried from the payload, serialised on read, filterable.
 *
 * Three questions, because a kind that is accepted and then dropped looks
 * exactly like one that works until somebody filters on it. The builder must
 * read it, the serialisation must carry it, and the inbox query must reach
 * the column rather than a JSON blob.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The kind travels from create to read to filter.
 *
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @covers \OCA\OpenRegister\Service\Task\TaskBuilder
 */
class TaskKindTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * The builder reads `kind` from the payload, and leaves it null when the
	 * payload is silent.
	 *
	 * @return void
	 */
	public function testTheBuilderCarriesTheKindAndDefaultsItToNull(): void {
		$builder = new TaskBuilder();

		$kinded = $builder->fromData(
			data: [
				'title' => 'Call the applicant',
				'kind' => 'reminder',
			],
			actor: 'alice'
		);

		$this->assertSame('reminder', $kinded->getKind());

		$plain = $builder->fromData(data: ['title' => 'Assess the request'], actor: 'alice');

		// Null means "work", not "unknown": an ordinary task is not a kind
		// called empty string, and a filter on '' must not find it.
		$this->assertNull($plain->getKind());
	}//end testTheBuilderCarriesTheKindAndDefaultsItToNull()

	/**
	 * The serialised row carries the kind, so every reader of a task sees it
	 * without a second query.
	 *
	 * @return void
	 */
	public function testTheSerialisedRowCarriesTheKind(): void {
		$task = new Task();
		$task->setKind('reminder');

		$row = $task->jsonSerialize();

		$this->assertArrayHasKey('kind', $row);
		$this->assertSame('reminder', $row['kind']);
	}//end testTheSerialisedRowCarriesTheKind()

	/**
	 * The inbox filter reaches the `kind` COLUMN.
	 *
	 * The column is the whole point of the change: `metadata` is declared
	 * carried and never interpreted, and is JSON besides, so a filter that
	 * ended up there would be unindexable and against the entity's own rule.
	 * Asserting the equality predicate on the column name is what separates
	 * the two.
	 *
	 * @return void
	 */
	public function testTheInboxFiltersOnTheKindColumn(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());

		$mapper->findInbox(
			criteria: new TaskInboxCriteria(
				uid: 'root',
				isAdmin: true,
				scope: TaskInboxCriteria::SCOPE_ALL,
				kind: 'reminder'
			)
		);

		$this->assertTrue($this->saw('expr.eq', 'kind'));
	}//end testTheInboxFiltersOnTheKindColumn()

	/**
	 * With no kind asked for, no kind predicate is added.
	 *
	 * The control for the test above: without it, a mapper that filtered on
	 * `kind` unconditionally would pass that one and answer nothing in
	 * production.
	 *
	 * @return void
	 */
	public function testAnInboxThatAsksForNoKindGetsNoKindPredicate(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());

		$mapper->findInbox(
			criteria: new TaskInboxCriteria(
				uid: 'root',
				isAdmin: true,
				scope: TaskInboxCriteria::SCOPE_ALL
			)
		);

		$this->assertFalse($this->saw('expr.eq', 'kind'));
	}//end testAnInboxThatAsksForNoKindGetsNoKindPredicate()
}//end class
