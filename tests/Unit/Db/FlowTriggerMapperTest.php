<?php

/**
 * FlowTriggerMapperTest: the trigger index that decides which flows an object event starts.
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Flow\FlowTriggerSet;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowTriggerMapper
 */
class FlowTriggerMapperTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper on a recording connection.
	 *
	 * @return FlowTriggerMapper
	 */
	private function mapper(): FlowTriggerMapper {
		return new FlowTriggerMapper($this->recordingConnection());
	}//end mapper()

	public function testFlowUuidsSubscribedToMatchesEventRegisterSchemaAndOnlyEnabledRows(): void {
		$this->rowSets = [[['flow_uuid' => 'f1'], ['flow_uuid' => 22]]];

		$this->assertSame(['f1', '22'], $this->mapper()->flowUuidsSubscribedTo('object.created', new RegisterSchemaRef(register: 'zaken', schema: 'zaak')));
		$this->assertSame(
			[
				'selectDistinct(flow_uuid)',
				'from(openregister_flow_triggers)',
				"where(eq(event, 'object.created'))",
				"andWhere(eq(register, 'zaken'))",
				"andWhere(eq(schema_slug, 'zaak'))",
				'andWhere(eq(enabled, true))',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testFlowUuidsSubscribedToMatchesEventRegisterSchemaAndOnlyEnabledRows()

	public function testFlowUuidsSubscribedToWithoutAMatchIsEmpty(): void {
		$this->assertSame([], $this->mapper()->flowUuidsSubscribedTo('object.deleted', new RegisterSchemaRef(register: 'r', schema: 's')));
	}//end testFlowUuidsSubscribedToWithoutAMatchIsEmpty()

	public function testFindBySchemaListsEachTriggerTypedAndOrdered(): void {
		$this->rowSets = [[
			['flow_uuid' => 'f1', 'event' => 'object.created', 'register' => 'zaken', 'enabled' => '1'],
			['flow_uuid' => 'f2', 'event' => 'object.updated', 'register' => 'zaken', 'enabled' => 0],
		]];

		$this->assertSame(
			[
				['flow_uuid' => 'f1', 'event' => 'object.created', 'register' => 'zaken', 'enabled' => true],
				['flow_uuid' => 'f2', 'event' => 'object.updated', 'register' => 'zaken', 'enabled' => false],
			],
			$this->mapper()->findBySchema('zaak')
		);
		$this->assertSame(
			[
				'select(flow_uuid, event, register, enabled)',
				'from(openregister_flow_triggers)',
				"where(eq(schema_slug, 'zaak'))",
				'orderBy(flow_uuid, ASC)',
				'addOrderBy(event, ASC)',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testFindBySchemaListsEachTriggerTypedAndOrdered()

	public function testFindBySchemaWithoutTriggersIsEmpty(): void {
		$this->assertSame([], $this->mapper()->findBySchema('none'));
	}//end testFindBySchemaWithoutTriggersIsEmpty()

	public function testRepresentedFlowUuidsListsEveryIndexedFlowOnce(): void {
		$this->rowSets = [[['flow_uuid' => 'f1'], ['flow_uuid' => 'f2']]];

		$this->assertSame(['f1', 'f2'], $this->mapper()->representedFlowUuids());
		$this->assertSame(['selectDistinct(flow_uuid)', 'from(openregister_flow_triggers)', 'executeQuery'], $this->queries[0]);
	}//end testRepresentedFlowUuidsListsEveryIndexedFlowOnce()

	public function testReplaceForDeletesTheOldRowsThenInsertsOnePerTrigger(): void {
		$this->statementResults = [5];

		$written = $this->mapper()->replaceFor(
			new FlowTriggerSet(
				flowUuid: 'f1',
				triggers: [
					['event' => 'object.created', 'register' => 'zaken', 'schema' => 'zaak'],
					['event' => 'object.updated', 'register' => 7, 'schema' => 'besluit'],
				],
				enabled: false
			)
		);

		$this->assertSame(2, $written);
		$this->assertSame(['delete(openregister_flow_triggers)', "where(eq(flow_uuid, 'f1'))", 'executeStatement'], $this->queries[0]);
		$this->assertSame(
			[
				'insert(openregister_flow_triggers)',
				'values({"flow_uuid":"\'f1\'","event":"\'object.created\'","register":"\'zaken\'","schema_slug":"\'zaak\'","enabled":"false"})',
				'executeStatement',
			],
			$this->queries[1]
		);
		$this->assertStringContainsString('"register":"\'7\'"', $this->queries[2][1]);
	}//end testReplaceForDeletesTheOldRowsThenInsertsOnePerTrigger()

	public function testReplaceForWithNoTriggersOnlyClearsTheFlow(): void {
		$this->assertSame(0, $this->mapper()->replaceFor(new FlowTriggerSet(flowUuid: 'f1', triggers: [], enabled: true)));
		$this->assertCount(1, $this->queries);
		$this->assertSame('delete(openregister_flow_triggers)', $this->queries[0][0]);
	}//end testReplaceForWithNoTriggersOnlyClearsTheFlow()

	public function testDeleteForReportsTheRowsRemoved(): void {
		$this->statementResults = [3];

		$this->assertSame(3, $this->mapper()->deleteFor('f9'));
		$this->assertSame(['delete(openregister_flow_triggers)', "where(eq(flow_uuid, 'f9'))", 'executeStatement'], $this->queries[0]);
	}//end testDeleteForReportsTheRowsRemoved()
}//end class
