<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers;

use OCA\OpenRegister\Service\Vectorization\Handlers\PgVectorPlatform;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorSearchHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for VectorSearchHandler::semanticSearch() on the PHP fallback path.
 *
 * Each case feeds a fixed set of stored vector rows (stream and string blobs,
 * broken blobs, metadata variants, dimension mismatches) and a limit; the
 * recording holds the ranked results, or the thrown error, plus the log
 * levels and messages written. Compared with
 * fixtures/vector-semantic-search-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Vectorization\Handlers\VectorSearchHandler
 */
class VectorSearchHandlerSemanticSearchGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/vector-semantic-search-golden.json';

	/**
	 * @return array<string, array{0: array<int, string>, 1: int}>
	 */
	public static function cases(): array {
		return [
			'no rows' => [[], 10],
			'one row' => [['near'], 10],
			'ranked' => [['far', 'near', 'mid', 'opposite'], 10],
			'limit cuts' => [['far', 'near', 'mid', 'opposite'], 2],
			'limit zero' => [['near', 'mid'], 0],
			'stream blob' => [['stream', 'far'], 10],
			'non string blob skipped' => [['int blob', 'near'], 10],
			'broken serialised skipped' => [['garbage', 'near'], 10],
			'serialised non array skipped' => [['scalar blob', 'mid'], 10],
			'object blob not instantiated' => [['object blob', 'near'], 10],
			'class blob never woken' => [['class blob', 'near'], 10],
			'dimension mismatch logged' => [['wide', 'near'], 10],
			'zero vector' => [['zero', 'near'], 10],
			'metadata json' => [['meta', 'near'], 10],
			'metadata invalid json' => [['bad meta', 'near'], 10],
			'metadata empty string' => [['empty meta'], 10],
			'equal similarity keeps order' => [['near', 'near copy', 'mid'], 10],
			'only skipped rows' => [['garbage', 'int blob'], 10],
			'db failure' => [['THROW'], 10],
		];
	}//end cases()

	/**
	 * @param array<int, string> $rowNames
	 */
	#[DataProvider('cases')]
	public function testSemanticSearchMatchesGolden(array $rowNames, int $limit): void {
		$logs   = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string|\Stringable $message, array $context=[]) use (&$logs, $level): void {
					unset($context['file'], $context['line'], $context['search_time_ms']);
					$logs[] = [$level, (string)$message, $context];
				}
			);
		}

		$pgVector = $this->createMock(PgVectorPlatform::class);
		$pgVector->method('getVectorColumnDimension')->willReturn(null);

		$db = $this->createMock(IDBConnection::class);
		if ($rowNames === ['THROW']) {
			$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('db down'));
		} else {
			$db->method('getQueryBuilder')->willReturn($this->queryBuilder(rows: array_map([self::class, 'row'], $rowNames)));
		}

		$handler  = new VectorSearchHandler($db, $pgVector, $logger);
		$warnings = [];
		set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
			$warnings[] = $message;
			return true;
		});
		try {
			$actual = ['results' => $handler->semanticSearch([1.0, 0.0], $limit, ['entity_type' => 'object'])];
		} catch (\Throwable $e) {
			$actual = ['threw' => get_class($e), 'message' => $e->getMessage()];
		} finally {
			restore_error_handler();
		}

		$actual['logs']     = $logs;
		$actual['warnings'] = $warnings;
		$actual = json_decode(json_encode($actual, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true);
		$name   = (string)$this->dataName();

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testSemanticSearchMatchesGolden()

	/**
	 * @return array<string, mixed>
	 */
	private static function row(string $name): array {
		$blobs = [
			'near' => serialize([0.9, 0.1]),
			'near copy' => serialize([0.9, 0.1]),
			'mid' => serialize([0.5, 0.5]),
			'far' => serialize([0.1, 0.9]),
			'opposite' => serialize([-1.0, 0.0]),
			'zero' => serialize([0.0, 0.0]),
			'wide' => serialize([1.0, 0.0, 0.0]),
			'garbage' => 'not-serialised',
			'scalar blob' => serialize(42),
			'object blob' => serialize(new \ArrayObject([1.0, 0.0])),
			'class blob' => 'O:8:"DateTime":1:{s:4:"date";s:3:"bad";}',
			'int blob' => 12345,
			'meta' => serialize([0.7, 0.7]),
			'bad meta' => serialize([0.2, 0.8]),
			'empty meta' => serialize([1.0, 1.0]),
		];
		$metadata = ['meta' => '{"title":"T","n":2}', 'bad meta' => '{broken', 'empty meta' => ''];

		$embedding = $blobs[$name] ?? serialize([1.0, 0.0]);
		if ($name === 'stream') {
			$embedding = fopen('php://memory', 'r+');
			fwrite($embedding, serialize([0.8, 0.2]));
			rewind($embedding);
		}

		return [
			'id' => crc32($name),
			'entity_type' => 'object',
			'entity_id' => 'e-' . $name,
			'embedding' => $embedding,
			'metadata' => $metadata[$name] ?? null,
			'chunk_index' => 0,
			'total_chunks' => 1,
			'chunk_text' => 'text ' . $name,
			'embedding_model' => 'golden',
			'embedding_dimensions' => 2,
		];
	}//end row()

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function queryBuilder(array $rows): IQueryBuilder {
		$stmt = $this->createMock(IResult::class);
		$stmt->method('fetchAll')->willReturn($rows);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1=1');
		$expr->method('in')->willReturn('1=1');

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'andWhere', 'setMaxResults', 'orderBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('expr')->willReturn($expr);
		$qb->method('executeQuery')->willReturn($stmt);

		return $qb;
	}//end queryBuilder()
}//end class
