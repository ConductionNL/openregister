<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ChunkVectorizationJob;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorStorageHandler;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Tests\Unit\Support\RegistersContainerServices;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden test for ChunkVectorizationJob::run().
 *
 * Each case runs the job once; the recording holds every call to the chunk
 * mapper, the embedding coordinator, the storage handler, the app config and
 * the logger, plus the vectorized flag of every chunk afterwards. Volatile
 * values (log file and line, the run timestamp, the execution time) are masked.
 * Compared with fixtures/chunk-vectorization-job-golden.json; set
 * UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\ChunkVectorizationJob
 * @covers \OCA\OpenRegister\BackgroundJob\ChunkVectorizationBatch
 */
class ChunkVectorizationJobGoldenTest extends TestCase
{
    use RegistersContainerServices;

    private const FIXTURE = __DIR__.'/fixtures/chunk-vectorization-job-golden.json';

    /**
     * @var list<array<string, mixed>>
     */
    private array $trace = [];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $ok = static fn (float $v): array => ['embedding' => [$v, $v + 0.1], 'model' => 'm1', 'dimensions' => 2];
        $noBackfill = ['converted' => 0, 'failed' => 0, 'last_id' => 0, 'remaining' => 0];

        return [
            'two sources, total cached per source' => [[
                'chunks'   => [[1, 'file', 42, 'alpha', 0], [2, 'file', 42, 'beta', 1], [3, 'object', 7, 'gamma', 0]],
                'sources'  => ['file_42' => 3, 'object_7' => 1],
                'results'  => [$ok(0.1), $ok(0.2), $ok(0.3)],
                'cursor'   => '0',
                'backfill' => $noBackfill,
            ]],
            'null entry, null embedding, missing index' => [[
                'chunks'   => [[1, 'file', 42, 'a', 0], [2, 'file', 42, 'b', 1], [3, 'file', 42, 'c', 2], [4, 'file', 42, 'd', 3]],
                'sources'  => ['file_42' => 4],
                'results'  => [null, ['embedding' => null, 'error' => 'timeout'], $ok(0.5)],
                'cursor'   => '0',
                'backfill' => $noBackfill,
            ]],
            'embedding key absent, no error text' => [[
                'chunks'   => [[1, 'file', 42, 'a', 0]],
                'sources'  => ['file_42' => 1],
                'results'  => [['model' => 'm1']],
                'cursor'   => '0',
                'backfill' => $noBackfill,
            ]],
            'store throws for one chunk' => [[
                'chunks'     => [[1, 'file', 42, 'a', 0], [2, 'file', 42, 'b', 1]],
                'sources'    => ['file_42' => 2],
                'results'    => [$ok(0.1), $ok(0.2)],
                'storeThrow' => [2],
                'cursor'     => '0',
                'backfill'   => $noBackfill,
            ]],
            'update throws for one chunk' => [[
                'chunks'      => [[1, 'file', 42, 'a', 0], [2, 'file', 42, 'b', 1]],
                'sources'     => ['file_42' => 2],
                'results'     => [$ok(0.1), $ok(0.2)],
                'updateThrow' => [1],
                'cursor'      => '0',
                'backfill'    => $noBackfill,
            ]],
            'source without chunks counts as one' => [[
                'chunks'   => [[1, 'file', 9, 'a', 5]],
                'sources'  => ['file_9' => 0],
                'results'  => [$ok(0.1)],
                'cursor'   => '0',
                'backfill' => $noBackfill,
            ]],
            'long and null text' => [[
                'chunks'   => [[1, 'file', 42, str_repeat('x', 520), 0], [2, 'file', 42, null, 1]],
                'sources'  => ['file_42' => 2],
                'results'  => [$ok(0.1), $ok(0.2)],
                'cursor'   => '0',
                'backfill' => $noBackfill,
            ]],
            'no chunks, backfill progresses' => [[
                'chunks'   => [],
                'sources'  => [],
                'results'  => [],
                'cursor'   => '0',
                'backfill' => ['converted' => 40, 'failed' => 2, 'last_id' => 140, 'remaining' => 60],
            ]],
            'backfill makes no progress' => [[
                'chunks'   => [],
                'sources'  => [],
                'results'  => [],
                'cursor'   => '200',
                'backfill' => ['converted' => 0, 'failed' => 5, 'last_id' => 200, 'remaining' => 9],
            ]],
            'backfill sweep complete' => [[
                'chunks'   => [],
                'sources'  => [],
                'results'  => [],
                'cursor'   => '500',
                'backfill' => ['converted' => 3, 'failed' => 0, 'last_id' => 512, 'remaining' => 0],
            ]],
            'provider throws, backfill throws' => [[
                'chunks'        => [[1, 'file', 42, 'a', 0]],
                'sources'       => ['file_42' => 1],
                'results'       => [],
                'providerThrow' => true,
                'cursor'        => '0',
                'backfillThrow' => true,
                'backfill'      => $noBackfill,
            ]],
        ];
    }//end cases()

    #[DataProvider('cases')]
    public function testRunMatchesGolden(array $case): void
    {
        $chunks = [];
        foreach ($case['chunks'] as [$id, $type, $sourceId, $text, $index]) {
            $chunk = new Chunk();
            $chunk->setId($id);
            $chunk->setSourceType($type);
            $chunk->setSourceId($sourceId);
            if ($text !== null) {
                $chunk->setTextContent($text);
            }

            $chunk->setChunkIndex($index);
            $chunk->setStartOffset($id * 10);
            $chunk->setEndOffset($id * 10 + 9);
            $chunk->setVectorized(false);
            $chunks[] = $chunk;
        }

        $this->wire($case, $chunks);

        $job = new ChunkVectorizationJob($this->createMock(ITimeFactory::class), $this->containerMock());
        $run = new ReflectionMethod($job, 'run');
        $run->invoke($job, []);

        $flags = [];
        foreach ($chunks as $chunk) {
            $flags[$chunk->getId()] = $chunk->getVectorized();
        }

        $actual = ['trace' => $this->trace, 'vectorized' => $flags];

        $name = (string) $this->dataName();
        $all = is_file(self::FIXTURE) === true ? json_decode((string) file_get_contents(self::FIXTURE), true) : [];
        if (getenv('UPDATE_GOLDEN') === '1') {
            $all[$name] = $actual;
            ksort($all);
            file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertArrayHasKey($name, $all);
        $this->assertSame(json_encode($all[$name]), json_encode($actual));
    }//end testRunMatchesGolden()

    /**
     * @param array<string, mixed> $case
     * @param list<Chunk>          $chunks
     */
    private function wire(array $case, array $chunks): void
    {
        $mapper = $this->createMock(ChunkMapper::class);
        $mapper->method('findUnvectorized')->willReturnCallback(
            function (...$args) use ($chunks): array {
                $this->record('findUnvectorized', $args);
                return $chunks;
            }
        );
        $mapper->method('findBySource')->willReturnCallback(
            function (string $type, int $id) use ($case): array {
                $this->record('findBySource', [$type, $id]);
                return array_fill(0, $case['sources'][$type.'_'.$id], new Chunk());
            }
        );
        $mapper->method('update')->willReturnCallback(
            function (Chunk $chunk) use ($case): Chunk {
                $this->record('update', [$chunk->getId(), $chunk->getVectorized(), $chunk->getUpdatedAt() !== null]);
                if (in_array($chunk->getId(), $case['updateThrow'] ?? [], true) === true) {
                    throw new \Exception('update failed '.$chunk->getId());
                }

                return $chunk;
            }
        );

        $embeddings = $this->createMock(VectorEmbeddings::class);
        $embeddings->method('generateBatchEmbeddings')->willReturnCallback(
            function (array $texts) use ($case): array {
                $this->record('generateBatchEmbeddings', [$texts]);
                if (($case['providerThrow'] ?? false) === true) {
                    throw new \RuntimeException('no provider');
                }

                return $case['results'];
            }
        );

        $storage = $this->createMock(VectorStorageHandler::class);
        $storeCalls = 0;
        $storage->method('storeVector')->willReturnCallback(
            function (...$args) use ($case, &$storeCalls): int {
                $storeCalls++;
                $this->record('storeVector', $args);
                if (in_array($storeCalls, $case['storeThrow'] ?? [], true) === true) {
                    throw new \Exception('store failed '.$storeCalls);
                }

                return $storeCalls;
            }
        );
        $storage->method('backfillEmbeddingVectors')->willReturnCallback(
            function (...$args) use ($case): array {
                $this->record('backfillEmbeddingVectors', $args);
                if (($case['backfillThrow'] ?? false) === true) {
                    throw new \RuntimeException('backfill broke');
                }

                return $case['backfill'];
            }
        );

        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            function (...$args) use ($case): string {
                $this->record('getValueString', $args);
                return $case['cursor'];
            }
        );
        $config->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                if (str_ends_with($key, 'last_run') === true) {
                    $value = preg_match('/^\d{4}-\d\d-\d\dT/', $value) === 1 ? '<iso-date>' : $value;
                }

                $this->record('setValueString', [$app, $key, $value]);
                return true;
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    unset($context['line']);
                    $context['file'] = str_ends_with((string) ($context['file'] ?? ''), '.php') === true ? '<php-file>' : null;
                    if (isset($context['execution_time_seconds']) === true) {
                        $context['execution_time_seconds'] = is_float($context['execution_time_seconds']) ? '<float>' : $context['execution_time_seconds'];
                    }

                    $this->record('log.'.$level, [$message, $context]);
                }
            );
        }

        $this->registerService(LoggerInterface::class, fn () => $logger);
        $this->registerService(IAppConfig::class, fn () => $config);
        $this->registerService(ChunkMapper::class, fn () => $mapper);
        $this->registerService(VectorEmbeddings::class, fn () => $embeddings);
        $this->registerService(VectorStorageHandler::class, fn () => $storage);
    }//end wire()

    /**
     * @param array<int|string, mixed> $args
     */
    private function record(string $call, array $args): void
    {
        $this->trace[] = [$call => $args];
    }//end record()
}//end class
