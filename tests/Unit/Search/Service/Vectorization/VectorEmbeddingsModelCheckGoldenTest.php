<?php

/**
 * Golden test pinning the embedding-model mismatch check and the clear-all report.
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

use Exception;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every report shape and the queries run for it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings
 */
final class VectorEmbeddingsModelCheckGoldenTest extends TestCase {

	/**
	 * The query-builder calls, in order, as "method:args".
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * The service over a database answering the scripted results in order.
	 *
	 * @param array<string, mixed>  $settings The LLM settings.
	 * @param array<int, mixed>     $answers  Per query: an int (fetchOne), a list of models (fetch), or an Exception.
	 *
	 * @return VectorEmbeddings
	 */
	private function service(array $settings, array $answers): VectorEmbeddings {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$answers): IQueryBuilder {
				return $this->builder($answers);
			}
		);
		$settingsService = $this->createMock(SettingsServiceInterface::class);
		$settingsService->method('getLLMSettingsOnly')->willReturn($settings);

		return new VectorEmbeddings(
			$db,
			$settingsService,
			new VectorEmbeddingHandlers(
				$this->createMock(EmbeddingGeneratorHandler::class),
				$this->createMock(VectorStorageHandlerInterface::class),
				$this->createMock(VectorSearchHandler::class),
				$this->createMock(VectorStatsHandler::class)
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A recording query builder answering the next scripted result.
	 *
	 * @param array<int, mixed> $answers The scripted results, consumed in order.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(array &$answers): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectDistinct', 'from', 'where', 'delete'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $method): IQueryBuilder {
					$this->log[] = $method . ':' . implode(',', array_map(static fn ($a): string => is_string($a) ? $a : 'fn', array_filter($args, static fn ($a): bool => $a !== null)));
					return $qb;
				}
			);
		}

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(
			function (string $count, string $alias): IQueryFunction {
				$this->log[] = 'count:' . $count . ',' . $alias;
				return $this->createMock(IQueryFunction::class);
			}
		);
		$qb->method('func')->willReturn($func);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('isNull')->willReturnCallback(static fn (string $c): string => $c . ' IS NULL');
		$expr->method('isNotNull')->willReturnCallback(static fn (string $c): string => $c . ' IS NOT NULL');
		$qb->method('expr')->willReturn($expr);

		$next = static function () use (&$answers) {
			$answer = array_shift($answers);
			if ($answer instanceof Exception) {
				throw $answer;
			}

			return $answer;
		};
		$qb->method('executeStatement')->willReturnCallback($next);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($next): IResult {
				$answer = $next();
				$result = $this->createMock(IResult::class);
				if (is_array($answer) === true) {
					$rows = array_map(static fn (string $m): array => ['embedding_model' => $m], $answer);
					$rows[] = false;
					$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
				} else {
					$result->method('fetchOne')->willReturn($answer);
					$result->method('fetch')->willReturn(false);
				}

				return $result;
			}
		);
		return $qb;
	}//end builder()

	/**
	 * Each provider's model setting, and the report when none is set.
	 *
	 * @return void
	 */
	public function testConfiguredModel(): void {
		$none = ['has_vectors' => false, 'mismatch' => false, 'message' => 'No embedding model configured'];
		$this->assertSame($none, $this->service([], [])->checkEmbeddingModelMismatch());
		$this->assertSame($none, $this->service(['embeddingProvider' => 'openai', 'openaiConfig' => ['model' => '']], [])->checkEmbeddingModelMismatch());
		$this->assertSame($none, $this->service(['embeddingProvider' => 'other', 'otherConfig' => ['model' => 'x']], [])->checkEmbeddingModelMismatch());
		$this->assertSame($none, $this->service(['embeddingProvider' => 'fireworks', 'fireworksConfig' => ['model' => 'x']], [])->checkEmbeddingModelMismatch());
		$this->assertSame([], $this->log);

		$empty = ['has_vectors' => false, 'mismatch' => false, 'current_model' => 'm', 'message' => 'No vectors exist yet'];
		$this->assertSame($empty, $this->service(['embeddingProvider' => 'openai', 'openaiConfig' => ['model' => 'm']], ['0'])->checkEmbeddingModelMismatch());
		$this->assertSame($empty, $this->service(['embeddingProvider' => 'ollama', 'ollamaConfig' => ['model' => 'm']], [0])->checkEmbeddingModelMismatch());
		$this->assertSame($empty, $this->service(['embeddingProvider' => 'fireworks', 'fireworksConfig' => ['embeddingModel' => 'm']], [0])->checkEmbeddingModelMismatch());
		$this->assertSame(['count:*,total', 'select:fn', 'from:openregister_vectors'], array_slice($this->log, 0, 3));
	}//end testConfiguredModel()

	/**
	 * The reports over existing vectors.
	 *
	 * @return void
	 */
	public function testReports(): void {
		$settings = ['embeddingProvider' => 'openai', 'openaiConfig' => ['model' => 'm']];
		$this->assertSame(
			['has_vectors' => true, 'mismatch' => false, 'current_model' => 'm', 'existing_models' => ['m'], 'total_vectors' => 4, 'null_model_count' => 0, 'mismatched_models' => [], 'message' => 'All vectors use the same embedding model.'],
			$this->service($settings, ['4', ['m'], '0'])->checkEmbeddingModelMismatch()
		);
		$this->assertSame(
			[
				'count:*,total', 'select:fn', 'from:openregister_vectors',
				'selectDistinct:embedding_model', 'from:openregister_vectors', 'where:embedding_model IS NOT NULL',
				'count:*,null_count', 'select:fn', 'from:openregister_vectors', 'where:embedding_model IS NULL',
			],
			$this->log
		);
		$this->assertSame(
			['has_vectors' => true, 'mismatch' => true, 'current_model' => 'm', 'existing_models' => ['m'], 'total_vectors' => 4, 'null_model_count' => 2, 'mismatched_models' => [], 'message' => '2 vectors have no model information.'],
			$this->service($settings, [4, ['m'], 2])->checkEmbeddingModelMismatch()
		);
		$this->assertSame(
			['has_vectors' => true, 'mismatch' => true, 'current_model' => 'm', 'existing_models' => ['a', 'm', 'b'], 'total_vectors' => 9, 'null_model_count' => 1, 'mismatched_models' => ['a', 'b'], 'message' => 'Multiple embedding models detected. Consider re-embedding all vectors with a single model.'],
			$this->service($settings, [9, ['a', 'm', 'b'], 1])->checkEmbeddingModelMismatch()
		);
		$this->assertSame(
			['has_vectors' => false, 'mismatch' => false, 'error' => 'db down'],
			$this->service($settings, [new Exception('db down')])->checkEmbeddingModelMismatch()
		);
	}//end testReports()

	/**
	 * Clearing: nothing to delete, deleted, and failed.
	 *
	 * @return void
	 */
	public function testClearAll(): void {
		$this->assertSame(['success' => true, 'deleted' => 0, 'message' => 'No vectors to delete'], $this->service([], ['0'])->clearAllEmbeddings());
		$this->assertSame(['count:*,total', 'select:fn', 'from:openregister_vectors'], $this->log);
		$this->assertSame(['success' => true, 'deleted' => 3, 'message' => 'Deleted 3 vectors successfully'], $this->service([], [5, 3])->clearAllEmbeddings());
		$this->assertSame('delete:openregister_vectors', $this->log[6]);
		$this->assertSame(
			['success' => false, 'error' => 'gone', 'message' => 'Failed to clear embeddings: gone'],
			$this->service([], [5, new Exception('gone')])->clearAllEmbeddings()
		);
	}//end testClearAll()
}//end class
