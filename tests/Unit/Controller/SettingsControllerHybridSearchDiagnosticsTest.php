<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Exception;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The hybrid-search block of the database info on PostgreSQL: the ANN sidecar,
 * its indexes, the vector counts and the chunk progress, and the plain row
 * count when the sidecar join fails.
 *
 * @covers \OCA\OpenRegister\Controller\SettingsController
 */
class SettingsControllerHybridSearchDiagnosticsTest extends TestCase {

	public function testPostgresReportsTheSidecarIndexesAndCounts(): void {
		$diagnostics = $this->diagnostics(sidecarJoinFails: false);

		$this->assertTrue($diagnostics['annSidecarTable']);
		$this->assertSame(1536, $diagnostics['embeddingVectorDimension']);
		$this->assertTrue($diagnostics['hnswIndex']);
		$this->assertTrue($diagnostics['textSearchGinIndex']);
		$this->assertSame(['total' => 10, 'pgvectorPopulated' => 7], $diagnostics['vectors']);
		$this->assertSame(['total' => 20, 'vectorized' => 15], $diagnostics['chunks']);
	}

	public function testPostgresFallsBackToThePlainVectorCount(): void {
		$diagnostics = $this->diagnostics(sidecarJoinFails: true);

		$this->assertSame(['total' => 4, 'pgvectorPopulated' => 0], $diagnostics['vectors']);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function diagnostics(bool $sidecarJoinFails): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(true);

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('postgresql');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('prepare')->willReturnCallback(function () {
			$statement = $this->createMock(IPreparedStatement::class);
			$statement->method('execute')->willThrowException(new Exception('not here'));
			return $statement;
		});
		$db->method('executeQuery')->willReturnCallback(function (string $sql) use ($sidecarJoinFails) {
			$result = $this->createMock(IResult::class);
			if (str_contains($sql, 'atttypmod') === true) {
				$result->method('fetchOne')->willReturn('1536');
			} elseif (str_contains($sql, 'pg_indexes') === true) {
				$rows = [['indexname' => 'idx_or_vec_ann_hnsw'], ['indexname' => 'idx_or_chunks_text_search_gin']];
				$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
					return array_shift($rows) ?? false;
				});
			} elseif (str_contains($sql, 'LEFT JOIN') === true) {
				if ($sidecarJoinFails === true) {
					throw new Exception('no sidecar');
				}

				$result->method('fetch')->willReturn(['total' => '10', 'populated' => '7']);
			} else {
				$result->method('fetchOne')->willReturn('4');
			}

			return $result;
		});

		$chunkMapper = $this->createMock(ChunkMapper::class);
		$chunkMapper->method('countAll')->willReturn(20);
		$chunkMapper->method('countVectorized')->willReturn(15);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ChunkMapper::class)->willReturn($chunkMapper);

		$controller = new SettingsController(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$db,
			$container,
			$this->createMock(IAppManager::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class)
		);

		return $controller->getDatabaseInfo()->getData()['database']['hybridSearch'];
	}
}
