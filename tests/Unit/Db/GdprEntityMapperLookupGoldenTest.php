<?php

/**
 * Characterisation of GdprEntityMapper::findOneByValueAndType(): the query it sends, the
 * canonical row it returns, and the warning (type and ids only, never the value) when the
 * catalogue holds two rows for one value and type.
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

use OCA\OpenRegister\Db\GdprEntityMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden query, result and warning of the catalogue lookup.
 *
 * @covers \OCA\OpenRegister\Db\GdprEntityMapper
 */
final class GdprEntityMapperLookupGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * The lookup matches value and type, lowest id first, reading at most two rows.
	 *
	 * @return void
	 */
	public function testQueryMatchesValueAndTypeLowestIdFirst(): void {
		$mapper = new GdprEntityMapper($this->recordingDb());

		$this->assertNull($mapper->findOneByValueAndType('Jan Jansen', 'PERSON'));
		$this->assertSame(
			'select(*) from(openregister_entities) where(andX(eq(value, :p0), eq(type, :p1))) orderBy(id, ASC) setMaxResults(2) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['Jan Jansen', IQueryBuilder::PARAM_STR], $this->params[':p0']);
		$this->assertSame(['PERSON', IQueryBuilder::PARAM_STR], $this->params[':p1']);
	}//end testQueryMatchesValueAndTypeLowestIdFirst()

	/**
	 * One row is returned without a warning.
	 *
	 * @return void
	 */
	public function testOneRowIsReturnedQuietly(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$this->results[] = [['id' => 4, 'value' => 'v', 'type' => 'EMAIL']];

		$this->assertSame(4, (new GdprEntityMapper($this->recordingDb(), $logger))->findOneByValueAndType('v', 'EMAIL')?->getId());
	}//end testOneRowIsReturnedQuietly()

	/**
	 * Two rows: the lowest id wins and the collision is logged by type and ids, never by value.
	 *
	 * @return void
	 */
	public function testTwoRowsWarnByTypeAndIds(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertSame(
					'[GdprEntityMapper] findOneByValueAndType: multiple catalogue rows match the same (value, type) — dedup invariant violated.',
					$message
				);
				$this->assertSame(['file', 'line', 'type', 'collidingIds'], array_keys($context));
				$this->assertStringEndsWith('GdprEntityMapper.php', $context['file']);
				$this->assertIsInt($context['line']);
				$this->assertSame('PERSON', $context['type']);
				$this->assertSame([7, 8], $context['collidingIds']);
			}
		);
		$this->results[] = [['id' => 7, 'value' => 'Jan', 'type' => 'PERSON'], ['id' => 8, 'value' => 'Jan', 'type' => 'PERSON']];

		$this->assertSame(7, (new GdprEntityMapper($this->recordingDb(), $logger))->findOneByValueAndType('Jan', 'PERSON')?->getId());
	}//end testTwoRowsWarnByTypeAndIds()

	/**
	 * Without a logger two rows still answer the lowest id.
	 *
	 * @return void
	 */
	public function testTwoRowsWithoutLoggerAnswerTheLowestId(): void {
		$this->results[] = [['id' => 2], ['id' => 3]];

		$this->assertSame(2, (new GdprEntityMapper($this->recordingDb()))->findOneByValueAndType('x', 'PERSON')?->getId());
	}//end testTwoRowsWithoutLoggerAnswerTheLowestId()
}//end class
