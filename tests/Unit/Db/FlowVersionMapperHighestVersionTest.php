<?php

/**
 * FlowVersionMapperHighestVersionTest: the next version number is read from the version rows.
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

use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowVersionMapper
 */
class FlowVersionMapperHighestVersionTest extends TestCase {
	use RecordsQueries;

	public function testHighestVersionReadsTheTopVersionRow(): void {
		$this->rowSets = [[['version' => '3']]];

		$this->assertSame(3, (new FlowVersionMapper($this->recordingConnection()))->highestVersion('f1'));
		$this->assertSame(
			['select(version)', 'from(openregister_flow_versions)', "where(eq(flow_uuid, 'f1'))", 'orderBy(version, DESC)', 'setMaxResults(1)', 'executeQuery'],
			$this->queries[0]
		);
	}//end testHighestVersionReadsTheTopVersionRow()

	public function testAFlowWithoutVersionsIsAtZero(): void {
		$this->rowSets = [[]];

		$this->assertSame(0, (new FlowVersionMapper($this->recordingConnection()))->highestVersion('f1'));
	}//end testAFlowWithoutVersionsIsAtZero()

	public function testARowWithoutAVersionColumnIsAtZero(): void {
		$this->rowSets = [[['flow_uuid' => 'f1']]];

		$this->assertSame(0, (new FlowVersionMapper($this->recordingConnection()))->highestVersion('f1'));
	}//end testARowWithoutAVersionColumnIsAtZero()
}//end class
