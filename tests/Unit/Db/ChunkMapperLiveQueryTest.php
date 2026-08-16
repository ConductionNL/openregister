<?php

declare(strict_types=1);

/**
 * ChunkMapper LIVE query tests — real mapper, real IDBConnection, real SQL.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Every other test that touches the content-search path stubs the database
 * away, so the SQL these methods build is never executed:
 *
 *  - tests/Unit/Service/Object/ContentSearchHandlerTest.php and
 *    tests/Unit/Service/Object/QueryHandlerContentSearchTest.php mock
 *    ChunkMapper itself (`createMock(ChunkMapper::class)`), so
 *    `searchByKeyword()` never runs at all.
 *  - tests/Unit/Db/ChunkMapperKeywordSearchTest.php mocks IDBConnection and
 *    IQueryBuilder, so the ranked arm is only asserted as a SUBSTRING of a SQL
 *    string that is never sent to a database, and the unranked `LIKE` arm is
 *    asserted through an IQueryBuilder mock that accepts any column name.
 *
 * The consequence is a suite that stays green through a real SQL failure:
 * `searchByKeyword()` catches every exception and degrades to `[]` with a
 * logged warning, so a renamed/typo'd column produces "no content-search hits"
 * — indistinguishable from "no documents matched" — while every mock-based
 * assertion still passes.
 *
 * These tests close that hole: they drive the REAL ChunkMapper against the
 * REAL Nextcloud IDBConnection (the same container-resolved connection the app
 * uses at runtime), insert probe chunks, and assert on the rows the database
 * actually returns. A broken query turns them red.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Live (real-database) tests for ChunkMapper's content-search queries.
 *
 * @group DB
 */
class ChunkMapperLiveQueryTest extends TestCase {

	/**
	 * Dedicated source_type for this test's probe rows. Scoping every query and
	 * every cleanup on it keeps the test independent of whatever else lives in
	 * the chunk table on the instance the suite runs against.
	 *
	 * @var string
	 */
	private const PROBE_SOURCE_TYPE = 'phpunit-chunkprobe';

	/**
	 * Second source_type, used to prove the source_type filter really reaches SQL.
	 *
	 * @var string
	 */
	private const PROBE_OTHER_SOURCE_TYPE = 'phpunit-chunkprobe-other';

	/**
	 * A single lowercase token that is not a stop word in any text-search
	 * configuration and does not occur anywhere else in the corpus. Matched
	 * identically by the PostgreSQL `plainto_tsquery` arm and by the unranked
	 * `LIKE` arm.
	 *
	 * @var string
	 */
	private const PROBE_TERM = 'kwartaalrapportagexyz';

	/**
	 * Real Nextcloud database connection.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * The real mapper under test, wired to the real connection.
	 *
	 * @var ChunkMapper
	 */
	private ChunkMapper $mapper;

