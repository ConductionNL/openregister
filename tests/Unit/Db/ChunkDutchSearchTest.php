<?php

/**
 * Ranked chunk keyword search also stems with the configured text search configuration.
 *
 * The live case runs against a PostgreSQL database named by the environment
 * variable OPENREGISTER_TEST_PGSQL_DSN (a PDO DSN, e.g.
 * `pgsql:host=127.0.0.1;dbname=test;user=test;password=test`) and is skipped
 * without one; the SQL cases run everywhere.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Files\Db\ChunkMapper
 * @uses \OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch
 * @uses \OCA\OpenRegister\Service\Search\TextSearchConfiguration
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
final class ChunkDutchSearchTest extends TestCase {

	/**
	 * The SQL each executeQuery() call received.
	 *
	 * @var list<string>
	 */
	private array $sql = [];

	/**
	 * A connection on the PostgreSQL platform whose queries run on $pdo, or only get recorded.
	 *
	 * @param PDO|null $pdo The live database, or null to record only.
	 *
	 * @return IDBConnection
	 */
	private function connection(?PDO $pdo): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$db->method('executeQuery')->willReturnCallback(function (string $sql, array $params = []) use ($pdo): IResult {
			$this->sql[] = $sql;
			$rows = [];
			if ($pdo !== null) {
				$statement = $pdo->prepare(str_replace('*PREFIX*', 'oc_', $sql));
				$statement->execute($params);
				$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
			}

			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn($rows);
			return $result;
		});

		return $db;
	}

	private function mapper(IDBConnection $db, ?string $stored): ChunkMapper {
		$textSearch = null;
		if ($stored !== null) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn($stored);
			$textSearch = new TextSearchConfiguration(db: $db, appConfig: $appConfig);
		}

		return new ChunkMapper($db, new NullLogger(), $textSearch);
	}

	public function testAnInflectedFormFindsChunkText(): void {
		$dsn = getenv('OPENREGISTER_TEST_PGSQL_DSN');
		if (is_string($dsn) === false || $dsn === '') {
			$this->markTestSkipped('OPENREGISTER_TEST_PGSQL_DSN names no PostgreSQL database.');
		}

		$pdo = new PDO($dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TEMPORARY TABLE oc_openregister_chunks (id serial, source_type text, source_id text, text_content text, chunk_index int)');
		$pdo->exec("INSERT INTO oc_openregister_chunks (source_type, source_id, text_content, chunk_index) VALUES ('file', '41', 'Het college neemt een besluit over de aanvraag.', 0), ('file', '42', 'Een ander onderwerp.', 0)");

		$hits = $this->mapper(db: $this->connection(pdo: $pdo), stored: 'dutch')
			->searchByKeyword(search: new KeywordChunkSearch(query: 'besluiten', limit: 5));

		$this->assertSame(['41'], array_column($hits, 'entity_id'));
		$this->assertGreaterThan(0.0, $hits[0]['score']);
	}

	public function testTheStemmedArmIsOredWithTheSimpleOneAndRankedByTheBetterOfBoth(): void {
		$this->mapper(db: $this->connection(pdo: null), stored: 'dutch')
			->searchByKeyword(search: new KeywordChunkSearch(query: 'besluiten', limit: 5));

		$this->assertCount(1, $this->sql);
		$this->assertStringContainsString(
			"WHERE (to_tsvector('simple', text_content) @@ plainto_tsquery('simple', :query) OR to_tsvector('dutch', text_content) @@ plainto_tsquery('dutch', :query))",
			$this->sql[0]
		);
		$this->assertStringContainsString(
			"GREATEST(ts_rank(to_tsvector('simple', text_content), plainto_tsquery('simple', :query)), ts_rank(to_tsvector('dutch', text_content), plainto_tsquery('dutch', :query))) AS score",
			$this->sql[0]
		);
	}

	public function testWithoutAConfigurationTheQueryIsAsBefore(): void {
		$this->mapper(db: $this->connection(pdo: null), stored: null)
			->searchByKeyword(search: new KeywordChunkSearch(query: 'besluiten', limit: 5));

		$this->assertSame(
			"SELECT id, source_type, source_id, text_content, chunk_index, ts_rank(to_tsvector('simple', text_content), plainto_tsquery('simple', :query)) AS score "
			. "FROM *PREFIX*openregister_chunks WHERE to_tsvector('simple', text_content) @@ plainto_tsquery('simple', :query) ORDER BY score DESC LIMIT 5",
			$this->sql[0]
		);
	}

	public function testTheSimpleConfigurationAddsNoSecondArm(): void {
		$this->mapper(db: $this->connection(pdo: null), stored: 'simple')
			->searchByKeyword(search: new KeywordChunkSearch(query: 'besluiten', limit: 5));

		$this->assertStringNotContainsString(' OR ', $this->sql[0]);
	}
}
