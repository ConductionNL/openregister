<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\BlobMigrationJob;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for BlobMigrationJob::run().
 *
 * Each case runs the job once over a scripted blob table; the recording holds
 * every container lookup and every call to the logger, the app config, the
 * register and schema mappers, the magic mapper and the database. Log file,
 * line and duration and the last-run time are masked. Compared with
 * fixtures/blob-migration-job-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\BlobMigrationJob
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\BackgroundJob\BlobMigrationRun
 * @uses   \OCA\OpenRegister\BackgroundJob\BlobGroup
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class BlobMigrationJobGoldenTest extends TestCase
{

    private const FIXTURE = __DIR__.'/fixtures/blob-migration-job-golden.json';

    /**
     * @var list<array<string, mixed>>
     */
    private array $trace = [];

    /**
     * Answers of executeQuery, consumed in order.
     *
     * @var list<IResult>
     */
    private array $results = [];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $rows = [
            ['id' => 1, 'uuid' => 'a', 'register' => 1, 'schema' => 2, 'object' => '{"name":"A","n":1}', 'version' => '1.0', 'locked' => null],
            ['id' => 2, 'uuid' => 'b', 'register' => 1, 'schema' => 2, 'object' => 'not json', 'owner' => 'ann'],
            ['id' => 3, 'uuid' => 'c', 'register' => null, 'schema' => 2, 'object' => '{}'],
            ['uuid' => 'd', 'register' => 9, 'schema' => 9, 'object' => '[]'],
            ['id' => 5, 'register' => 1, 'schema' => 3, 'object' => ['raw'], 'created' => '2026-01-01', 'relations' => '{}'],
            ['id' => 6, 'uuid' => 'f', 'register' => 4, 'schema' => 0],
        ];

        return [
            'already complete'    => [['complete' => 'true']],
            'no blob table'       => [['table' => false]],
            'blob table empty'    => [['rows' => []]],
            'groups and failures' => [['rows' => $rows, 'processed' => '7', 'remaining' => '12']],
            'save throws'         => [['rows' => [$rows[0]], 'saveThrows' => true]],
            'table check throws'  => [['table' => 'throw']],
            'postgres, empty'     => [['rows' => [], 'platform' => 'postgres']],
            'rows without id'     => [['rows' => [['uuid' => 'g', 'register' => 7, 'schema' => 8, 'relations' => '[]', 'uri' => 'u']]]],
        ];
    }//end cases()

    #[DataProvider('cases')]
    public function testRunMatchesGolden(array $case): void
    {
        $job = new BlobMigrationJob($this->createMock(ITimeFactory::class), $this->container($case));
        $run = new ReflectionMethod($job, 'run');
        $run->invoke($job, []);

        $name = (string) $this->dataName();
        $all = is_file(self::FIXTURE) === true ? json_decode((string) file_get_contents(self::FIXTURE), true) : [];
        if (getenv('UPDATE_GOLDEN') === '1') {
            $all[$name] = $this->trace;
            ksort($all);
            file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertArrayHasKey($name, $all);
        $this->assertSame($all[$name], $this->trace);
    }//end testRunMatchesGolden()

    private function record(string $call, array $args): void
    {
        $this->trace[] = [$call => $args];
    }//end record()

    private function container(array $case): ContainerInterface
    {
        $services = [
            LoggerInterface::class         => $this->logger(),
            IDBConnection::class           => $this->db($case),
            IAppConfig::class              => $this->appConfig($case),
            RegisterMapperInterface::class => $this->registers(),
            SchemaMapperInterface::class   => $this->schemas(),
            MagicMapperInterface::class    => $this->magic($case),
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($services): object {
                $this->record('get', [substr($id, (int) strrpos($id, '\\') + 1)]);
                return $services[$id];
            }
        );
        return $container;
    }//end container()

    private function logger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['error', 'warning', 'info', 'debug'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context=[]) use ($level): void {
                    foreach (['file', 'line', 'execution_time_seconds', 'trace'] as $volatile) {
                        if (array_key_exists($volatile, $context) === true) {
                            $context[$volatile] = 'X';
                        }
                    }

                    $this->record($level, [$message, $context]);
                }
            );
        }

        return $logger;
    }//end logger()

    private function appConfig(array $case): IAppConfig
    {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') use ($case): string {
                $this->record('getValueString', [$app, $key, $default]);
                $values = ['blob_migration_complete' => ($case['complete'] ?? 'false'), 'blob_migration_processed' => ($case['processed'] ?? '0')];
                return ($values[$key] ?? $default);
            }
        );
        $config->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->record('setValueString', [$app, $key, $key === 'blob_migration_last_run' ? 'T' : $value]);
                return true;
            }
        );
        return $config;
    }//end appConfig()

    private function registers(): RegisterMapperInterface
    {
        $mapper = $this->createMock(RegisterMapperInterface::class);
        $mapper->method('find')->willReturnCallback(
            function (...$args): Register {
                $this->record('registerFind', $args);
                if ($args[0] === 9) {
                    throw new RuntimeException('register 9 gone');
                }

                $register = new Register();
                $register->setId($args[0]);
                return $register;
            }
        );
        return $mapper;
    }//end registers()

    private function schemas(): SchemaMapperInterface
    {
        $mapper = $this->createMock(SchemaMapperInterface::class);
        $mapper->method('find')->willReturnCallback(
            function (...$args): Schema {
                $this->record('schemaFind', $args);
                if ($args[0] === 3) {
                    throw new RuntimeException('schema 3 gone');
                }

                $schema = new Schema();
                $schema->setId($args[0]);
                return $schema;
            }
        );
        return $mapper;
    }//end schemas()

    private function magic(array $case): MagicMapperInterface
    {
        $mapper = $this->createMock(MagicMapperInterface::class);
        $mapper->method('ensureTableForRegisterSchema')->willReturnCallback(
            function (Register $register, Schema $schema): bool {
                $this->record('ensureTable', [$register->getId(), $schema->getId()]);
                return true;
            }
        );
        $mapper->method('saveObjectsToRegisterSchemaTable')->willReturnCallback(
            function (RegisterSchema $table, array $objects) use ($case): array {
                [$register, $schema] = [$table->register, $table->schema];
                $this->record('saveObjects', [$objects, $register->getId(), $schema->getId()]);
                if (($case['saveThrows'] ?? false) === true) {
                    throw new RuntimeException('save failed');
                }

                return array_slice(array_column($objects, 'uuid'), 0, max(1, count($objects) - 1));
            }
        );
        return $mapper;
    }//end magic()

    private function db(array $case): IDBConnection
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabasePlatform')->willReturnCallback(
            function () use ($case): object {
                $this->record('getDatabasePlatform', []);
                if (($case['table'] ?? true) === 'throw') {
                    throw new RuntimeException('platform down');
                }

                if (($case['platform'] ?? null) === 'postgres') {
                    return $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQL100Platform::class);
                }

                return new \stdClass();
            }
        );
        $statement = $this->createMock(IPreparedStatement::class);
        $statement->method('execute')->willReturn($this->createMock(IResult::class));
        $statement->method('fetch')->willReturn(($case['table'] ?? true) === true ? [1] : false);
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($statement): IPreparedStatement {
                $this->record('prepare', [$sql]);
                return $statement;
            }
        );
        $this->results = [$this->dbResult(['fetchAll' => ($case['rows'] ?? [])]), $this->dbResult(['fetch' => ['count' => ($case['remaining'] ?? '0')]])];
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
        return $db;
    }//end db()

    private function dbResult(array $answers): IResult
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn(($answers['fetchAll'] ?? []));
        $result->method('fetch')->willReturn(($answers['fetch'] ?? false));
        $result->method('closeCursor')->willReturnCallback(
            function (): bool {
                $this->record('closeCursor', []);
                return true;
            }
        );
        return $result;
    }//end dbResult()

    private function queryBuilder(): IQueryBuilder
    {
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'setMaxResults', 'delete', 'where'] as $method) {
            $qb->method($method)->willReturnCallback(
                function (...$args) use ($method, $qb): IQueryBuilder {
                    $this->record($method, array_map(static fn ($arg) => is_object($arg) === true ? 'function' : $arg, $args));
                    return $qb;
                }
            );
        }

        $qb->method('createFunction')->willReturnCallback(
            function (string $sql): IQueryFunction {
                $this->record('createFunction', [$sql]);
                return $this->createMock(IQueryFunction::class);
            }
        );
        $qb->method('createNamedParameter')->willReturnCallback(
            function (...$args): string {
                $this->record('createNamedParameter', $args);
                return ':ids';
            }
        );
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('in')->willReturnCallback(
            function (...$args): string {
                $this->record('in', $args);
                return 'id IN (:ids)';
            }
        );
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturnCallback(
            function (): IResult {
                $this->record('executeQuery', []);
                return array_shift($this->results);
            }
        );
        $qb->method('executeStatement')->willReturnCallback(
            function (): int {
                $this->record('executeStatement', []);
                return 1;
            }
        );
        return $qb;
    }//end queryBuilder()
}//end class
