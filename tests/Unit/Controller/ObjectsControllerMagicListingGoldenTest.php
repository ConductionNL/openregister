<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Golden test for how ObjectsController pages and annotates a magic-table listing and refuses a search term.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-magic-listing-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Exception\SearchTermSyntaxException
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermParser
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermNode
 */
class ObjectsControllerMagicListingGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-magic-listing-golden.json';

	/** @var list<mixed> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int}>
	 */
	public static function pagingCases(): array {
		return [
			'defaults' => [[], [], 45],
			'limit' => [['_limit' => '10'], [], 45],
			'exact pages' => [['_limit' => 5], [], 45],
			'offset' => [['_limit' => 10, '_offset' => 25], [], 45],
			'page' => [['_limit' => 10, '_page' => '3'], [], 45],
			'page and offset' => [['_limit' => 10, '_page' => 3, '_offset' => 5], [], 45],
			'zero limit' => [['_limit' => 0], [], 45],
			'negative limit' => [['_limit' => -1, '_page' => 2], [], 45],
			'zero limit offset' => [['_limit' => 0, '_offset' => 7], [], 45],
			'nothing found' => [['_limit' => 10], [], 0],
			'no total' => [['_limit' => 10, '_offset' => 25], ['_count' => 'false'], 45],
			'no total flag' => [['_limit' => 10], ['_noTotal' => true], 45],
			'no total zero limit' => [['_limit' => 0], ['_noTotal' => true], 45],
			'no total page' => [['_limit' => 10, '_page' => 4], ['_count' => false], 45],
		];
	}

	/**
	 * @return array<string, array{0: list<string>}>
	 */
	public static function hintCases(): array {
		return [
			'none' => [[]],
			'property only' => [['colour']],
			'one control' => [['limit']],
			'all controls' => [['limit', 'offset', 'page', 'order', 'sort', 'search', 'extend', 'fields', 'unset']],
			'mixed' => [['colour', 'page', 'size', 'sort']],
			'underscored stays out' => [['_limit']],
		];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function searchCases(): array {
		return [
			'none' => [[]],
			'not a string' => [['_search' => ['a']]],
			'blank' => [['_search' => '   ']],
			'plain' => [['_search' => 'plain words']],
			'balanced' => [['_search' => '(a AND b)']],
			'open bracket' => [['_search' => '(a AND b']],
			'padded open bracket' => [['_search' => '  (a  ']],
			'dangling operator' => [['_search' => 'a AND']],
			'open quote' => [['_search' => '"a AND b']],
		];
	}

	#[DataProvider('pagingCases')]
	public function testPagingMatchesTheRecording(array $query, array $params, int $total): void {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(function (array $query) use ($total): int {
			$this->calls[] = ['count' => $query];
			return $total;
		});
		$listing = ['query' => $query, 'params' => $params, 'magicMapper' => $mapper, 'resolved' => ['registerEntity' => $this->createMock(Register::class), 'schemaEntity' => $this->createMock(Schema::class)]];
		$this->compare(key: 'paging ' . $this->dataName(), actual: $this->call('paginateMagicResults', [$listing]));
	}

	#[DataProvider('hintCases')]
	public function testIgnoredFilterHintMatchesTheRecording(array $ignored): void {
		$this->compare(key: 'hint ' . $this->dataName(), actual: $this->call('addIgnoredFilterHint', [['@self' => ['kept' => 1]], $ignored]));
	}

	public function testResponseMatchesTheRecording(): void {
		$page = ['results' => ['r'], 'total' => 2, 'pages' => 3, 'page' => 4, 'limit' => 5, 'extra' => 'no'];
		$listing = ['query' => ['q' => 1], 'register' => 'r', 'schema' => 's', 'rbac' => true, 'multi' => false, 'deleted' => null];
		$this->compare(key: 'response plain', actual: $this->call('buildMagicResponse', [$page, $listing]));

		$listing['query']['_recentLens'] = ['answered' => false];
		$this->compare(key: 'response lens', actual: $this->call('buildMagicResponse', [$page, $listing]));

		$listing['query']['_recentLens'] = 'text';
		$this->compare(key: 'response lens not an array', actual: $this->call('buildMagicResponse', [$page, $listing]));
	}

	public function testFacetsMatchTheRecording(): void {
		$answers = ['none asked' => null, 'empty' => [], 'facets' => ['f' => 1], 'fails' => new RuntimeException('no facets')];
		foreach ($answers as $name => $answer) {
			$mapper = $this->createMock(MagicMapperInterface::class);
			$mapper->method('getSimpleFacetsFromRegisterSchemaTable')->willReturnCallback(function (RegisterSchema $table, array $query) use ($answer): array {
				$this->calls[] = ['facets' => $query];
				if ($answer instanceof RuntimeException) {
					throw $answer;
				}

				return $answer;
			});
			$query = ['_facets' => ($answer === null ? [] : ['a'])];
			$listing = ['query' => $query, 'magicMapper' => $mapper, 'resolved' => ['registerEntity' => $this->createMock(Register::class), 'schemaEntity' => $this->createMock(Schema::class)]];
			$this->compare(key: 'facets ' . $name, actual: $this->call('addMagicFacets', [['@self' => [], 'results' => []], $listing]));
		}
	}

	#[DataProvider('searchCases')]
	public function testSearchRefusalMatchesTheRecording(array $params): void {
		$answer = $this->call('refuseMalformedSearchTerm', [$params]);
		if ($answer instanceof JSONResponse) {
			$answer = ['status' => $answer->getStatus(), 'data' => $answer->getData()];
		}

		$this->compare(key: 'search ' . $this->dataName(), actual: $answer);
	}

	/**
	 * @param list<mixed> $args
	 */
	private function call(string $method, array $args): mixed {
		$this->calls = [];
		$reflection = new ReflectionClass(ObjectsController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no organisation'));
		$reflection->getProperty('container')->setValue($controller, $container);
		$answer = $reflection->getMethod($method)->invokeArgs($controller, $args);
		if ($this->calls === []) {
			return $answer;
		}

		return ['answer' => $answer, 'calls' => $this->calls];
	}

	private function compare(string $key, mixed $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= [];
			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
