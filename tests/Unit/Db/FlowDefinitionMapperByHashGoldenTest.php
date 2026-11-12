<?php

/**
 * Characterisation of FlowDefinitionMapper::findByHash(): a blank hash is null without a query,
 * the stored definition is read by its hash, and any read failure answers null.
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

use OCA\OpenRegister\Db\FlowDefinitionMapper;
use PHPUnit\Framework\TestCase;

/**
 * Golden hash lookup of flow definitions.
 *
 * @covers \OCA\OpenRegister\Db\FlowDefinitionMapper
 */
final class FlowDefinitionMapperByHashGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * A blank hash is null without a query.
	 *
	 * @return void
	 */
	public function testBlankHash(): void {
		$mapper = new FlowDefinitionMapper($this->recordingDb());

		$this->assertNull($mapper->findByHash(''));
		$this->assertNull($mapper->findByHash("  \t"));
		$this->assertSame([], $this->queries);
	}//end testBlankHash()

	/**
	 * The definition is read by its hash, one row at most.
	 *
	 * @return void
	 */
	public function testStoredDefinition(): void {
		$this->results[] = [['id' => 3, 'hash' => 'h-1']];

		$this->assertSame(3, (new FlowDefinitionMapper($this->recordingDb()))->findByHash('h-1')?->getId());
		$this->assertSame(
			'select(*) from(openregister_flow_defs) where(eq(hash, :p0)) setMaxResults(1) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['h-1'], $this->boundValues());
	}//end testStoredDefinition()

	/**
	 * No row, or a failing read, is null.
	 *
	 * @return void
	 */
	public function testMissingOrFailingReadIsNull(): void {
		$this->results[] = [];
		$this->results[] = [['id' => 1], ['id' => 2]];
		$mapper = new FlowDefinitionMapper($this->recordingDb());

		$this->assertNull($mapper->findByHash('missing'));
		$this->assertNull($mapper->findByHash('twice'));
	}//end testMissingOrFailingReadIsNull()
}//end class
