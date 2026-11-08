<?php

/**
 * A search trail filter of IS NULL or IS NOT NULL is a null check, and only that.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversNothing
 */
final class SearchTrailNullFilterTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The two markers and the predicate each stands for.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function markers(): array {
		return [
			'is not null' => ['IS NOT NULL', '#1 andWhere(isNotNull(search_term))'],
			'is null' => ['IS NULL', '#1 andWhere(isNull(search_term))'],
		];
	}//end markers()

	/**
	 * The marker adds its null check and no equality on the marker text.
	 *
	 * Before the fix the marker fell through to `search_term = 'IS NOT NULL'`,
	 * so the filter matched no row at all.
	 *
	 * @param string $marker    The filter value.
	 * @param string $predicate The predicate it must add.
	 *
	 * @return void
	 */
	#[DataProvider('markers')]
	public function testANullMarkerIsOnlyANullCheck(string $marker, string $predicate): void {
		$this->queuedRows = [[['count' => '2']]];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$mapper = new SearchTrailMapper($db, $this->createMock(IRequest::class), $this->createMock(IUserSession::class), new NullLogger());

		$mapper->count(['search_term' => $marker]);

		$where = array_values(array_filter($this->queryLog, fn (string $line): bool => str_contains($line, 'andWhere(')));
		$this->assertSame([$predicate], $where);
	}//end testANullMarkerIsOnlyANullCheck()
}//end class
