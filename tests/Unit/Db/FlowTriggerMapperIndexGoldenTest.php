<?php

/**
 * FlowTriggerMapper index golden tests.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\Flow\FlowTriggerSet;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The hot-path read and the replace of the derived trigger index.
 */
class FlowTriggerMapperIndexGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * flowUuidsFor() matches event, register, schema and enabled, and returns the uuids as strings.
	 *
	 * @return void
	 */
	public function testFlowUuidsForMatchesTheEventOnOneTable(): void {
		$this->results[] = [['flow_uuid' => 'f-1'], ['flow_uuid' => 'f-2']];

		$uuids = (new FlowTriggerMapper($this->recordingDb()))->flowUuidsFor('object.created', 'zaken', 'zaak');

		$this->assertSame(['f-1', 'f-2'], $uuids);
		$this->assertSame('selectDistinct(flow_uuid) from(openregister_flow_triggers) where(eq(event, :p0)) andWhere(eq(register, :p1)) andWhere(eq(schema_slug, :p2)) andWhere(eq(enabled, :p3)) executeQuery()', $this->sql());
		$this->assertSame(['object.created', 'zaken', 'zaak', true], [$this->bound(':p0'), $this->bound(':p1'), $this->bound(':p2'), $this->bound(':p3')]);
		$this->assertSame(IQueryBuilder::PARAM_BOOL, $this->params[':p3'][1]);
	}//end testFlowUuidsForMatchesTheEventOnOneTable()

	/**
	 * replaceFor() deletes the flow's rows, then inserts one row per trigger and counts them.
	 *
	 * @return void
	 */
	public function testReplaceForDeletesThenInsertsEachTrigger(): void {
		$written = (new FlowTriggerMapper($this->recordingDb()))->replaceFor(
			new FlowTriggerSet(
				'f-1',
				[
					['event' => 'object.created', 'register' => 'zaken', 'schema' => 'zaak'],
					['event' => 'object.updated', 'register' => 7, 'schema' => 'besluit'],
				],
				false
			)
		);

		$this->assertSame(2, $written);
		$this->assertSame([
				'delete(openregister_flow_triggers) where(eq(flow_uuid, :p0)) executeStatement()',
				'insert(openregister_flow_triggers) values([:p1, :p2, :p3, :p4, :p5]) executeStatement()',
				'insert(openregister_flow_triggers) values([:p6, :p7, :p8, :p9, :p10]) executeStatement()',
			], [$this->sql(0), $this->sql(1), $this->sql(2)]);
		$this->assertSame('{":p0":["f-1",2],":p1":["f-1",2],":p2":["object.created",2],":p3":["zaken",2],":p4":["zaak",2],":p5":[false,"boolean"],'
			. '":p6":["f-1",2],":p7":["object.updated",2],":p8":["7",2],":p9":["besluit",2],":p10":[false,"boolean"]}', json_encode($this->params));
	}//end testReplaceForDeletesThenInsertsEachTrigger()

	/**
	 * replaceFor() with no triggers only deletes.
	 *
	 * @return void
	 */
	public function testReplaceForWithoutTriggersOnlyDeletes(): void {
		$written = (new FlowTriggerMapper($this->recordingDb()))->replaceFor(new FlowTriggerSet('f-1', [], true));

		$this->assertSame(0, $written);
		$this->assertCount(1, $this->queries);
	}//end testReplaceForWithoutTriggersOnlyDeletes()
}//end class
