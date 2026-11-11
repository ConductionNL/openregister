<?php

/**
 * Golden test pinning the pgvector KNN query and the hybrid search envelope.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization;

use OCA\OpenRegister\Search\Service\Vectorization\Handlers\PgVectorPlatform;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorQuery;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\KeywordFusion;

/**
 * Pins the exact SQL, parameters and types of every filter combination,
 * the result rows, and the hybrid envelope built from them.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler
 */
final class VectorSearchKnnQueryGoldenTest extends TestCase {

	/**
	 * Every executeQuery call.
	 *
	 * @var array<int, array{string, array<string, mixed>, array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A handler on a two-dimension pgvector column returning the rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The KNN rows.
	 *
	 * @return VectorSearchHandler
	 */
	private function handler(array $rows): VectorSearchHandler {
		$pgVector = $this->createMock(PgVectorPlatform::class);
		$pgVector->method('getVectorColumnDimension')->willReturn(2);
		$pgVector->method('formatVector')->willReturn('[1,0]');
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = [], array $types = []) use ($result): IResult {
				$this->calls[] = [$sql, $params, $types];
				return $result;
			}
		);
		return new VectorSearchHandler($db, $pgVector, $this->createMock(LoggerInterface::class));
	}//end handler()

	/**
	 * A KNN row.
	 *
	 * @param int         $id       The vector id.
	 * @param string      $entity   The entity id.
	 * @param float       $distance The cosine distance.
	 * @param string|null $metadata The metadata JSON.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(int $id, string $entity, float $distance, ?string $metadata = null): array {
		return [
			'id' => $id, 'entity_type' => 'object', 'entity_id' => $entity, 'chunk_index' => 0, 'total_chunks' => 1,
			'chunk_text' => 't' . $id, 'metadata' => $metadata, 'embedding_model' => 'm', 'embedding_dimensions' => 2,
			'distance' => $distance,
		];
	}//end row()

	/**
	 * Filters with the WHERE clause, params and types they produce.
	 *
	 * @return array<string, array{array<string, mixed>, string, array<string, mixed>, array<string, mixed>}>
	 */
	public static function filters(): array {
		$arr = IQueryBuilder::PARAM_STR_ARRAY;
		return [
			'none' => [[], '', ['qvec' => '[1,0]'], []],
			'null filters' => [['entity_type' => null, 'entity_id' => null], '', ['qvec' => '[1,0]'], []],
			'type' => [['entity_type' => 'file'], 'WHERE v.entity_type = :entityType ', ['qvec' => '[1,0]', 'entityType' => 'file'], []],
			'types' => [['entity_type' => ['file', 'object']], 'WHERE v.entity_type IN (:entityTypes) ', ['qvec' => '[1,0]', 'entityTypes' => ['file', 'object']], ['entityTypes' => $arr]],
			'id' => [['entity_id' => 'u1'], 'WHERE v.entity_id = :entityId ', ['qvec' => '[1,0]', 'entityId' => 'u1'], []],
			'ids and type' => [
				['entity_id' => ['u1', 'u2'], 'entity_type' => 'file'],
				'WHERE v.entity_type = :entityType AND v.entity_id IN (:entityIds) ',
				['qvec' => '[1,0]', 'entityType' => 'file', 'entityIds' => ['u1', 'u2']],
				['entityIds' => $arr],
			],
			'both arrays' => [
				['entity_type' => [], 'entity_id' => ['u1']],
				'WHERE v.entity_type IN (:entityTypes) AND v.entity_id IN (:entityIds) ',
				['qvec' => '[1,0]', 'entityTypes' => [], 'entityIds' => ['u1']],
				['entityTypes' => $arr, 'entityIds' => $arr],
			],
		];
	}//end filters()

	/**
	 * The exact query of each filter combination.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param string               $where   The WHERE clause.
	 * @param array<string, mixed> $params  The parameters.
	 * @param array<string, mixed> $types   The parameter types.
	 *
	 * @return void
	 *
	 * @dataProvider filters
	 */
	public function testQuery(array $filters, string $where, array $params, array $types): void {
		$this->handler([self::row(1, 'a', 0.25)])->semanticSearch(new VectorQuery(embedding: [1.0, 0.0], limit: 0, filters: $filters));
		$this->assertSame(
			[[
				'SELECT v.id, v.entity_type, v.entity_id, v.chunk_index, v.total_chunks, v.chunk_text, '
				. 'v.metadata, v.embedding_model, v.embedding_dimensions, (a.embedding <=> :qvec::vector) AS distance '
				. 'FROM openregister_vec_ann a JOIN *PREFIX*openregister_vectors v ON v.id = a.vector_id '
				. $where . 'ORDER BY a.embedding <=> :qvec::vector ASC LIMIT 1',
				$params,
				$types,
			]],
			$this->calls
		);
	}//end testQuery()

	/**
	 * The result rows and the hybrid envelope over them.
	 *
	 * @return void
	 */
	public function testRowsAndHybridEnvelope(): void {
		$handler = $this->handler([self::row(1, 'a', 0.25, '{"k":1}'), self::row(2, 'b', 0.5, '')]);
		$this->assertSame(
			[
				['vector_id' => 1, 'entity_type' => 'object', 'entity_id' => 'a', 'similarity' => 0.75, 'chunk_index' => 0, 'total_chunks' => 1, 'chunk_text' => 't1', 'metadata' => ['k' => 1], 'model' => 'm', 'dimensions' => 2],
				['vector_id' => 2, 'entity_type' => 'object', 'entity_id' => 'b', 'similarity' => 0.5, 'chunk_index' => 0, 'total_chunks' => 1, 'chunk_text' => 't2', 'metadata' => [], 'model' => 'm', 'dimensions' => 2],
			],
			$handler->semanticSearch(new VectorQuery(embedding: [1.0, 0.0], limit: 5))
		);

		$keyword = [['entity_type' => 'object', 'entity_id' => 'b', 'score' => 9], ['entity_type' => 'file', 'entity_id' => 'c']];
		$hybrid  = $handler->hybridSearch(new VectorQuery(embedding: [1.0, 0.0], limit: 2), new KeywordFusion(results: $keyword, weights: ['keyword' => 1, 'vector' => 3]));
		unset($hybrid['search_time_ms']);
		$this->assertStringEndsWith('LIMIT 4', $this->calls[1][0]);
		$this->assertSame(
			[
				'results' => [
					['entity_type' => 'object', 'entity_id' => 'b', 'chunk_index' => 0, 'chunk_text' => 't2', 'metadata' => [], 'vector_similarity' => 0.5, 'keyword_score' => 9, 'combined_score' => 0.75 / 62 + 0.25 / 61, 'in_vector' => true, 'in_keyword' => true, 'vector_rank' => 2, 'keyword_rank' => 1],
					['entity_type' => 'object', 'entity_id' => 'a', 'chunk_index' => 0, 'chunk_text' => 't1', 'metadata' => ['k' => 1], 'vector_similarity' => 0.75, 'keyword_score' => null, 'combined_score' => 0.75 / 61, 'in_vector' => true, 'in_keyword' => false, 'vector_rank' => 1, 'keyword_rank' => null],
				],
				'total' => 2,
				'source_breakdown' => ['vector_only' => 1, 'keyword_only' => 0, 'both' => 1],
				'weights' => ['keyword' => 0.25, 'vector' => 0.75],
			],
			$hybrid
		);
	}//end testRowsAndHybridEnvelope()
}//end class
