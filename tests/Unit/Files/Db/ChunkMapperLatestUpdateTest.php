<?php

/**
 * The time a source's chunks were last updated: the newest updated_at of
 * that source type and id, as a Unix timestamp, or null when it has no
 * chunks or the stored value is not a time.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordsQueries;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * ChunkMapper::getLatestUpdatedTimestamp().
 *
 * @covers \OCA\OpenRegister\Files\Db\ChunkMapper
 */
final class ChunkMapperLatestUpdateTest extends TestCase {
	use RecordsQueries;

	private ChunkMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new ChunkMapper($this->recordingDb());
	}//end setUp()

	public function testTheNewestUpdateOfTheSourceIsReadAsATimestamp(): void {
		$this->results[] = [['max_updated_at' => '2026-03-04 05:06:07']];

		$this->assertSame(strtotime('2026-03-04 05:06:07'), $this->mapper->getLatestUpdatedTimestamp('file', 81));

		$this->assertStringContainsString('from(openregister_chunks)', $this->sql());
		$this->assertStringContainsString('where(andX(eq(source_type, :p0), eq(source_id, :p1)))', $this->sql());
		$this->assertSame(['file', IQueryBuilder::PARAM_STR], $this->params[':p0']);
		$this->assertSame([81, IQueryBuilder::PARAM_INT], $this->params[':p1']);
	}//end testTheNewestUpdateOfTheSourceIsReadAsATimestamp()

	public function testASourceWithoutChunksHasNoLatestUpdate(): void {
		$this->assertNull($this->mapper->getLatestUpdatedTimestamp('object', 3));
	}//end testASourceWithoutChunksHasNoLatestUpdate()

	public function testANullMaximumHasNoLatestUpdate(): void {
		$this->results[] = [['max_updated_at' => null]];

		$this->assertNull($this->mapper->getLatestUpdatedTimestamp('object', 3));
	}//end testANullMaximumHasNoLatestUpdate()

	public function testAValueThatIsNotATimeHasNoLatestUpdate(): void {
		$this->results[] = [['max_updated_at' => 'not a time']];

		$this->assertNull($this->mapper->getLatestUpdatedTimestamp('object', 3));
	}//end testAValueThatIsNotATimeHasNoLatestUpdate()
}//end class
