<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\ObjectsProvider;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IFilter;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden test for ObjectsProvider::search().
 *
 * Each case runs one search; the recording holds every query handed to the
 * object service, every log call (level, message, context without file, line
 * and exception), and the serialised search result.
 * Compared with fixtures/objects-provider-search-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\ObjectsProvider
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Search\SearchScopes
 * @uses   \OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter
 * @uses   \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter
 */
class ObjectsProviderSearchGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-provider-search-golden.json';

	/**
	 * Case name => [filters, limit, cursor, world]. World keys: searchable (ids),
	 * nonSearchable (ids or 'throw'), rows (rows per schema chunk call), failChunk
	 * (1-based chunk that throws), searchableThrows.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int, 2: mixed, 3: array<string, mixed>}>
	 */
	public static function cases(): array {
		$base = ['searchable' => [1, 2, 3], 'nonSearchable' => [], 'rows' => 2];

		return [
			'no filters' => [[], 25, null, $base],
			'term register digit dates' => [
				['term' => 'zaak', 'register' => ' 7 ', 'since' => '2026-01-01', 'until' => '2026-02-01'],
				25,
				null,
				$base,
			],
			'until only' => [['until' => '2026-02-01'], 25, null, $base],
			'since only' => [['since' => '2026-01-01'], 25, null, $base],
			'register slug schema digit' => [['register' => 'zaken', 'schema' => '3'], 25, null, $base],
			'schema slug resolves' => [['schema' => 'known-slug'], 25, null, $base],
			'schema slug unknown' => [['schema' => 'nope'], 25, null, $base],
			'schema zero' => [['schema' => '0'], 25, null, $base],
			'schema opted out' => [['schema' => '5'], 25, null, ['nonSearchable' => [5]] + $base],
			'schema slug opted out' => [['schema' => 'known-slug'], 25, null, ['nonSearchable' => [2]] + $base],
			'opt-out lookup throws' => [['schema' => '5'], 25, null, ['nonSearchable' => 'throw'] + $base],
			'nothing searchable' => [['term' => 'x'], 25, null, ['searchable' => []] + $base],
			'searchable lookup throws' => [['term' => 'x'], 25, null, ['searchableThrows' => true] + $base],
			'scope narrows to schema' => [['scopes' => 'schema:beta'], 25, null, $base],
			'scope narrows by register' => [['scopes' => 'register:zaken'], 25, null, $base],
			'scope narrows to nothing' => [['scopes' => 'schema:missing'], 25, null, $base],
			'scope unparsed' => [['scopes' => 'colour:blue'], 25, null, $base],
			'limit and cursor' => [['term' => 'a'], 10, '5', $base],
			'limit zero' => [[], 0, '-3', $base],
			'limit above page' => [[], 100, 'abc', $base],
			'full page paginates' => [[], 3, '2', ['rows' => 3] + $base],
			'many chunks' => [['term' => 'b'], 4, '1', ['searchable' => range(1, 120), 'rows' => 2] + $base],
			'many chunks one fails' => [[], 5, null, ['searchable' => range(1, 120), 'failChunk' => 2] + $base],
			'single chunk fails' => [['schema' => '2'], 25, null, ['failChunk' => 1] + $base],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $filters Filter name => value
	 * @param int                  $limit   Query limit
	 * @param mixed                $cursor  Query cursor
	 * @param array<string, mixed> $world   Mapper and service behaviour
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testSearchMatchesGolden(array $filters, int $limit, mixed $cursor, array $world): void {
		$trace = [];
		$logger = new class($trace) extends AbstractLogger {
			/** @param list<mixed> $trace */
			public function __construct(private array &$trace) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				unset($context['file'], $context['line'], $context['exception']);
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string => $route . '?' . http_build_query($params)
		);
		$deepLinks = $this->createMock(DeepLinkRegistryService::class);

		$schemaMapper = $this->schemaMapper($world);
		$registerMapper = $this->registerMapper();

		$objectService = $this->createMock(ObjectService::class);
		$call = 0;
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use (&$trace, &$call, $world): array {
				$call++;
				$trace[] = ['searchObjectsPaginated', $query];
				if (($world['failChunk'] ?? 0) === $call) {
					throw new \RuntimeException('chunk table broken');
				}

				$schemas = (array) ($query['@self']['schema'] ?? [0]);
				$rows = [];
				foreach (array_slice($schemas, 0, 2) as $schema) {
					for ($i = 0; $i < $world['rows']; $i++) {
						$rows[] = [
							'@self' => [
								'id' => sprintf('uuid-%s-%d', (string) $schema, $i),
								'relevance' => (($call * 7 + $i * 3) % 5),
								'register' => 1,
								'schema' => $schema,
							],
							'title' => "row $call/$i",
						];
					}
				}

				return ['results' => $rows, 'total' => (count($rows) + 10)];
			}
		);

		$preview = new ObjectPreviewFormatter($urlGenerator, $l10n, $objectService, $deepLinks, $schemaMapper, $registerMapper, $logger);
		$provider = new ObjectsProvider(
			$l10n,
			$objectService,
			$logger,
			$schemaMapper,
			new ObjectSearchResultFormatter($urlGenerator, $deepLinks, $preview),
			$registerMapper
		);

		try {
			$result = $provider->search($this->createMock(IUser::class), $this->query($filters, $limit, $cursor));
			$trace[] = ['result', $result->jsonSerialize()];
		} catch (\Throwable $e) {
			$trace[] = ['thrown', get_class($e), $e->getMessage()];
		}

		$actual = json_decode(json_encode($trace), true);

		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testSearchMatchesGolden()

	/**
	 * @param array<string, mixed> $world Mapper behaviour
	 *
	 * @return SchemaMapper
	 */
	private function schemaMapper(array $world): SchemaMapper {
		$mapper = $this->createMock(SchemaMapper::class);
		if (($world['searchableThrows'] ?? false) === true) {
			$mapper->method('findSearchableIds')->willThrowException(new \RuntimeException('searchable lookup down'));
		} else {
			$mapper->method('findSearchableIds')->willReturn($world['searchable']);
		}

		if ($world['nonSearchable'] === 'throw') {
			$mapper->method('findNonSearchableIds')->willThrowException(new \RuntimeException('opt-out lookup down'));
		} else {
			$mapper->method('findNonSearchableIds')->willReturn($world['nonSearchable']);
		}

		$mapper->method('find')->willReturnCallback(
			function ($reference) {
				if ($reference !== 'known-slug') {
					throw new \RuntimeException('not found');
				}

				return $this->entity(Schema::class, ['id' => 2]);
			}
		);

		$schemas = [];
		foreach ([[1, 'alpha'], [2, 'beta'], [3, 'gamma'], [9, 'hidden']] as [$id, $slug]) {
			$schemas[] = $this->entity(Schema::class, ['id' => $id, 'slug' => $slug]);
		}

		$mapper->method('findAll')->willReturn($schemas);
		return $mapper;
	}//end schemaMapper()

	/**
	 * @return RegisterMapper
	 */
	private function registerMapper(): RegisterMapper {
		$register = $this->entity(Register::class, ['schemas' => [1, 3], 'slug' => 'zaken']);

		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('findAll')->willReturn([$register]);
		$mapper->method('find')->willThrowException(new \RuntimeException('not found'));
		return $mapper;
	}//end registerMapper()

	/**
	 * @param class-string         $class  Entity class
	 * @param array<string, mixed> $fields Field => value, set through the entity setters
	 *
	 * @return object
	 */
	private function entity(string $class, array $fields): object {
		$entity = new $class();
		foreach ($fields as $field => $value) {
			$entity->{'set' . ucfirst($field)}($value);
		}

		return $entity;
	}//end entity()

	/**
	 * @param array<string, mixed> $filters Filter name => value
	 * @param int                  $limit   Query limit
	 * @param mixed                $cursor  Query cursor
	 *
	 * @return ISearchQuery
	 */
	private function query(array $filters, int $limit, mixed $cursor): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getFilter')->willReturnCallback(
			function (string $name) use ($filters): ?IFilter {
				if (array_key_exists($name, $filters) === false) {
					return null;
				}

				$filter = $this->createMock(IFilter::class);
				$filter->method('get')->willReturn($filters[$name]);
				return $filter;
			}
		);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);
		return $query;
	}//end query()
}//end class