	/**
	 * Resolve the real connection and seed the probe rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$connection = null;
		if (class_exists(\OC::class) === true && isset(\OC::$server) === true) {
			// The bare-CLI harness installs an OC_FakeServer whose get() returns
			// null for anything unregistered, so the instanceof check below — not
			// the mere existence of \OC — is what distinguishes a real bootstrap.
			$connection = \OC::$server->get(IDBConnection::class);
		}

		if (($connection instanceof IDBConnection) === false) {
			$this->markTestSkipped(
				'ChunkMapperLiveQueryTest needs a bootstrapped Nextcloud (real IDBConnection). '
				. 'Run it inside the container, e.g. '
				. 'OPENREGISTER_TEST_NC_ROOT=/var/www/html php vendor/bin/phpunit tests/Unit/Db/ChunkMapperLiveQueryTest.php'
			);
		}

		$this->db = $connection;

		// Positive control: a mock IDBConnection leaked into the container by an
		// earlier test is still `instanceof IDBConnection`, and would make every
		// query below silently return nothing — exactly the vacuous-green failure
		// mode this file exists to close. Prove the connection really talks to a
		// database BEFORE asserting anything about query results.
		$probe = $this->db->executeQuery('SELECT 1');
		$this->assertInstanceOf(
			IResult::class,
			$probe,
			'The IDBConnection resolved from the container did not return a result set; '
			. 'it is not backed by a real database and every live assertion below would be vacuous.'
		);
		$one = $probe->fetchOne();
		$probe->closeCursor();
		$this->assertSame(
			1,
			(int)$one,
			'The IDBConnection resolved from the container is not backed by a real database; '
			. 'every live assertion below would be vacuous.'
		);

		$this->mapper = new ChunkMapper($this->db, new NullLogger());

		$this->deleteProbeChunks();
		$this->seedProbeChunks();
	}//end setUp()

	/**
	 * Remove every probe row this test inserted.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (isset($this->db) === true) {
			$this->deleteProbeChunks();
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Delete all rows carrying either probe source_type.
	 *
	 * @return void
	 */
	private function deleteProbeChunks(): void {
		foreach ([self::PROBE_SOURCE_TYPE, self::PROBE_OTHER_SOURCE_TYPE] as $sourceType) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('openregister_chunks')
				->where(
					$qb->expr()->eq(
						'source_type',
						$qb->createNamedParameter($sourceType, IQueryBuilder::PARAM_STR)
					)
				);
			$qb->executeStatement();
		}
	}//end deleteProbeChunks()

	/**
	 * Insert the three probe chunks used by every test in this class:
	 *  - source_id 909001, chunk_index 7  — contains PROBE_TERM
	 *  - source_id 909002, chunk_index 0  — does NOT contain PROBE_TERM
	 *  - source_id 909003, chunk_index 3  — contains PROBE_TERM, other source_type
	 *
	 * @return void
	 */
	private function seedProbeChunks(): void {
		$this->insertChunk(
			sourceType: self::PROBE_SOURCE_TYPE,
			sourceId: 909001,
			chunkIndex: 7,
			textContent: 'Deze ' . self::PROBE_TERM . ' beschrijft de gemeentelijke uitgaven.'
		);

		$this->insertChunk(
			sourceType: self::PROBE_SOURCE_TYPE,
			sourceId: 909002,
			chunkIndex: 0,
			textContent: 'Een notitie zonder het gezochte woord erin.'
		);

		$this->insertChunk(
			sourceType: self::PROBE_OTHER_SOURCE_TYPE,
			sourceId: 909003,
			chunkIndex: 3,
			textContent: 'Bijlage bij de ' . self::PROBE_TERM . ' van vorig jaar.'
		);
	}//end seedProbeChunks()

	/**
	 * Insert one chunk through the real mapper.
	 *
	 * @param string $sourceType The source type.
	 * @param int $sourceId The source id.
	 * @param int $chunkIndex The chunk index.
	 * @param string $textContent The chunk body text.
	 *
	 * @return Chunk The inserted chunk.
	 */
	private function insertChunk(string $sourceType, int $sourceId, int $chunkIndex, string $textContent): Chunk {
		$chunk = new Chunk();
		$chunk->setUuid(sprintf('%s-%d-%d', self::PROBE_SOURCE_TYPE, $sourceId, $chunkIndex));
		$chunk->setSourceType($sourceType);
		$chunk->setSourceId($sourceId);
		$chunk->setChunkIndex($chunkIndex);
		$chunk->setTextContent($textContent);
		$chunk->setStartOffset(0);
		$chunk->setEndOffset(strlen($textContent));
		$chunk->setIndexed(false);
		$chunk->setVectorized(false);
		$chunk->setCreatedAt(new DateTime());
		$chunk->setUpdatedAt(new DateTime());

		return $this->mapper->insert($chunk);
	}//end insertChunk()

	/**
	 * Report which arm of searchByKeyword() the live platform selects, so the
	 * per-path expectations below are explicit rather than assumed.
	 *
	 * @return bool True when the ranked PostgreSQL ts_rank arm runs.
	 */
	private function isPostgres(): bool {
		return str_contains(get_class($this->db->getDatabasePlatform()), 'PostgreSQL');
	}//end isPostgres()

	// =========================================================================
	// searchByKeyword — executed against the real database
	// =========================================================================

	/**
	 * The SQL searchByKeyword() builds must actually run and return the matching
	 * chunk with every projected column populated.
	 *
	 * This is the assertion the mock-only suite cannot make: renaming any column
	 * in the SELECT list (`source_type`, `source_id`, `text_content`,
	 * `chunk_index`) or breaking the predicate makes the live query throw, which
	 * the mapper swallows into `[]` — red here, still green everywhere else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
	 */
	public function testSearchByKeywordRunsRealSqlAndReturnsTheMatchingChunk(): void {
		$results = $this->mapper->searchByKeyword(
			query: self::PROBE_TERM,
			limit: 25,
			filters: ['source_type' => self::PROBE_SOURCE_TYPE],
			allowUnrankedFallback: true
		);

		$this->assertCount(
			1,
			$results,
			'The live keyword query returned ' . count($results) . ' row(s) instead of the single seeded match. '
			. 'An empty result here means the query failed and was swallowed into [] by searchByKeyword().'
		);

		$hit = $results[0];
		$this->assertSame(self::PROBE_SOURCE_TYPE, $hit['entity_type']);
		$this->assertSame('909001', $hit['entity_id']);
		$this->assertSame(7, $hit['chunk_index']);
		$this->assertStringContainsString(self::PROBE_TERM, (string)$hit['chunk_text']);
		$this->assertSame([], $hit['metadata']);

		if ($this->isPostgres() === true) {
			// ts_rank over a real match is strictly positive; a 0.0 score would
			// mean the ranking expression is not the one that matched.
			$this->assertGreaterThan(
				0.0,
				$hit['score'],
				'PostgreSQL ranked arm returned a zero ts_rank for a real match.'
			);
		}
	}//end testSearchByKeywordRunsRealSqlAndReturnsTheMatchingChunk()

	/**
	 * A chunk that does not contain the term must not come back — proving the
	 * predicate is really evaluated by the database rather than the query simply
	 * returning every row.
	 *
	 * @return void
	 */
	public function testSearchByKeywordDoesNotReturnNonMatchingChunks(): void {
		$results = $this->mapper->searchByKeyword(
			query: self::PROBE_TERM,
			limit: 25,
			filters: ['source_type' => self::PROBE_SOURCE_TYPE],
			allowUnrankedFallback: true
		);

		$ids = array_column($results, 'entity_id');
		$this->assertContains('909001', $ids);
		$this->assertNotContains(
			'909002',
			$ids,
			'The non-matching chunk was returned; the keyword predicate is not being evaluated.'
		);
	}//end testSearchByKeywordDoesNotReturnNonMatchingChunks()

	/**
	 * The source_type filter must reach SQL: unfiltered, both matching chunks
	 * come back; filtered, only the in-scope one does.
	 *
	 * @return void
	 */
	public function testSearchByKeywordSourceTypeFilterIsAppliedBySql(): void {
		$unfiltered = $this->mapper->searchByKeyword(
			query: self::PROBE_TERM,
			limit: 25,
			filters: [],
			allowUnrankedFallback: true
		);
		$unfilteredIds = array_column($unfiltered, 'entity_id');
		$this->assertContains('909001', $unfilteredIds);
		$this->assertContains('909003', $unfilteredIds);

		$filtered = $this->mapper->searchByKeyword(
			query: self::PROBE_TERM,
			limit: 25,
			filters: ['source_type' => self::PROBE_OTHER_SOURCE_TYPE],
			allowUnrankedFallback: true
		);
		$filteredIds = array_column($filtered, 'entity_id');
		$this->assertSame(['909003'], $filteredIds);
	}//end testSearchByKeywordSourceTypeFilterIsAppliedBySql()

	/**
	 * The unranked `LIKE` arm is the one ContentSearchHandler actually uses on
	 * MariaDB/MySQL deployments, and it is the arm whose column names no mock
	 * asserts. Reach it directly so its SQL is executed on whatever platform the
	 * suite runs against, not only on a MariaDB instance.
	 *
	 * Reflection is deliberate: the method is private and, by design, only
	 * selected on non-PostgreSQL platforms, so there is no public route to its
	 * SQL from a PostgreSQL-backed test run. Executing it here is the point —
	 * the alternative is leaving it mock-only, which is the defect this file
	 * closes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
	 */
	public function testUnrankedLikeFallbackRunsRealSqlAndReturnsTheMatchingChunk(): void {
		// No setAccessible() call: it has been a no-op since PHP 8.1 and is
		// deprecated from 8.5 (the runtime the container image ships).
		$method = new ReflectionMethod(ChunkMapper::class, 'searchByKeywordUnranked');

		$results = $method->invoke(
			$this->mapper,
			self::PROBE_TERM,
			25,
			['source_type' => self::PROBE_SOURCE_TYPE]
		);

		$this->assertCount(
			1,
			$results,
			'The live unranked LIKE query returned ' . count($results) . ' row(s) instead of the single seeded match.'
		);

		$hit = $results[0];
		$this->assertSame(self::PROBE_SOURCE_TYPE, $hit['entity_type']);
		$this->assertSame('909001', $hit['entity_id']);
		$this->assertSame(7, $hit['chunk_index']);
		$this->assertSame(0.0, $hit['score']);
		$this->assertStringContainsString(self::PROBE_TERM, (string)$hit['chunk_text']);
	}//end testUnrankedLikeFallbackRunsRealSqlAndReturnsTheMatchingChunk()

	/**
	 * findBySource() builds its query through the real QueryBuilder; run it so a
	 * renamed `source_type` / `source_id` / `chunk_index` column fails loudly.
	 *
	 * @return void
	 */
	public function testFindBySourceRunsRealQueryBuilderSql(): void {
		$this->insertChunk(
			sourceType: self::PROBE_SOURCE_TYPE,
			sourceId: 909001,
			chunkIndex: 1,
			textContent: 'Tweede fragment van hetzelfde bestand.'
		);

		$chunks = $this->mapper->findBySource(self::PROBE_SOURCE_TYPE, 909001);

		$this->assertCount(2, $chunks);
		// orderBy('chunk_index', 'ASC') — index 1 before index 7.
		$this->assertSame(1, $chunks[0]->getChunkIndex());
		$this->assertSame(7, $chunks[1]->getChunkIndex());
		$this->assertSame(self::PROBE_SOURCE_TYPE, $chunks[0]->getSourceType());
		$this->assertSame(909001, $chunks[0]->getSourceId());
	}//end testFindBySourceRunsRealQueryBuilderSql()
}//end class
