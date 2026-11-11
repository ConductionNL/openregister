<?php

/**
 * Characterisation of FlowTriggerMapper::findBySchema(): the query for one schema's
 * triggers and the typed rows it returns.
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

use OCA\OpenRegister\Db\FlowTriggerMapper;
use PHPUnit\Framework\TestCase;

/**
 * Golden schema read of the flow trigger index.
 *
 * @covers \OCA\OpenRegister\Db\FlowTriggerMapper
 */
final class FlowTriggerMapperBySchemaGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * One schema's triggers, by flow then event, with typed values.
	 *
	 * @return void
	 */
	public function testFindBySchema(): void {
		$this->results[] = [
			['flow_uuid' => 'f-1', 'event' => 'created', 'register' => 'reg', 'enabled' => '1'],
			['flow_uuid' => 'f-2', 'event' => 'updated', 'register' => null, 'enabled' => 0],
		];

		$rows = (new FlowTriggerMapper($this->recordingDb()))->findBySchema('person');

		$this->assertSame(
			[
				['flow_uuid' => 'f-1', 'event' => 'created', 'register' => 'reg', 'enabled' => true],
				['flow_uuid' => 'f-2', 'event' => 'updated', 'register' => '', 'enabled' => false],
			],
			$rows
		);
		$this->assertSame(
			'select(flow_uuid, event, register, enabled) from(openregister_flow_triggers) where(eq(schema_slug, :p0)) orderBy(flow_uuid, ASC) addOrderBy(event, ASC) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['person'], $this->boundValues());
	}//end testFindBySchema()

	/**
	 * A schema without triggers has none.
	 *
	 * @return void
	 */
	public function testSchemaWithoutTriggers(): void {
		$this->assertSame([], (new FlowTriggerMapper($this->recordingDb()))->findBySchema('none'));
	}//end testSchemaWithoutTriggers()
}//end class
